<?php

declare(strict_types=1);

namespace App\Domain\Organiser\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * A label the account puts on its own things.
 *
 * Shared across everybody on the account, unlike a favourite — see the
 * migration for why the two are kept apart.
 */
class Tag extends Model
{
    use BelongsToAccount, HasPublicId;

    protected $fillable = [
        'public_id',
        'account_id',
        'name',
        'colour',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'colour' => $this->colour,
        ];
    }
}
