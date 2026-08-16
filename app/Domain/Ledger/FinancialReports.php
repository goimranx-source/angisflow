<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reading the ledger back out.
 *
 * ── Why every figure here is a sum over journal_lines ────────────────────────
 *
 * There is no balances table, no running total kept on the account, no nightly
 * job that rolls figures forward. Those exist to make reports fast and they all
 * share one failure: the moment a stored balance disagrees with the postings
 * beneath it, every report is wrong and nothing says so. The disagreement
 * arrives eventually — a job that half-ran, a correction posted with the cache
 * cold, a restore from backup.
 *
 * Summing the lines cannot disagree with the lines. journal_lines carries
 * business_id, entry_date and the base amounts denormalised precisely so this
 * is one indexed scan rather than a join, and a business with a decade of
 * postings is still a table of a few million rows — which a database sums in
 * milliseconds. When that stops being true the answer is a materialised
 * summary that is rebuilt from these queries and checked against them, not a
 * balance nobody can verify.
 *
 * ── Sign conventions, stated once ────────────────────────────────────────────
 *
 * Every figure is returned as a positive Money with the side it falls on, or as
 * a signed balance in the account's own natural direction — an asset with money
 * in it is positive, a liability you owe is positive. Reports that mix "debit
 * positive" and "natural positive" are how a balance sheet ends up with
 * negative liabilities that nobody can explain.
 */
