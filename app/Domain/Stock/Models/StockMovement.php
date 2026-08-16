<?php

declare(strict_types=1);

namespace App\Domain\Stock\Models;

use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change in what is held. Signed: positive brings stock in, negative takes it out. */
class StockMovement extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const ADJUSTMENT = 'adjustment';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const RETURN_IN = 'return_in';

    public const WRITE_OFF = 'write_off';

    public const OPENING = 'opening';

    protected $attributes = ['unit_cost_minor' => 0];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'product_variant_id',
        'stock_location_id', 'stock_batch_id', 'kind', 'quantity',
        'unit_cost_minor', 'currency', 'moved_on', 'reason',
        'subject_type', 'subject_id', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['moved_on' => 'date', 'unit_cost_minor' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function isInbound(): bool
    {
        return (float) $this->quantity > 0;
    }

    /** What this movement was worth. Always positive; direction lives in the quantity. */
    public function value(): Money
    {
        return new Money(
            (int) round($this->unit_cost_minor * abs((float) $this->quantity)),
            $this->currency,
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'quantity' => (float) $this->quantity,
            'moved_on' => $this->moved_on?->toDateString(),
            'reason' => $this->reason,
            'value' => $this->value()->jsonSerialize(),
            'batch' => $this->relationLoaded('batch') ? $this->batch?->batch_number : null,
            'location' => $this->relationLoaded('location') ? $this->location?->toPayload() : null,
        ];
    }
}
