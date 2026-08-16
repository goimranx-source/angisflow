<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Campaign Delivery Model
 *
 * Tracks individual message deliveries for marketing campaigns
 * with detailed engagement and conversion metrics.
 */
class CampaignDelivery extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'campaign_id',
        'customer_id',
        'recipient_email',
        'recipient_phone',
        'channel',
        'variant',
        'message_content',
        'personalization_data',
        'status',
        'failure_reason',
        'external_id',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'converted_at',
        'unsubscribed_at',
        'attributed_revenue',
        'attributed_orders',
        'conversion_data',
    ];

    protected $casts = [
        'message_content' => 'array',
        'personalization_data' => 'array',
        'conversion_data' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'converted_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'attributed_revenue' => 'integer',
        'attributed_orders' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Parent marketing campaign
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    /**
     * Target customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ── Status Management ────────────────────────────────────────────────────

    /**
     * Check if message was successfully delivered
     */
    public function isDelivered(): bool
    {
        return in_array($this->status, ['delivered', 'opened', 'clicked', 'converted']);
    }

    /**
     * Check if message was opened
     */
    public function isOpened(): bool
    {
        return in_array($this->status, ['opened', 'clicked', 'converted']);
    }

    /**
     * Check if message was clicked
     */
    public function isClicked(): bool
    {
        return in_array($this->status, ['clicked', 'converted']);
    }

    /**
     * Check if message led to conversion
     */
    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    /**
     * Check if delivery failed
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'bounced']);
    }

    /**
     * Check if customer unsubscribed
     */
    public function isUnsubscribed(): bool
    {
        return $this->status === 'unsubscribed';
    }

    // ── Status Updates ───────────────────────────────────────────────────────

    /**
     * Mark as sent
     */
    public function markAsSent(string $externalId = null): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'external_id' => $externalId,
        ]);
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(): void
    {
        if (!in_array($this->status, ['sent', 'sending'])) {
            return;
        }

        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        // Update campaign stats
        $this->campaign->increment('delivered_count');
    }

    /**
     * Mark as opened
     */
    public function markAsOpened(): void
    {
        if (!$this->isDelivered() || $this->isOpened()) {
            return;
        }

        $this->update([
            'status' => 'opened',
            'opened_at' => now(),
        ]);

        // Update campaign stats
        $this->campaign->increment('opened_count');
    }

    /**
     * Mark as clicked
     */
    public function markAsClicked(): void
    {
        if (!$this->isDelivered() || $this->isClicked()) {
            return;
        }

        $this->update([
            'status' => 'clicked',
            'clicked_at' => now(),
        ]);

        // Update campaign stats
        $this->campaign->increment('clicked_count');
    }

    /**
     * Mark as converted
     */
    public function markAsConverted(int $revenue = 0, array $conversionData = []): void
    {
        if (!$this->isDelivered() || $this->isConverted()) {
            return;
        }

        $this->update([
            'status' => 'converted',
            'converted_at' => now(),
            'attributed_revenue' => $revenue,
            'attributed_orders' => $this->attributed_orders + 1,
            'conversion_data' => array_merge($this->conversion_data ?? [], $conversionData),
        ]);

        // Update campaign stats
        $this->campaign->increment('converted_count');
        $this->campaign->increment('revenue_generated', $revenue);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Mark as bounced
     */
    public function markAsBounced(string $reason = null): void
    {
        $this->update([
            'status' => 'bounced',
            'failure_reason' => $reason,
        ]);

        // Update campaign stats
        $this->campaign->increment('bounced_count');
    }

    /**
     * Mark as unsubscribed
     */
    public function markAsUnsubscribed(): void
    {
        $this->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        // Update campaign stats
        $this->campaign->increment('unsubscribed_count');
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Get engagement timeline
     */
    public function getEngagementTimeline(): array
    {
        $timeline = [];

        if ($this->sent_at) {
            $timeline[] = [
                'event' => 'sent',
                'timestamp' => $this->sent_at,
            ];
        }

        if ($this->delivered_at) {
            $timeline[] = [
                'event' => 'delivered',
                'timestamp' => $this->delivered_at,
            ];
        }

        if ($this->opened_at) {
            $timeline[] = [
                'event' => 'opened',
                'timestamp' => $this->opened_at,
            ];
        }

        if ($this->clicked_at) {
            $timeline[] = [
                'event' => 'clicked',
                'timestamp' => $this->clicked_at,
            ];
        }

        if ($this->converted_at) {
            $timeline[] = [
                'event' => 'converted',
                'timestamp' => $this->converted_at,
            ];
        }

        if ($this->unsubscribed_at) {
            $timeline[] = [
                'event' => 'unsubscribed',
                'timestamp' => $this->unsubscribed_at,
            ];
        }

        return $timeline;
    }

    /**
     * Calculate time to action metrics
     */
    public function getTimeToActionMetrics(): array
    {
        $metrics = [];

        if ($this->sent_at && $this->delivered_at) {
            $metrics['time_to_delivery'] = $this->sent_at->diffInSeconds($this->delivered_at);
        }

        if ($this->delivered_at && $this->opened_at) {
            $metrics['time_to_open'] = $this->delivered_at->diffInSeconds($this->opened_at);
        }

        if ($this->opened_at && $this->clicked_at) {
            $metrics['time_to_click'] = $this->opened_at->diffInSeconds($this->clicked_at);
        }

        if ($this->clicked_at && $this->converted_at) {
            $metrics['time_to_conversion'] = $this->clicked_at->diffInSeconds($this->converted_at);
        }

        if ($this->delivered_at && $this->converted_at) {
            $metrics['total_time_to_conversion'] = $this->delivered_at->diffInSeconds($this->converted_at);
        }

        return $metrics;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope to delivered messages
     */
    public function scopeDelivered($query)
    {
        return $query->whereIn('status', ['delivered', 'opened', 'clicked', 'converted']);
    }

    /**
     * Scope to opened messages
     */
    public function scopeOpened($query)
    {
        return $query->whereIn('status', ['opened', 'clicked', 'converted']);
    }

    /**
     * Scope to clicked messages
     */
    public function scopeClicked($query)
    {
        return $query->whereIn('status', ['clicked', 'converted']);
    }

    /**
     * Scope to converted messages
     */
    public function scopeConverted($query)
    {
        return $query->where('status', 'converted');
    }

    /**
     * Scope to failed messages
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'bounced']);
    }

    /**
     * Scope by channel
     */
    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope by variant (for A/B testing)
     */
    public function scopeByVariant($query, string $variant)
    {
        return $query->where('variant', $variant);
    }
}