<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\FiscalYear;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Money\CurrencyService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing allowed to write to the ledger.
 *
 * ── Why posting is a service and not a model method ──────────────────────────
 *
 * Every rule that makes a ledger trustworthy is a rule about a whole entry, not
 * about one row: the lines must balance, the accounts must be postable and
 * belong to this book, the period must be open, the reference must be unique.
 * A model that lets you save a line on its own cannot enforce any of them, and
 * the checks end up copied into every caller — invoicing, payroll, stock, the
 * courier settlement — where one of them will eventually be missed.
 *
 * So journal lines have no public write path. Everything goes through post(),
 * which either writes a complete, balanced, in-period entry or writes nothing.
 *
 * ── On the balance check ─────────────────────────────────────────────────────
 *
 * It refuses rather than corrects. The temptation is to shove the difference
 * into a rounding account and carry on, and some systems do — but an entry that
 * does not balance means the caller computed something wrong, and silently
 * absorbing it hides the bug while producing books that foot correctly and say
 * something false. Better to fail loudly at the moment the mistake is made,
 * while there is still a stack trace pointing at it.
 */
final class Ledger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrencyService $currency,
    ) {}

    /**
     * Write a balanced entry to the books.
     *
     * $lines is a list of ['account' => code|LedgerAccount, 'debit' => Money]
     * or ['account' => …, 'credit' => Money], each optionally with
     * 'description'.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws RuntimeException if the entry does not balance, names an account
     *                          it may not post to, or falls in a closed period
     */
    public function post(
        string $date,
        string $description,
        array $lines,
        array $options = [],
    ): JournalEntry {
        $business = $this->requireBusiness();
        $baseCurrency = strtoupper($business->base_currency);

        if (count($lines) < 2) {
            // One line cannot balance against anything. This is nearly always a
            // caller that meant to write a pair and built the second wrong.
            throw new RuntimeException('An entry needs at least two lines — one thing given, one thing received.');
        }

        $year = FiscalYear::covering($date);

        if ($year !== null && $year->is_closed) {
            throw new RuntimeException(
                "{$year->name} is closed, so nothing more can be posted into it. Date the entry in an open year, or reopen that one."
            );
        }

        $prepared = $this->prepare($lines, $date, $baseCurrency);

        $this->assertBalanced($prepared, $baseCurrency);

        return DB::transaction(function () use ($business, $baseCurrency, $date, $description, $prepared, $options, $year) {
            $entry = JournalEntry::create([
                'business_id' => $business->id,
                'fiscal_year_id' => $year?->id,
                'reference' => $options['reference'] ?? $this->nextReference($business, $date),
                'entry_date' => $date,
                'description' => $description,
                'memo' => $options['memo'] ?? null,
                'status' => ($options['draft'] ?? false) ? JournalEntry::DRAFT : JournalEntry::POSTED,
                'source' => $options['source'] ?? 'manual',
                'source_platform' => $options['source_platform'] ?? null,
                'source_ref' => $options['source_ref'] ?? null,
                'subject_type' => $options['subject_type'] ?? null,
                'subject_id' => $options['subject_id'] ?? null,
                'currency' => $prepared[0]['currency'],
                'base_currency' => $baseCurrency,
                'posted_at' => ($options['draft'] ?? false) ? null : now(),
                'posted_by' => ($options['draft'] ?? false) ? null : ($options['actor_id'] ?? auth()->id()),
                'created_by' => $options['actor_id'] ?? auth()->id(),
            ]);

            foreach ($prepared as $index => $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $line['account_id'],
                    'business_id' => $business->id,
                    'entry_date' => $date,
                    'line_no' => $index + 1,
                    'description' => $line['description'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'currency' => $line['currency'],
                    'base_debit_minor' => $line['base_debit_minor'],
                    'base_credit_minor' => $line['base_credit_minor'],
                    'base_currency' => $baseCurrency,
                    'exchange_rate' => $line['rate'],
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Undo a posted entry by posting its mirror image.
     *
     * Not an edit and not a delete: a second entry, dated when the correction
     * was made rather than when the mistake was, with every debit and credit
     * swapped. Both stay on the record and each points at the other, so the
     * history reads as "this happened, then it was undone" — which is what
     * actually occurred.
     */
    public function reverse(JournalEntry $entry, ?string $date = null, ?string $reason = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new RuntimeException('Only a posted entry can be reversed. A draft can simply be discarded.');
        }

        if ($entry->reversed_by_entry_id !== null) {
            throw new RuntimeException("{$entry->reference} has already been reversed.");
        }

        $entry->loadMissing('lines');

        $mirrored = $entry->lines->map(fn (JournalLine $line) => [
            'account_id' => $line->ledger_account_id,
            'description' => $line->description,
            // Swapped: what was given is now received.
            'debit' => $line->isDebit() ? null : $line->amount(),
            'credit' => $line->isDebit() ? $line->amount() : null,
        ])->all();

        return DB::transaction(function () use ($entry, $mirrored, $date, $reason) {
            $reversal = $this->post(
                $date ?? now()->toDateString(),
                $reason ?? "Reversal of {$entry->reference}",
                $mirrored,
                [
                    'source' => 'system',
                    'subject_type' => $entry->subject_type,
                    'subject_id' => $entry->subject_id,
                    'reference' => null,
                ],
            );

            $reversal->forceFill(['reverses_entry_id' => $entry->id])->save();
            $entry->forceFill([
                'reversed_by_entry_id' => $reversal->id,
                'status' => JournalEntry::REVERSED,
            ])->save();

            return $reversal;
        });
    }

    /**
     * Normalise the caller's lines into rows, resolving accounts and rates.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function prepare(array $lines, string $date, string $baseCurrency): array
    {
        $prepared = [];

        foreach ($lines as $index => $line) {
            $account = $this->resolveAccount($line['account'] ?? $line['account_id'] ?? null, $index);

            $debit = $line['debit'] ?? null;
            $credit = $line['credit'] ?? null;

            if (($debit === null) === ($credit === null)) {
                // Both or neither. A line is one side of a posting; anything
                // else is a caller that has not decided what it means.
                throw new RuntimeException(
                    "Line {$index} must be either a debit or a credit, not both and not neither."
                );
            }

            /** @var Money $amount */
            $amount = $debit ?? $credit;

            if (! $amount instanceof Money) {
                throw new RuntimeException("Line {$index} needs a Money amount, not a bare number — the currency matters.");
            }

            if ($amount->isNegative()) {
                // A negative debit is a credit. Saying so directly keeps the
                // trial balance readable and stops two ways of writing the
                // same posting from existing.
                throw new RuntimeException(
                    "Line {$index} is negative. Put it on the other side instead — that is what the other side is for."
                );
            }

            if ($amount->isZero()) {
                throw new RuntimeException("Line {$index} moves nothing. Leave it out.");
            }

            $base = $this->currency->convert($amount, $baseCurrency);

            if ($base === null) {
                throw new RuntimeException(
                    "No exchange rate for {$amount->currency} against {$baseCurrency}, so this cannot be posted. Add a rate first."
                );
            }

            $prepared[] = [
                'account_id' => $account->id,
                'description' => $line['description'] ?? null,
                'currency' => $amount->currency,
                'debit_minor' => $debit !== null ? $amount->minor : 0,
                'credit_minor' => $credit !== null ? $amount->minor : 0,
                'base_debit_minor' => $debit !== null ? $base->minor : 0,
                'base_credit_minor' => $credit !== null ? $base->minor : 0,
                'rate' => $amount->minor === 0 ? 1 : $base->minor / $amount->minor,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $prepared
     */
    private function assertBalanced(array $prepared, string $baseCurrency): void
    {
        $currencies = array_unique(array_column($prepared, 'currency'));

        if (count($currencies) > 1) {
            // Multi-currency entries are a real thing, but they balance only in
            // the base currency, and letting them through here would mean the
            // first check below could never be applied. Until there is a caller
            // that genuinely needs one, refusing is the honest answer.
            throw new RuntimeException(
                'All lines of one entry must share a currency. Convert before posting, or post separate entries.'
            );
        }

        $debit = array_sum(array_column($prepared, 'debit_minor'));
        $credit = array_sum(array_column($prepared, 'credit_minor'));

        if ($debit !== $credit) {
            throw new RuntimeException(sprintf(
                'This entry does not balance: debits %s, credits %s, a difference of %s.',
                (new Money($debit, $currencies[0]))->toDecimalString(),
                (new Money($credit, $currencies[0]))->toDecimalString(),
                (new Money(abs($debit - $credit), $currencies[0]))->toDecimalString(),
            ));
        }

        $baseDebit = array_sum(array_column($prepared, 'base_debit_minor'));
        $baseCredit = array_sum(array_column($prepared, 'base_credit_minor'));

        if ($baseDebit !== $baseCredit) {
            // Each line was converted on its own, and rounding can leave the
            // two sides a unit apart even when the originals agree exactly.
            // Nudging the largest line absorbs it where it is least visible,
            // and is the conventional treatment.
            $this->absorbRounding($prepared, $baseDebit - $baseCredit);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $prepared
     */
    private function absorbRounding(array &$prepared, int $difference): void
    {
        if (abs($difference) > count($prepared)) {
            // More than one minor unit per line is not rounding, it is a rate
            // problem or a bug, and quietly swallowing it would hide both.
            throw new RuntimeException(sprintf(
                'Converted amounts are out by %d minor units, which is too much to be rounding. Check the exchange rate.',
                abs($difference),
            ));
        }

        $side = $difference > 0 ? 'base_debit_minor' : 'base_credit_minor';
        $largest = 0;

        foreach ($prepared as $index => $line) {
            if ($line[$side] > $prepared[$largest][$side]) {
                $largest = $index;
            }
        }

        $prepared[$largest][$side] -= abs($difference);
    }

    private function resolveAccount(mixed $ref, int $index): LedgerAccount
    {
        $account = match (true) {
            $ref instanceof LedgerAccount => $ref,
            is_int($ref) => LedgerAccount::find($ref),
            is_string($ref) => LedgerAccount::where('code', $ref)->first(),
            default => null,
        };

        if ($account === null) {
            throw new RuntimeException("Line {$index} names an account that does not exist in this book: ".var_export($ref, true));
        }

        if (! $account->is_postable) {
            throw new RuntimeException(
                "{$account->code} {$account->name} is a heading, not an account. Post to one of the accounts under it."
            );
        }

        if (! $account->is_active) {
            throw new RuntimeException("{$account->code} {$account->name} is archived, so nothing more can be posted to it.");
        }

        return $account;
    }

    /**
     * JE-2026-0001, restarting each year.
     *
     * Read inside the posting transaction, so two requests posting at the same
     * moment cannot both take the same number — the unique index on
     * (business_id, reference) is the backstop if they somehow do.
     */
    private function nextReference(Business $business, string $date): string
    {
        $year = substr($date, 0, 4);
        $prefix = "JE-{$year}-";

        $last = JournalEntry::query()
            ->where('business_id', $business->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function requireBusiness(): Business
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nothing to post into.');
        }

        return $business;
    }
}
