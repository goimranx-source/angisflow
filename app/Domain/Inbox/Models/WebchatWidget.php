<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat widget on somebody's website.
 *
 * The key is public and always will be. What keeps it from being useful to a
 * stranger is the origin allowlist, and the fact that it can only ever start a
 * conversation — never read one.
 */
class WebchatWidget extends Model
{
    use BelongsToBusiness, HasPublicId;

    protected $attributes = [
        'title' => 'Chat with us',
        'colour' => '#0891b2',
        'position' => 'right',
        'require_name' => false,
        'require_email' => false,
        'require_phone' => false,
        'is_enabled' => true,
    ];

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'inbox_channel_id', 'name', 'widget_key',
        'allowed_origins', 'title', 'greeting', 'away_message', 'colour', 'position',
        'avatar_url', 'office_hours', 'timezone',
        'require_name', 'require_email', 'require_phone', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'office_hours' => 'array',
            'require_name' => 'boolean',
            'require_email' => 'boolean',
            'require_phone' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WebchatSession::class);
    }

    /**
     * Whether a page may embed this widget.
     *
     * An empty list allows anything, which is right for somebody still setting
     * it up and wrong for anybody live. Compared on host only: a path or a port
     * in an allowlist entry is somebody's mistake, and matching the whole
     * string would silently block their own site.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = $this->allowed_origins ?? [];

        if ($allowed === []) {
            return true;
        }

        if ($origin === null || $origin === '') {
            return false;
        }

        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

        foreach ($allowed as $entry) {
            $entryHost = strtolower((string) (parse_url($entry, PHP_URL_HOST) ?: trim($entry, '/ ')));

            if ($entryHost === '') {
                continue;
            }

            // A leading dot means "and every subdomain", which is what people
            // mean when they type .example.com.
            if (str_starts_with($entryHost, '.')) {
                if ($host === ltrim($entryHost, '.') || str_ends_with($host, $entryHost)) {
                    return true;
                }

                continue;
            }

            if ($host === $entryHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether somebody is likely to answer right now.
     *
     * Being honest about this is worth more than appearing always-on: a visitor
     * told "we reply by 9am" waits, and one promised a live answer that never
     * comes leaves.
     */
    public function isWithinOfficeHours(?Carbon $at = null): bool
    {
        $hours = $this->office_hours ?? [];

        if ($hours === []) {
            return true;
        }

        $at = ($at ?? now())->copy()->setTimezone($this->timezone ?? config('app.timezone'));
        $today = $hours[strtolower($at->format('D'))] ?? null;

        if ($today === null || ($today['closed'] ?? false)) {
            return false;
        }

        $now = $at->format('H:i');

        return $now >= ($today['from'] ?? '00:00') && $now <= ($today['to'] ?? '23:59');
    }

    /** What the widget shows before anybody has typed anything. */
    public function openingMessage(): ?string
    {
        return $this->isWithinOfficeHours()
            ? $this->greeting
            : ($this->away_message ?? $this->greeting);
    }

    /**
     * Only what is safe to hand to a page anybody can view source on.
     *
     * Deliberately separate from toPayload(): the settings screen shows the
     * allowlist and the key, and neither belongs in a response served to the
     * open internet.
     *
     * @return array<string, mixed>
     */
    public function toPublicPayload(): array
    {
        return [
            'title' => $this->title,
            'greeting' => $this->openingMessage(),
            'colour' => $this->colour,
            'position' => $this->position,
            'avatar_url' => $this->avatar_url,
            'is_online' => $this->isWithinOfficeHours(),
            'require' => array_values(array_filter([
                $this->require_name ? 'name' : null,
                $this->require_email ? 'email' : null,
                $this->require_phone ? 'phone' : null,
            ])),
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'widget_key' => $this->widget_key,
            'allowed_origins' => $this->allowed_origins ?? [],
            'is_enabled' => $this->is_enabled,
            ...$this->toPublicPayload(),
        ];
    }
}