final class FinancialReports
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Every account with movement, and its debit and credit totals.
     *
     * The report that proves the books. If the two columns do not agree, the
     * ledger has been written to by something that bypassed Ledger::post(), and
     * nothing else in here can be believed until that is found.
     *
     * @return array{rows: list<array<string, mixed>>, debit: Money, credit: Money, balanced: bool}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $currency = $this->baseCurrency();

        $rows = $this->postedLines($from, $to)
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type', 'ledger_accounts.subtype')
            ->orderBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.id, ledger_accounts.code, ledger_accounts.name, ledger_accounts.type, ledger_accounts.subtype,
                         SUM(journal_lines.base_debit_minor) AS debit, SUM(journal_lines.base_credit_minor) AS credit')
            ->get();

        $totalDebit = 0;
        $totalCredit = 0;
        $out = [];

        foreach ($rows as $row) {
            $debit = (int) $row->debit;
            $credit = (int) $row->credit;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $net = $this->naturalBalance($row->type, $debit, $credit, $row->subtype);

            // naturalBalance already answers in the account's own direction, so
            // a positive result means "sitting on the side this account
            // normally sits on" — not "debit". Reading it as debit labelled
            // every liability and every revenue account Dr, which is the sort
            // of trial balance somebody spends an afternoon disbelieving.
            $growsOnDebit = in_array($row->type, LedgerAccount::DEBIT_TYPES, true);

            if ($row->subtype !== null && str_starts_with((string) $row->subtype, 'contra_')) {
                $growsOnDebit = ! $growsOnDebit;
            }

            $normal = $growsOnDebit ? 'debit' : 'credit';

            $out[] = [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'subtype' => $row->subtype,
                'debit' => (new Money($debit, $currency))->jsonSerialize(),
                'credit' => (new Money($credit, $currency))->jsonSerialize(),
                'balance' => (new Money(abs($net), $currency))->jsonSerialize(),
                'balance_side' => $net >= 0 ? $normal : $this->otherSide($normal),
            ];
        }

        return [
            'rows' => $out,
            'debit' => new Money($totalDebit, $currency),
            'credit' => new Money($totalCredit, $currency),
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    /**
     * Profit and loss for a period.
     *
     * Revenue less cost of sales is gross profit; less operating expenses is
     * operating profit. The two subtotals are the point of the statement — a
     * flat list of income and expenses hides whether a business is selling at a
     * sensible margin and merely spending too much, or selling at a loss.
     *
     * Contra revenue is subtracted from revenue rather than added to costs. See
     * ChartOfAccounts for why that distinction changes what the statement says.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $currency = $this->baseCurrency();
        $balances = $this->balancesByAccount($from, $to);

        $revenue = $this->section($balances, fn ($r) => $r['type'] === 'revenue' && ! $this->isContra($r));
        $contra = $this->section($balances, fn ($r) => $r['type'] === 'revenue' && $this->isContra($r));
        $cogs = $this->section($balances, fn ($r) => $r['type'] === 'expense' && $r['subtype'] === 'cogs');
        $opex = $this->section($balances, fn ($r) => $r['type'] === 'expense' && $r['subtype'] !== 'cogs');

        $netRevenue = $revenue['total'] - $contra['total'];
        $grossProfit = $netRevenue - $cogs['total'];
        $operatingProfit = $grossProfit - $opex['total'];

        return [
            'from' => $from,
            'to' => $to,
            'currency' => $currency,
            'revenue' => $this->present($revenue, $currency),
            'deductions' => $this->present($contra, $currency),
            'net_revenue' => (new Money($netRevenue, $currency))->jsonSerialize(),
            'cost_of_sales' => $this->present($cogs, $currency),
            'gross_profit' => (new Money($grossProfit, $currency))->jsonSerialize(),
            // Held apart from the figure itself: a margin on zero revenue is
            // not zero percent, it is undefined, and printing 0% invites
            // somebody to average it into something.
            'gross_margin_pct' => $netRevenue === 0 ? null : round($grossProfit / $netRevenue * 100, 2),
            'operating_expenses' => $this->present($opex, $currency),
            'operating_profit' => (new Money($operatingProfit, $currency))->jsonSerialize(),
            'net_margin_pct' => $netRevenue === 0 ? null : round($operatingProfit / $netRevenue * 100, 2),
        ];
    }

    /**
     * What the business owns, owes and is worth, as at a date.
     *
     * ── Why profit for the year appears here ─────────────────────────────────
     *
     * Assets minus liabilities is what the owners have. Equity accounts alone
     * do not show that, because this year's profit has not been moved into
     * retained earnings yet — that happens when the year is closed. Until then
     * the sheet only balances if current earnings are carried in as part of
     * equity, which is exactly what every accounting package does and what the
     * `current_earnings` line below is.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(string $asAt): array
    {
        $currency = $this->baseCurrency();
        $balances = $this->balancesByAccount(null, $asAt);

        $assets = $this->section($balances, fn ($r) => $r['type'] === 'asset');
        $liabilities = $this->section($balances, fn ($r) => $r['type'] === 'liability');
        $equity = $this->section($balances, fn ($r) => $r['type'] === 'equity');

        $revenue = $this->section($balances, fn ($r) => $r['type'] === 'revenue');
        $expenses = $this->section($balances, fn ($r) => $r['type'] === 'expense');
        $earnings = $revenue['total'] - $expenses['total'];

        $totalEquity = $equity['total'] + $earnings;

        return [
            'as_at' => $asAt,
            'currency' => $currency,
            'assets' => $this->present($assets, $currency),
            'liabilities' => $this->present($liabilities, $currency),
            'equity' => $this->present($equity, $currency),
            'current_earnings' => (new Money($earnings, $currency))->jsonSerialize(),
            'total_equity' => (new Money($totalEquity, $currency))->jsonSerialize(),
            // The identity that makes it a balance sheet. Reported rather than
            // assumed: if this is ever false, something posted around Ledger.
            'balances' => $assets['total'] === $liabilities['total'] + $totalEquity,
            'difference' => (new Money(
                $assets['total'] - ($liabilities['total'] + $totalEquity),
                $currency,
            ))->jsonSerialize(),
        ];
    }

    /**
     * Who owes money, and how late.
     *
     * Bucketed from the due date, not the issue date. "Sixty days old" and
     * "sixty days overdue" are different claims and only the second is a reason
     * to ring somebody.
     *
     * @return array<string, mixed>
     */
    public function receivablesAging(?string $asAt = null): array
    {
        return $this->aging(
            \App\Domain\Sales\Models\Invoice::query()
                ->with('customer')
                ->outstanding(),
            'customer',
            $asAt,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, mixed>
     */
    private function aging($query, string $partyRelation, ?string $asAt): array
    {
        $asAt = $asAt ?? now()->toDateString();
        $currency = $this->baseCurrency();

        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $parties = [];

        foreach ($query->get() as $doc) {
            $outstanding = $doc->total_minor - $doc->paid_minor;

            if ($outstanding <= 0) {
                continue;
            }

            $days = $doc->due_date === null
                ? 0
                : (int) floor((strtotime($asAt) - strtotime($doc->due_date->toDateString())) / 86400);

            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $outstanding;

            $party = $doc->{$partyRelation};
            $key = $party?->public_id ?? 'unassigned';

            $parties[$key] ??= [
                'name' => $party?->name ?? 'No account',
                'total' => 0,
                'buckets' => ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0],
            ];
            $parties[$key]['total'] += $outstanding;
            $parties[$key]['buckets'][$bucket] += $outstanding;
        }

        uasort($parties, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'as_at' => $asAt,
            'currency' => $currency,
            'buckets' => array_map(
                fn (int $minor) => (new Money($minor, $currency))->jsonSerialize(),
                $buckets,
            ),
            'total' => (new Money(array_sum($buckets), $currency))->jsonSerialize(),
            'parties' => array_values(array_map(fn (array $p) => [
                'name' => $p['name'],
                'total' => (new Money($p['total'], $currency))->jsonSerialize(),
                'buckets' => array_map(
                    fn (int $minor) => (new Money($minor, $currency))->jsonSerialize(),
                    $p['buckets'],
                ),
            ], $parties)),
        ];
    }

    /**
     * Every posting against one account, with a running balance.
     *
     * The screen somebody opens when a figure looks wrong, so it carries the
     * entry reference and description on every row — a statement of amounts
     * with no way back to what caused them answers nothing.
     *
     * @return array<string, mixed>
     */
    public function accountLedger(LedgerAccount $account, ?string $from = null, ?string $to = null): array
    {
        $currency = $this->baseCurrency();

        $lines = $this->postedLines($from, $to)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->id)
            ->orderBy('journal_lines.entry_date')
            ->orderBy('journal_lines.id')
            ->selectRaw('journal_lines.*, journal_entries.reference, journal_entries.description AS entry_description')
            ->get();

        // The balance carried in from before the window, so a statement for
        // March opens where February left off rather than at zero.
        $opening = 0;

        if ($from !== null) {
            $prior = $this->postedLines(null, date('Y-m-d', strtotime($from.' -1 day')))
                ->where('journal_lines.ledger_account_id', $account->id)
                ->selectRaw('SUM(base_debit_minor) d, SUM(base_credit_minor) c')
                ->first();

            $opening = $this->naturalBalance($account->type, (int) ($prior->d ?? 0), (int) ($prior->c ?? 0), $account->subtype);
        }

        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running += $this->naturalBalance($account->type, $line->base_debit_minor, $line->base_credit_minor, $account->subtype);

            $rows[] = [
                'date' => (string) $line->entry_date,
                'reference' => $line->reference,
                'description' => $line->description ?: $line->entry_description,
                'debit' => (new Money((int) $line->base_debit_minor, $currency))->jsonSerialize(),
                'credit' => (new Money((int) $line->base_credit_minor, $currency))->jsonSerialize(),
                'balance' => (new Money(abs($running), $currency))->jsonSerialize(),
                'balance_side' => $running >= 0 ? $account->normalBalance() : $this->otherSide($account->normalBalance()),
            ];
        }

        return [
            'account' => $account->toPayload(),
            'from' => $from,
            'to' => $to,
            'opening' => (new Money(abs($opening), $currency))->jsonSerialize(),
            'closing' => (new Money(abs($running), $currency))->jsonSerialize(),
            'rows' => $rows,
        ];
    }

    /**
     * Account balances in their natural direction, keyed by code.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function balancesByAccount(?string $from, ?string $to): Collection
    {
        return $this->postedLines($from, $to)
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type', 'ledger_accounts.subtype')
            ->orderBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, ledger_accounts.name, ledger_accounts.type, ledger_accounts.subtype,
                         SUM(journal_lines.base_debit_minor) AS debit, SUM(journal_lines.base_credit_minor) AS credit')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'subtype' => $row->subtype,
                'balance' => $this->naturalBalance($row->type, (int) $row->debit, (int) $row->credit, $row->subtype),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $balances
     * @return array{lines: list<array<string, mixed>>, total: int}
     */
    private function section(Collection $balances, callable $filter): array
    {
        $lines = $balances->filter($filter)->values();

        return [
            'lines' => $lines->all(),
            'total' => (int) $lines->sum('balance'),
        ];
    }

    /**
     * @param  array{lines: list<array<string, mixed>>, total: int}  $section
     * @return array<string, mixed>
     */
    private function present(array $section, string $currency): array
    {
        return [
            'lines' => array_map(fn (array $line) => [
                'code' => $line['code'],
                'name' => $line['name'],
                'subtype' => $line['subtype'],
                'amount' => (new Money($line['balance'], $currency))->jsonSerialize(),
            ], $section['lines']),
            'total' => (new Money($section['total'], $currency))->jsonSerialize(),
        ];
    }

    /**
     * A balance in the direction the account naturally grows.
     *
     * An asset with money in it comes back positive; so does a liability you
     * owe. Without this every liability and every revenue account reads as a
     * negative number, and somebody eventually "fixes" it by flipping a sign in
     * one report and not the others.
     */
    private function naturalBalance(string $type, int $debit, int $credit, ?string $subtype = null): int
    {
        $growsOnDebit = in_array($type, LedgerAccount::DEBIT_TYPES, true);

        // A contra account grows on the opposite side to its type. Sales
        // Returns is a revenue account that fills up with debits; Accumulated
        // Depreciation is an asset account that fills up with credits.
        //
        // Without this, a contra account's natural balance comes back negative,
        // and the P&L then subtracts a negative — so returns of 175 *added* 175
        // to net revenue. The figure was wrong in the most flattering possible
        // direction, which is the kind nobody queries.
        if ($subtype !== null && str_starts_with($subtype, 'contra_')) {
            $growsOnDebit = ! $growsOnDebit;
        }

        return $growsOnDebit ? $debit - $credit : $credit - $debit;
    }

    private function isContra(array $row): bool
    {
        return str_starts_with((string) $row['subtype'], 'contra_');
    }

    private function otherSide(string $side): string
    {
        return $side === 'debit' ? 'credit' : 'debit';
    }

    /**
     * Posted lines only, within the window.
     *
     * Drafts are excluded everywhere. A draft is not a statement about anything
     * that happened, and a receivables figure that includes half-written
     * invoices is a figure nobody can chase from.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function postedLines(?string $from, ?string $to)
    {
        $query = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('je.status', [JournalEntry::POSTED, JournalEntry::REVERSED])
            ->where('journal_lines.business_id', $this->businessId());

        if ($from !== null) {
            $query->whereDate('journal_lines.entry_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_lines.entry_date', '<=', $to);
        }

        return $query->getQuery();
    }

    private function businessId(): int
    {
        $business = $this->tenant->business();

        if ($business === null) {
            throw new RuntimeException('No set of books is open, so there is nothing to report on.');
        }

        return $business->id;
    }

    private function baseCurrency(): string
    {
        return strtoupper($this->tenant->business()?->base_currency ?? 'USD');
    }
}
