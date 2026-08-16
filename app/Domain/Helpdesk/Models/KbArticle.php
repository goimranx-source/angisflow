<?php

declare(strict_types=1);

namespace App\Domain\Helpdesk\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer, serving three audiences.
 *
 * The public help centre, the agent's canned reply, and the bot all read the
 * same row. Three copies of an answer drift, and the customer always gets the
 * one nobody updated.
 */
class KbArticle extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected $attributes = [
        'status' => self::DRAFT,
        'is_public' => true,
        'is_canned_reply' => false,
        'is_bot_answer' => false,
        'view_count' => 0,
        'helpful_count' => 0,
        'unhelpful_count' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'helpdesk_category_id',
        'title', 'slug', 'summary', 'body', 'is_public', 'is_canned_reply',
        'is_bot_answer', 'keywords', 'status', 'view_count', 'helpful_count',
        'unhelpful_count', 'author_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_canned_reply' => 'boolean',
            'is_bot_answer' => 'boolean',
            'published_at' => 'datetime',
            'view_count' => 'integer',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpdeskCategory::class, 'helpdesk_category_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * How well it answers, where enough people have said.
     *
     * Null under five votes. Two out of two is 100% and means nothing, and a
     * figure that meaningless will still be sorted on.
     */
    public function helpfulness(): ?float
    {
        $votes = $this->helpful_count + $this->unhelpful_count;

        return $votes < 5 ? null : round($this->helpful_count / $votes * 100, 1);
    }

    /** Published, and answering badly — the queue worth rewriting. */
    public function scopeFailing(Builder $q): Builder
    {
        return $q->where('status', self::PUBLISHED)
            ->whereRaw('(helpful_count + unhelpful_count) >= 5')
            ->whereColumn('unhelpful_count', '>', 'helpful_count');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::PUBLISHED);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'status' => $this->status,
            'is_public' => $this->is_public,
            'view_count' => $this->view_count,
            'helpfulness' => $this->helpfulness(),
        ];
    }
}
