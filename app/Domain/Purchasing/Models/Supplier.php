<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Somebody the business buys from. */
class Supplier extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'name', 'email', 'phone',
        'tax_number', 'address', 'country', 'currency', 'payment_terms_days',
        'default_expense_account_id', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'default_expense_account_id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'payment_terms_days' => $this->payment_terms_days,
            'is_active' => $this->is_active,
        ];
    }
}
