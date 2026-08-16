<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored answer.
 *
 * Almost nothing reads this directly — App\Domain\Settings\Settings does, once,
 * and caches the result. Reaching for the model in application code is the way
 * to reintroduce the per-request query the cache exists to remove.
 */
class Setting extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'key', 'value'];
}
