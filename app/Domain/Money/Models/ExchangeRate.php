<?php

declare(strict_types=1);

namespace App\Domain\Money\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * What one unit of a currency is worth in this subscriber's books.
 *
 * Held as "one unit of `code` is worth `rate` of `base`" — the direction anyone
 * actually quotes (1 USD = 122 BDT, not 1 BDT = 0.0082 USD), and the direction
 * that makes converting a store's takings a multiplication rather than a
 * division.
 */
class ExchangeRate extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'base', 'code', 'rate', 'source', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'fetched_at' => 'datetime',
        ];
    }

    /** Typed by hand, and never overwritten by a fetch. */
    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}
