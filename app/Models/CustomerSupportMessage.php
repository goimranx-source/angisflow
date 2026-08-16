<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer Support Message Model
 *
 * Represents individual messages within a support ticket conversation.
 */
class CustomerSupportMessage extends Model
{
    use HasPublicId;

    protected $fillable = [
        'ticket_id',
        'message',
        'attachments',
        'is_internal',
        'author_type',
        'author_id',
        'author_name',
        'author_email',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_internal' => 'boolean',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The support ticket this message belongs to
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(CustomerSupportTicket::class, 'ticket_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Check if message is from customer
     */
    public function isFromCustomer(): bool
    {
        return $this->author_type === 'customer';
    }

    /**
     * Check if message is from staff
     */
    public function isFromStaff(): bool
    {
        return $this->author_type === 'staff';
    }

    /**
     * Check if message has attachments
     */
    public function hasAttachments(): bool
    {
        return !empty($this->attachments);
    }

    /**
     * Get attachment count
     */
    public function getAttachmentCount(): int
    {
        return count($this->attachments ?? []);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Get message preview (first 100 characters)
     */
    public function getPreview(int $length = 100): string
    {
        $text = strip_tags($this->message);
        return strlen($text) > $length 
            ? substr($text, 0, $length) . '...'
            : $text;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to customer messages
     */
    public function scopeFromCustomer($query)
    {
        return $query->where('author_type', 'customer');
    }

    /**
     * Scope to staff messages
     */
    public function scopeFromStaff($query)
    {
        return $query->where('author_type', 'staff');
    }

    /**
     * Scope to public messages (not internal)
     */
    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    /**
     * Scope to internal messages
     */
    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    /**
     * Scope to unread messages
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}