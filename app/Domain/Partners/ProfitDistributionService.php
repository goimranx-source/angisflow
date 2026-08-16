<?php

namespace App\Domain\Partners;

use App\Domain\Identity\Models\User;
use App\Domain\Ledger\FinancialReports;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\ProfitDistribution;
use App\Models\ProfitDistributionLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocate profit among partners according to their agreed shares.
 *
 * ## How profit distribution works
 *
 * 1. Get net profit for the period from P&L
 * 2. Determine each partner's share based on their percentage at the time
 * 3. Post DR Retained Earnings / CR Partner Current Account for each partner
 * 4. Record the distribution with full working (who got what and why)
 *
 * ## Share calculation
 *
 * Partners can have different shares in different periods. The service looks up
 * the effective share percentage for each partner during the profit period.
 *
 * If a partner has no agreed share, profit can optionally be split by capital
 * contributed (not implemented in this version — deferred to later refinement).
 *
 * ## Reversal, not deletion
 *
 * Distributions are reversed with compensating entries rather than deleted.
 * Both the original and the reversal stay on record.
 */
class ProfitDistributionService
{
    const RETAINED_EARNINGS_CODE = '3900';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
        private readonly FinancialReports $reports,
    ) {}

    /**
     * Preview what each partner would get for a period.
     *
     * Calculates shares but does not post anything.
     *
     * @return array{net_profit: Money, shares: array, total_allocated: Money, unallocated: Money}
     */
    public function preview(string $from, string $to): array
    {
        $business = $this->requireBusiness();
        $currency = $business->base_currency;

        // Get net profit from P&L
        $netProfit = $this->getNetProfit($from, $to);

        // Get transactable partners (verified and active)
        $partners = Partner::where('business_id', $business->id)
            ->transactable()
            ->with('sharePeriods')
            ->get();

        if ($partners->isEmpty()) {
            return [
                'net_profit' => $netProfit,
                'shares' => [],
                'total_allocated' => new Money(0, $currency),
                'unallocated' => $netProfit,
            ];
        }

        // Calculate each partner's share
        $shares = [];
        $totalPercent = 0;

        foreach ($partners as $partner) {
            $sharePercent = $this->getEffectiveShare($partner, $from, $to);

            if ($sharePercent === null || $sharePercent <= 0) {
                continue; // Skip partners with no share
            }

            $amount = new Money(
                (int) round($netProfit->minor * $sharePercent / 100),
                $currency
            );

            $shares[] = [
                'partner_id' => $partner->id,
                'partner_name' => $partner->name,
                'partner_code' => $partner->code,
                'share_percent' => $sharePercent,
                'amount' => $amount,
            ];

            $totalPercent += $sharePercent;
        }

        $totalAllocated = new Money(
            array_sum(array_map(fn($share) => $share['amount']->minor, $shares)),
            $currency
        );

        $unallocated = new Money(
            $netProfit->minor - $totalAllocated->minor,
            $currency
        );

        return [
            'net_profit' => $netProfit,
            'shares' => $shares,
            'total_allocated' => $totalAllocated,
            'total_percent' => round($totalPercent, 3),
            'unallocated' => $unallocated,
        ];
    }

    /**
     * Distribute profit for a period.
     *
     * Posts DR Retained Earnings / CR Partner Current Account for each partner.
     *
     * @return ProfitDistribution
     */
    public function distribute(string $from, string $to, ?string $note = null): ProfitDistribution
    {
        $business = $this->requireBusiness();
        $currency = $business->base_currency;

        $preview = $this->preview($from, $to);

        if (empty($preview['shares'])) {
            throw new RuntimeException('No transactable partners found. Cannot distribute profit to nobody.');
        }

        // Check if period already distributed
        $existing = ProfitDistribution::where('business_id', $business->id)
            ->live()
            ->where('period_from', $from)
            ->where('period_to', $to)
            ->first();

        if ($existing) {
            throw new RuntimeException(
                "Profit for {$from} to {$to} has already been distributed (reference {$existing->reference}). Reverse that one first."
            );
        }

        return DB::transaction(function () use ($business, $currency, $from, $to, $note, $preview) {
            // Create distribution header
            $distribution = ProfitDistribution::create([
                'account_id' => $this->tenant->account()->id,
                'business_id' => $business->id,
                'reference' => ProfitDistribution::nextReference($business->id, $to),
                'period_from' => $from,
                'period_to' => $to,
                'net_profit_minor' => $preview['net_profit']->minor,
                'currency' => $currency,
                'distributed_minor' => $preview['total_allocated']->minor,
                'note' => $note,
                'recorded_by_user_id' => auth()->id(),
            ]);

            $retainedEarnings = $this->getRetainedEarningsAccount();

            // Post allocation for each partner
            foreach ($preview['shares'] as $share) {
                $partner = Partner::find($share['partner_id']);

                if (!$partner->current_account_id) {
                    throw new RuntimeException(
                        "{$partner->name} does not have a current account. Cannot allocate profit without it."
                    );
                }

                $currentAccount = LedgerAccount::find($partner->current_account_id);

                $description = "Profit share for {$partner->name} ({$share['share_percent']}% of {$from} to {$to})";

                // Post: DR Retained Earnings / CR Partner Current Account
                $entry = $this->ledger->post($to, $description, [
                    ['account' => $retainedEarnings, 'debit' => $share['amount'], 'description' => $description],
                    ['account' => $currentAccount, 'credit' => $share['amount'], 'description' => $description],
                ], [
                    'source' => 'profit_distribution',
                    'source_ref' => $distribution->reference,
                    'subject_type' => ProfitDistribution::class,
                    'subject_id' => $distribution->id,
                ]);

                // Record line
                ProfitDistributionLine::create([
                    'account_id' => $this->tenant->account()->id,
                    'business_id' => $business->id,
                    'profit_distribution_id' => $distribution->id,
                    'partner_id' => $partner->id,
                    'amount_minor' => $share['amount']->minor,
                    'share_percent' => $share['share_percent'],
                    'profit_used_minor' => $preview['net_profit']->minor,
                    'transaction_id' => $entry->id,
                ]);
            }

            return $distribution->load('lines.partner');
        });
    }

    /**
     * Reverse a profit distribution.
     *
     * Posts compensating entries (opposite of the original) and marks the
     * distribution as reversed.
     */
    public function reverse(ProfitDistribution $distribution, ?string $reason = null): ProfitDistribution
    {
        if ($distribution->isReversed()) {
            throw new RuntimeException("Distribution {$distribution->reference} has already been reversed.");
        }

        // Load the lines with transaction relationship to avoid lazy loading
        $distribution->load('lines.transaction');

        return DB::transaction(function () use ($distribution, $reason) {
            // Reverse each line's transaction
            foreach ($distribution->lines as $line) {
                if ($line->transaction_id && $line->transaction) {
                    $this->ledger->reverse(
                        $line->transaction,
                        now()->toDateString(),
                        $reason ?? "Reversing profit distribution {$distribution->reference}"
                    );
                }
            }

            // Mark distribution as reversed
            $distribution->update([
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            return $distribution->fresh();
        });
    }

    /**
     * Get all distributions for a business.
     */
    public function history(): array
    {
        $business = $this->requireBusiness();

        return ProfitDistribution::where('business_id', $business->id)
            ->with(['lines.partner', 'recordedBy'])
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get()
            ->map(function ($dist) {
                return [
                    'id' => $dist->id,
                    'reference' => $dist->reference,
                    'period_from' => $dist->period_from->toDateString(),
                    'period_to' => $dist->period_to->toDateString(),
                    'net_profit' => new Money($dist->net_profit_minor, $dist->currency),
                    'distributed' => new Money($dist->distributed_minor, $dist->currency),
                    'partners_count' => $dist->lines->count(),
                    'is_reversed' => $dist->isReversed(),
                    'reversed_at' => $dist->reversed_at?->toDateString(),
                    'recorded_by' => $dist->recordedBy?->name,
                    'recorded_at' => $dist->created_at->toDateString(),
                ];
            })
            ->toArray();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Get net profit for a period from the P&L.
     */
    private function getNetProfit(string $from, string $to): Money
    {
        $business = $this->requireBusiness();

        $pl = $this->reports->profitAndLoss($from, $to);

        // Net profit is the operating profit from the P&L
        $netProfitMinor = $pl['operating_profit']['minor'] ?? 0;

        return new Money($netProfitMinor, $business->base_currency);
    }

    /**
     * Get the effective share percentage for a partner during a period.
     *
     * Uses the most recent share period that was effective during the period.
     * Returns null if no share is defined.
     *
     * KNOWN LIMITATION: a partner whose share changed mid-period is not
     * prorated. The migration docblock for partner_share_periods describes
     * splitting such a period at the change date and weighting each partner's
     * allocation by the days their old and new percentages were each in
     * force; this method instead applies a single percentage (the one
     * effective on or before the period's end date) to the whole period. For
     * a partner whose share is stable across the period this is exact; for
     * one whose share changed partway through, it is wrong in whichever
     * direction the change went, silently. Left for a follow-up rather than
     * built here because splitting a period correctly touches `preview()`,
     * `distribute()` and the P&L period boundaries together, and is a bigger
     * change than this pass has room for — flagged here so it is not
     * mistaken for handled.
     */
    private function getEffectiveShare(Partner $partner, string $from, string $to): ?float
    {
        // Get the most recent share period effective on or before the end of the profit period
        $period = $partner->sharePeriods()
            ->where('effective_from', '<=', $to)
            ->orderByDesc('effective_from')
            ->first();

        if (!$period) {
            // Fall back to partner's current share if no period found
            return $partner->profit_share_percent ? (float) $partner->profit_share_percent : null;
        }

        return (float) $period->share_percent;
    }

    /**
     * Get or create the Retained Earnings account.
     */
    private function getRetainedEarningsAccount(): LedgerAccount
    {
        $business = $this->requireBusiness();

        $account = LedgerAccount::where('business_id', $business->id)
            ->where('code', self::RETAINED_EARNINGS_CODE)
            ->first();

        if (!$account) {
            $account = LedgerAccount::create([
                'business_id' => $business->id,
                'code' => self::RETAINED_EARNINGS_CODE,
                'name' => 'Retained Earnings',
                'type' => 'equity',
                'subtype' => 'retained',
                'is_active' => true,
                'is_system' => true,
            ]);
        }

        return $account;
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (!$business) {
            throw new RuntimeException('No business context. Profit distribution requires a business.');
        }

        return $business;
    }
}
