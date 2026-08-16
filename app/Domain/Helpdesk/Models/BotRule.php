<?php

declare(strict_types=1);

namespace App\Domain\Helpdesk\Models;

use App\Domain\Inbox\Models\InboxChannel;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the bot knows how to do.
 *
 * Rows rather than code, because a subscriber knows their five most common
 * questions and we cannot. What we supply is the machinery — match, answer,
 * hand off — configurable by somebody who does not write code.
 */
class BotRule extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const KEYWORD = 'keyword';

    public const GREETING = 'greeting';

    public const OUT_OF_HOURS = 'out_of_hours';

    public const FALLBACK = 'fallback';

    public const REPLY = 'reply';

    public const ARTICLE = 'article';

    public const HANDOFF = 'handoff';

    public const NONE = 'none';

    protected $attributes = [
        'trigger' => self::KEYWORD,
        'action' => self::REPLY,
        'max_attempts' => 2,
        'priority' => 100,
        'is_enabled' => true,
        'fired_count' => 0,
        'handoff_count' => 0,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'inbox_channel_id', 'name',
        'trigger', 'keywords', 'action', 'reply_body', 'kb_article_id',
        'max_attempts', 'priority', 'is_enabled', 'fired_count', 'handoff_count',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'max_attempts' => 'integer',
            'priority' => 'integer',
            'fired_count' => 'integer',
            'handoff_count' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(KbArticle::class, 'kb_article_id');
    }

    /**
     * Whether this rule recognises what somebody typed.
     *
     * Whole words only. A rule keyed on "cod" that fires on "according to your
     * website" is the kind of thing that makes a bot embarrassing, and
     * substring matching does exactly that.
     */
    public function matches(string $text): bool
    {
        $keywords = array_filter(array_map('trim', explode(',', (string) $this->keywords)));

        if ($keywords === []) {
            return false;
        }

        $haystack = ' '.strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)).' ';

        foreach ($keywords as $keyword) {
            $needle = strtolower(trim($keyword));

            if ($needle !== '' && str_contains($haystack, ' '.$needle.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * How often it hands off rather than answering.
     *
     * The number that says a rule is not working. A rule firing constantly and
     * handing off every time is worse than no rule — it delays every one of
     * those customers by a round trip.
     */
    public function handoffRate(): ?float
    {
        return $this->fired_count < 5
            ? null
            : round($this->handoff_count / $this->fired_count * 100, 1);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_enabled', true)->orderBy('priority');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'action' => $this->action,
            'fired_count' => $this->fired_count,
            'handoff_rate' => $this->handoffRate(),
            'is_enabled' => $this->is_enabled,
        ];
    }
}
