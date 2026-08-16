<?php

namespace App\Domain\Partners;

use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\PartnerSharePeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Partner lifecycle and transactions.
 *
 * ## What partners are
 *
 * A partner is an owner of the business — someone who shares the profit and risk.
 * Not a supplier or "business partner"; an equity holder.
 *
 * ## Three accounts
 *
 * Each partner has three ledger accounts:
 * - **Liability** — what the business owes them (expenses paid out of pocket)
 * - **Capital** (equity) — permanent investment
 * - **Current** (equity) — running balance of profit allocated and drawings taken
 *
 * ## KYC before transacting
 *
 * Partners are dormant until identity is verified (`id_verified_at`). They can be
 * created but cannot hold capital, draw, or receive profit until verified.
 */
class PartnerService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Create a new partner.
     *
     * @param array $data Partner attributes
     * @param bool $withCapitalAccount Whether to create capital and current accounts immediately
     * @return Partner
     */
    public function create(array $data, bool $withCapitalAccount = false): Partner
    {
        $business = $this->requireBusiness();

        // Generate code if not provided
        if (empty($data['code'])) {
            $data['code'] = Partner::nextCode($business->id);
        }

        // Fill tenancy
        $data['account_id'] = $this->tenant->account()->id;
        $data['business_id'] = $business->id;

        // Extract share period data before creating partner
        $sharePeriodData = [
            'profit_share_percent' => $data['profit_share_percent'] ?? null,
            'share_effective_from' => $data['share_effective_from'] ?? null,
            'share_note' => $data['share_note'] ?? null,
        ];

        // Remove share period fields from partner data
        unset($data['share_effective_from'], $data['share_note']);

        return DB::transaction(function () use ($data, $withCapitalAccount, $sharePeriodData) {
            $partner = Partner::create($data);

            // Create liability account (always)
            $partner->liability_account_id = $this->createLiabilityAccount($partner)->id;

            // Create capital and current accounts if requested
            if ($withCapitalAccount) {
                $partner->capital_account_id = $this->createCapitalAccount($partner)->id;
                $partner->current_account_id = $this->createCurrentAccount($partner)->id;
            }

            $partner->save();

            // Record initial share period if provided
            if ($sharePeriodData['profit_share_percent'] && $sharePeriodData['share_effective_from']) {
                $this->recordSharePeriod($partner, $sharePeriodData);
            }

            return $partner->fresh();
        });
    }

    /**
     * Update a partner.
     */
    public function update(Partner $partner, array $data, bool $withCapitalAccount = false): Partner
    {
        // Extract share period data before updating partner
        $sharePeriodData = [
            'profit_share_percent' => $data['profit_share_percent'] ?? null,
            'share_effective_from' => $data['share_effective_from'] ?? null,
            'share_note' => $data['share_note'] ?? null,
        ];

        // Remove share period fields from partner data
        unset($data['share_effective_from'], $data['share_note']);

        return DB::transaction(function () use ($partner, $data, $withCapitalAccount, $sharePeriodData) {
            $partner->update($data);

            // Create capital and current accounts if they don't exist and requested
            if ($withCapitalAccount) {
                if (!$partner->capital_account_id) {
                    $partner->capital_account_id = $this->createCapitalAccount($partner)->id;
                }
                if (!$partner->current_account_id) {
                    $partner->current_account_id = $this->createCurrentAccount($partner)->id;
                }
                $partner->save();
            }

            // Record new share period if provided
            if ($sharePeriodData['profit_share_percent'] && $sharePeriodData['share_effective_from']) {
                $this->recordSharePeriod($partner, $sharePeriodData);
            }

            return $partner->fresh();
        });
    }

    /**
     * Verify a partner's identity.
     *
     * Until verified, the partner is dormant and cannot transact.
     */
    public function verifyIdentity(Partner $partner, array $verificationData, int $verifiedByUserId): Partner
    {
        $partner->update([
            'id_type' => $verificationData['id_type'],
            'id_country' => $verificationData['id_country'] ?? null,
            'id_number' => $verificationData['id_number'],
            'id_name' => $verificationData['id_name'],
            'id_date_of_birth' => $verificationData['id_date_of_birth'] ?? null,
            'id_front_path' => $verificationData['id_front_path'] ?? null,
            'id_back_path' => $verificationData['id_back_path'] ?? null,
            'id_extracted' => $verificationData['id_extracted'] ?? null,
            'id_verified_at' => now(),
            'id_verified_by_user_id' => $verifiedByUserId,
        ]);

        return $partner->fresh();
    }

    /**
     * Record capital contributed or withdrawn.
     *
     * DR Cash / CR Capital Account (contribution)
     * DR Capital Account / CR Cash (withdrawal)
     */
    public function recordCapital(
        Partner $partner,
        Money $amount,
        string $moneyAccountCode,
        string $date,
        string $direction, // 'in' or 'out'
        ?string $note = null,
    ): void {
        $this->mustBeVerified($partner);

        if (!$partner->capital_account_id) {
            throw new RuntimeException("{$partner->name} does not have a capital account. Enable it first.");
        }

        $capitalAccount = LedgerAccount::find($partner->capital_account_id);
        $moneyAccount = LedgerAccount::where('business_id', $partner->business_id)
            ->where('code', $moneyAccountCode)
            ->firstOrFail();

        $description = $direction === 'in'
            ? "Capital from {$partner->name}"
            : "Capital withdrawal by {$partner->name}";

        if ($note) {
            $description .= " — {$note}";
        }

        $lines = $direction === 'in'
            ? [
                ['account' => $moneyAccount, 'debit' => $amount, 'description' => $description],
                ['account' => $capitalAccount, 'credit' => $amount, 'description' => $description],
            ]
            : [
                ['account' => $capitalAccount, 'debit' => $amount, 'description' => $description],
                ['account' => $moneyAccount, 'credit' => $amount, 'description' => $description],
            ];

        $this->ledger->post($date, $description, $lines, [
            'source' => 'partner',
            'subject_type' => Partner::class,
            'subject_id' => $partner->id,
        ]);
    }

    /**
     * Record a drawing (partner taking money out of profits).
     *
     * DR Current Account / CR Cash
     *
     * Drawings come from the current account, not capital. Capital is permanent
     * investment; current account is where profit allocations and drawings flow.
     */
    public function recordDrawing(
        Partner $partner,
        Money $amount,
        string $moneyAccountCode,
        string $date,
        ?string $note = null,
    ): void {
        $this->mustBeVerified($partner);

        if (!$partner->current_account_id) {
            throw new RuntimeException("{$partner->name} does not have a current account. Enable capital accounts first.");
        }

        $currentAccount = LedgerAccount::find($partner->current_account_id);
        $moneyAccount = LedgerAccount::where('business_id', $partner->business_id)
            ->where('code', $moneyAccountCode)
            ->firstOrFail();

        $description = "Drawing by {$partner->name}";
        if ($note) {
            $description .= " — {$note}";
        }

        $this->ledger->post($date, $description, [
            ['account' => $currentAccount, 'debit' => $amount, 'description' => $description],
            ['account' => $moneyAccount, 'credit' => $amount, 'description' => $description],
        ], [
            'source' => 'partner',
            'subject_type' => Partner::class,
            'subject_id' => $partner->id,
        ]);
    }

    /**
     * Settle what the business owes a partner (from liability account).
     *
     * DR Liability Account / CR Cash
     *
     * This is for reimbursing expenses they paid out of pocket, not for drawings.
     */
    public function settle(
        Partner $partner,
        Money $amount,
        string $moneyAccountCode,
        string $date,
        ?string $note = null,
    ): void {
        $this->mustBeVerified($partner);

        $liabilityAccount = LedgerAccount::find($partner->liability_account_id);
        $moneyAccount = LedgerAccount::where('business_id', $partner->business_id)
            ->where('code', $moneyAccountCode)
            ->firstOrFail();

        $description = "Repayment to {$partner->name}";
        if ($note) {
            $description .= " — {$note}";
        }

        $this->ledger->post($date, $description, [
            ['account' => $liabilityAccount, 'debit' => $amount, 'description' => $description],
            ['account' => $moneyAccount, 'credit' => $amount, 'description' => $description],
        ], [
            'source' => 'partner',
            'subject_type' => Partner::class,
            'subject_id' => $partner->id,
        ]);
    }

    /**
     * Record a partner paying a business expense out of pocket.
     *
     * DR Expense Account / CR Liability Account
     *
     * Creates a liability — the business now owes the partner for this expense.
     */
    public function recordExpense(
        Partner $partner,
        Money $amount,
        string $expenseAccountCode,
        string $date,
        string $description,
        ?string $note = null,
    ): void {
        $this->mustBeVerified($partner);

        $liabilityAccount = LedgerAccount::find($partner->liability_account_id);
        $expenseAccount = LedgerAccount::where('business_id', $partner->business_id)
            ->where('code', $expenseAccountCode)
            ->firstOrFail();

        $fullDescription = $description;
        if ($note) {
            $fullDescription .= " — {$note}";
        }
        $fullDescription .= " (paid by {$partner->name})";

        $this->ledger->post($date, $fullDescription, [
            ['account' => $expenseAccount, 'debit' => $amount, 'description' => $fullDescription],
            ['account' => $liabilityAccount, 'credit' => $amount, 'description' => $fullDescription],
        ], [
            'source' => 'partner',
            'subject_type' => Partner::class,
            'subject_id' => $partner->id,
        ]);
    }

    // ── Account creation ────────────────────────────────────────────────

    private function createLiabilityAccount(Partner $partner): LedgerAccount
    {
        $code = $this->nextAccountCode('2150', $partner->code);

        return LedgerAccount::create([
            'business_id' => $partner->business_id,
            'code' => $code,
            'name' => "Partner Liability — {$partner->name}",
            'type' => 'liability',
            'subtype' => 'current',
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    private function createCapitalAccount(Partner $partner): LedgerAccount
    {
        $code = $this->nextAccountCode('3100', $partner->code);

        return LedgerAccount::create([
            'business_id' => $partner->business_id,
            'code' => $code,
            'name' => "Partner Capital — {$partner->name}",
            'type' => 'equity',
            'subtype' => 'capital',
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    private function createCurrentAccount(Partner $partner): LedgerAccount
    {
        $code = $this->nextAccountCode('3200', $partner->code);

        return LedgerAccount::create([
            'business_id' => $partner->business_id,
            'code' => $code,
            'name' => "Partner Current — {$partner->name}",
            'type' => 'equity',
            'subtype' => 'current',
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    /**
     * Generate next account code in a range.
     *
     * For partner P001, liability is 2150-P001, capital 3100-P001, etc.
     * `partner.code` is already unique per business, so it is what makes the
     * account code unique — a timestamp would too, but it turns a readable
     * chart of accounts (2150-P001) into an unreadable one (2150-P001-
     * 1739500000) for no benefit once the code itself is guaranteed unique.
     */
    private function nextAccountCode(string $base, string $partnerCode): string
    {
        return "{$base}-{$partnerCode}";
    }

    // ── Share management ────────────────────────────────────────────────

    /**
     * Record a new share period (when partner's percentage changes).
     */
    private function recordSharePeriod(Partner $partner, array $data): void
    {
        if (empty($data['profit_share_percent']) || empty($data['share_effective_from'])) {
            return;
        }

        PartnerSharePeriod::create([
            'account_id' => $partner->account_id,
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'share_percent' => $data['profit_share_percent'],
            'effective_from' => $data['share_effective_from'],
            'note' => $data['share_note'] ?? null,
            'recorded_by_user_id' => auth()->id(),
        ]);

        // Update partner's current share
        $partner->update([
            'profit_share_percent' => $data['profit_share_percent'],
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function mustBeVerified(Partner $partner): void
    {
        if (!$partner->isVerified()) {
            throw new RuntimeException(
                "{$partner->name} has not been verified yet. Complete their KYC check before they can transact."
            );
        }

        if (!$partner->is_active) {
            throw new RuntimeException("{$partner->name} is inactive and cannot transact.");
        }
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (!$business) {
            throw new RuntimeException('No business context. Partner operations require a business.');
        }

        return $business;
    }
}
