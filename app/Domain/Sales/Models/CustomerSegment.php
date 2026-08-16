<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A saved question about customers.
 *
 * Rules are structured filters, never SQL. A segment holding SQL is a segment
 * one subscriber can use to read another's data, and no amount of escaping
 * makes that safe enough to be worth the flexibility.
 */
class CustomerSegment extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = ['is_dynamic' => true, 'member_count' => 0];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'name', 'description',
        'rules', 'is_dynamic', 'member_count', 'counted_at',
    ];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_dynamic' => 'boolean', 'member_count' => 'integer', 'counted_at' => 'datetime'];
    }

    public function pinnedMembers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_segment_members')
            ->withPivot('added_at');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'rules' => $this->rules,
            'is_dynamic' => $this->is_dynamic,
            'member_count' => $this->member_count,
            'counted_at' => $this->counted_at?->toIso8601String(),
        ];
    }
}
