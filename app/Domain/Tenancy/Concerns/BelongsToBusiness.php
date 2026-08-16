<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Puts a model inside one set of books.
 *
 * ── Why the ledger needs a narrower boundary than the account ────────────────
 *
 * BelongsToAccount keeps one subscriber's data away from another's, which is
 * the security boundary. It is not the accounting boundary. A group running
 * three businesses in one account has three separate sets of books, each with
 * its own chart of accounts, its own fiscal years, its own trial balance that
 * must balance on its own. Sum them and you get a number that is not any
 * business's profit and not the group's either.
 *
 * So ledger tables carry both keys: account_id because they are a subscriber's,
 * business_id because they are one book's. Both are scoped, in that order,
 * matching the index.
 *
 * ── Why writing refuses rather than defaulting ───────────────────────────────
 *
 * A missing account_id can be filled in from context safely — there is exactly
 * one, and a user only ever has theirs. A missing business_id cannot: a user
 * with three businesses has a *current* one, and quietly posting a journal
 * entry into whichever was last opened is how a month's figures end up in the
 * wrong book. Nobody notices until the year is closed.
 *
 * If there is no business in context, that is a bug in the caller, and it says
 * so.
 */
trait BelongsToBusiness
{
    use BelongsToAccount;

    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $builder): void {
            $businessId = app(TenantContext::class)->businessId();

            if ($businessId !== null) {
                $builder->where($builder->getModel()->getTable().'.business_id', $businessId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('business_id') !== null) {
                return;
            }

            $businessId = app(TenantContext::class)->businessId();

            if ($businessId === null) {
                throw new RuntimeException(sprintf(
                    '%s belongs to a set of books, and none is open. Set the business on TenantContext, or pass business_id explicitly.',
                    class_basename($model),
                ));
            }

            $model->setAttribute('business_id', $businessId);
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Read across every book in the account.
     *
     * For the few genuinely group-level questions — "what did we bill in total
     * this year" — and deliberately awkward to reach, because most questions
     * that look group-level are not.
     */
    public function scopeAcrossBusinesses(Builder $query): Builder
    {
        return $query->withoutGlobalScope('business');
    }
}
