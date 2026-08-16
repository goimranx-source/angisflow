<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Two identifiers per row, each doing a job the other cannot.
 *
 *   id         BIGINT, auto-increment. What every foreign key and index uses.
 *              Sequential, so InnoDB appends to the end of the clustered index
 *              instead of splitting pages in the middle of it — which is the
 *              difference between an insert-heavy table staying fast at a
 *              billion rows and slowly grinding to a halt. Never leaves the
 *              server.
 *
 *   public_id  ULID, 26 characters. What URLs, API responses and webhooks
 *              carry. Random enough that nobody can walk the range, sortable
 *              by creation time so it still indexes well, and — the part that
 *              matters commercially — it does not tell a competitor how many
 *              orders you took last month, which /orders/48213 does.
 *
 * A UUID primary key would give the second property and destroy the first: 128
 * random bits as a clustered key means every insert lands in a random page.
 * Keeping the two apart costs 26 bytes and one unique index per table.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->public_id)) {
                $model->public_id = strtolower((string) Str::ulid());
            }
        });
    }

    /** Bind {model} in a route by its public id, never by the row id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeWherePublicId($query, string $publicId)
    {
        return $query->where($this->getTable().'.public_id', $publicId);
    }
}
