<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * `where account_id = ?` on everything, always.
 *
 * The qualified column name matters: an unqualified `account_id` is ambiguous
 * the moment a query joins two tenant tables, and MySQL answers that with an
 * error rather than a guess — which at least fails loudly, unlike the version
 * of this bug where the join silently filters on the wrong side.
 */
final class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSuspended()) {
            return;
        }

        $accountId = $context->accountId();

        if ($accountId === null) {
            // No tenant and no deliberate escape hatch. Returning everything
            // here would mean a bug in resolving the account silently becomes a
            // cross-subscriber data leak; returning nothing makes it a visibly
            // empty screen, which somebody reports in a minute.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('account_id'), $accountId);
    }
}
