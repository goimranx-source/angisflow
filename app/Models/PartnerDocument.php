<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document attached to a partner: deed, agreement, amendment, etc.
 *
 * Stored on private disk and served only through a controller that checks tenancy.
 */
class PartnerDocument extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    const DIRECTORY = 'partner-documents';
    const DISK = 'private';

    const KINDS = [
        'deed' => 'Partnership Deed',
        'agreement' => 'Agreement',
        'amendment' => 'Amendment',
        'other' => 'Other',
    ];

    protected $fillable = [
        'account_id',
        'business_id',
        'partner_id',
        'kind',
        'title',
        'path',
        'original_name',
        'mime_type',
        'size',
        'signed_on',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'signed_on' => 'date',
        'size' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Get the kind label.
     */
    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
