<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Scopes\AccountScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Puts a model inside one subscriber's data and keeps it there.
 *
 * Two halves, and both are needed:
 *
 *   reading   a global scope adds `where account_id = ?` to every query. It is
 *             the first column of every index on these tables, so this is not
 *             a filter applied after reading — the database never looks at
 *             another subscriber's rows at all.
 *
 *   writing   account_id is stamped on create from the current context. A row
 *             cannot be written into the wrong account by forgetting a field,
 *             because the field is not the caller's to supply.
 *
 * Isolation you have to remember is isolation you will one day forget, and the
 * failure is silent and unbounded: a report that quietly includes somebody
 * else's orders looks exactly like a report that does not.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('account_id') === null) {
                $model->setAttribute('account_id', app(TenantContext::class)->accountId());
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Read across every subscriber.
     *
     * Named to be conspicuous in a diff and in a code review, because that is
     * the whole point: there are perhaps five legitimate callers of this in the
     * entire system — the admin panel, a platform-wide report, a backfill — and
     * every one of them should be obvious.
     */
    public static function acrossAllAccounts(): Builder
    {
        return static::query()->withoutGlobalScope(AccountScope::class);
    }
}
