<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A partner is an owner of the business — someone who shares the profit and risk.
 *
 * ## Three accounts
 *
 * Each partner has three ledger accounts:
 * - **Liability account** — what the business owes them (for expenses paid out of pocket)
 * - **Capital account** (equity) — permanent investment
 * - **Current account** (equity) — running balance of profit allocated and drawings taken
 *
 * ## KYC before transacting
 *
 * Partners are dormant until `id_verified_at` is set. They can be created (to get
 * the record on file) but cannot hold capital, draw, or receive profit until
 * identity is verified.
 *
 * ## Profit share
 *
 * `profit_share_percent` is the current share. Historical shares are in
 * `partner_share_periods` table.
 */
class Partner extends Model
{
    use BelongsToAccount,
        BelongsToBusiness,
        HasPublicId,
        SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'liability_account_id',
        'capital_account_id',
        'current_account_id',
        'linked_user_id',
        'code',
        'name',
        'phone',
        'email',
        'notes',
        'is_active',
        'profit_share_percent',
        'photo_path',
        'address',
        'city',
        'country',
        'father_name',
        'mother_name',
        'blood_group',
        'joined_on',
        'emergency_name',
        'emergency_phone',
        'nominee_name',
        'nominee_relation',
        'nominee_phone',
        'nominee_id_number',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_branch',
        'mobile_wallet',
        'id_type',
        'id_country',
        'id_number',
        'id_name',
        'id_date_of_birth',
        'id_front_path',
        'id_back_path',
        'id_extracted',
        'id_verified_at',
        'id_verified_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'profit_share_percent' => 'decimal:3',
        'joined_on' => 'date',
        'id_date_of_birth' => 'date',
        'id_extracted' => 'array',
        'id_verified_at' => 'datetime',
    ];

    // ── Verification status ─────────────────────────────────────────────

    /**
     * Whether identity has been verified against a document.
     */
    public function isVerified(): bool
    {
        return $this->id_verified_at !== null;
    }

    /**
     * Whether this partner can transact.
     *
     * Dormant until verified (a partner record is a claim on money, so it stays
     * inert until somebody has checked the person exists). Inactive partners
     * cannot transact even if verified.
     */
    public function canTransact(): bool
    {
        return $this->isVerified() && $this->is_active;
    }

    /**
     * Partners who can actually transact (verified and active).
     */
    public function scopeTransactable($query)
    {
        return $query->whereNotNull('id_verified_at')->where('is_active', true);
    }

    // ── Relationships ───────────────────────────────────────────────────

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Ledger\Models\LedgerAccount::class, 'liability_account_id');
    }

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Ledger\Models\LedgerAccount::class, 'capital_account_id');
    }

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Ledger\Models\LedgerAccount::class, 'current_account_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_verified_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class)->latest('id');
    }

    public function sharePeriods(): HasMany
    {
        return $this->hasMany(PartnerSharePeriod::class)->orderBy('effective_from');
    }

    public function distributionLines(): HasMany
    {
        return $this->hasMany(ProfitDistributionLine::class);
    }

    // ── Helper methods ──────────────────────────────────────────────────

    /**
     * Get the next available partner code.
     */
    public static function nextCode(int $businessId): string
    {
        $last = self::where('business_id', $businessId)
            ->whereRaw("code GLOB 'P[0-9][0-9][0-9]*'") // SQLite: codes starting with P followed by digits
            ->orderByRaw('CAST(SUBSTR(code, 2) AS INTEGER) DESC')
            ->value('code');

        if (!$last) {
            return 'P001';
        }

        $number = (int) substr($last, 1);
        return 'P' . str_pad((string)($number + 1), 3, '0', STR_PAD_LEFT);
    }
}
