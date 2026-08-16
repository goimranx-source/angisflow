<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Customer Support Ticket Model
 *
 * Manages customer support requests with full conversation tracking,
 * priority management, and resolution workflows.
 */
class CustomerSupportTicket extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'ticket_number',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'assigned_to',
        'assigned_at',
        'related_order_id',
        'related_booking_id',
        'related_products',
        'resolution_notes',
        'resolved_at',
        'closed_at',
        'resolution_type',
        'satisfaction_rating',
        'satisfaction_feedback',
        'feedback_submitted_at',
    ];

    protected $casts = [
        'related_products' => 'array',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'feedback_submitted_at' => 'datetime',
    ];

    public const CATEGORIES = [
        'order_inquiry' => 'Order Inquiry',
        'product_question' => 'Product Question',
        'billing' => 'Billing Issue',
        'technical' => 'Technical Support',
        'complaint' => 'Complaint',
        'compliment' => 'Compliment',
        'return_request' => 'Return Request',
        'other' => 'Other',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'waiting_customer' => 'Waiting for Customer',
        'waiting_business' => 'Waiting for Business',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public const RESOLUTION_TYPES = [
        'resolved' => 'Resolved',
        'duplicate' => 'Duplicate',
        'not_reproducible' => 'Not Reproducible',
        'working_as_designed' => 'Working as Designed',
        'cancelled' => 'Cancelled',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The customer who created this ticket
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The staff member assigned to this ticket
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Messages in this ticket conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CustomerSupportMessage::class, 'ticket_id');
    }

    /**
     * Related order if applicable
     */
    public function relatedOrder(): ?Order
    {
        if (!$this->related_order_id) {
            return null;
        }

        return Order::where('business_id', $this->business_id)
            ->where('public_id', $this->related_order_id)
            ->first();
    }

    /**
     * Related booking if applicable
     */
    public function relatedBooking(): ?Booking
    {
        if (!$this->related_booking_id) {
            return null;
        }

        return Booking::where('business_id', $this->business_id)
            ->where('public_id', $this->related_booking_id)
            ->first();
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Generate unique ticket number
     */
    public static function generateTicketNumber(): string
    {
        $year = date('Y');
        $sequence = self::whereYear('created_at', $year)->count() + 1;
        
        return sprintf('CS-%d-%04d', $year, $sequence);
    }

    /**
     * Create a new support ticket
     */
    public static function createTicket(array $data): self
    {
        $ticket = self::create(array_merge($data, [
            'ticket_number' => self::generateTicketNumber(),
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
        ]));

        // Add initial message from customer
        $ticket->addMessage([
            'message' => $data['description'],
            'author_type' => 'customer',
            'author_id' => $data['customer_id'],
        ]);

        return $ticket;
    }

    /**
     * Add a message to the ticket
     */
    public function addMessage(array $data): CustomerSupportMessage
    {
        $authorData = $this->getAuthorData($data['author_type'], $data['author_id']);
        
        $message = $this->messages()->create([
            'message' => $data['message'],
            'attachments' => $data['attachments'] ?? null,
            'is_internal' => $data['is_internal'] ?? false,
            'author_type' => $data['author_type'],
            'author_id' => $data['author_id'],
            'author_name' => $authorData['name'],
            'author_email' => $authorData['email'],
        ]);

        // Update ticket status based on message author
        $this->updateStatusAfterMessage($data['author_type']);

        return $message;
    }

    /**
     * Get author data for message
     */
    private function getAuthorData(string $authorType, int $authorId): array
    {
        if ($authorType === 'customer') {
            $customer = Customer::find($authorId);
            return [
                'name' => $customer->name ?? 'Customer',
                'email' => $customer->email ?? null,
            ];
        } else {
            $user = User::find($authorId);
            return [
                'name' => $user->name ?? 'Staff',
                'email' => $user->email ?? null,
            ];
        }
    }

    /**
     * Update ticket status after new message
     */
    private function updateStatusAfterMessage(string $authorType): void
    {
        $newStatus = match ($this->status) {
            'waiting_customer' => $authorType === 'customer' ? 'in_progress' : 'waiting_customer',
            'waiting_business' => $authorType === 'staff' ? 'in_progress' : 'waiting_business',
            'open' => 'in_progress',
            default => $this->status,
        };

        if ($newStatus !== $this->status) {
            $this->update(['status' => $newStatus]);
        }
    }

    /**
     * Assign ticket to staff member
     */
    public function assignTo(int $userId): void
    {
        $this->update([
            'assigned_to' => $userId,
            'assigned_at' => now(),
            'status' => $this->status === 'open' ? 'in_progress' : $this->status,
        ]);
    }

    /**
     * Mark ticket as waiting for customer
     */
    public function waitForCustomer(): void
    {
        $this->update(['status' => 'waiting_customer']);
    }

    /**
     * Mark ticket as waiting for business
     */
    public function waitForBusiness(): void
    {
        $this->update(['status' => 'waiting_business']);
    }

    /**
     * Resolve ticket
     */
    public function resolve(string $resolutionNotes, string $resolutionType = 'resolved'): void
    {
        $this->update([
            'status' => 'resolved',
            'resolution_notes' => $resolutionNotes,
            'resolution_type' => $resolutionType,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Close ticket
     */
    public function close(string $resolutionType = null): void
    {
        $updates = [
            'status' => 'closed',
            'closed_at' => now(),
        ];

        if ($resolutionType) {
            $updates['resolution_type'] = $resolutionType;
        }

        if (!$this->resolved_at && $this->status !== 'resolved') {
            $updates['resolved_at'] = now();
        }

        $this->update($updates);
    }

    /**
     * Submit customer satisfaction feedback
     */
    public function submitFeedback(int $rating, string $feedback = null): void
    {
        $this->update([
            'satisfaction_rating' => $rating,
            'satisfaction_feedback' => $feedback,
            'feedback_submitted_at' => now(),
        ]);
    }

    /**
     * Get category label
     */
    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Get priority label
     */
    public function getPriorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Get resolution type label
     */
    public function getResolutionTypeLabel(): ?string
    {
        return $this->resolution_type ? (self::RESOLUTION_TYPES[$this->resolution_type] ?? $this->resolution_type) : null;
    }

    /**
     * Check if ticket is open (can receive new messages)
     */
    public function isOpen(): bool
    {
        return !in_array($this->status, ['resolved', 'closed']);
    }

    /**
     * Check if ticket is resolved
     */
    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'closed']);
    }

    /**
     * Get response time in hours
     */
    public function getResponseTime(): ?float
    {
        $firstStaffMessage = $this->messages()
            ->where('author_type', 'staff')
            ->where('is_internal', false)
            ->oldest()
            ->first();

        if (!$firstStaffMessage) {
            return null;
        }

        return $this->created_at->diffInHours($firstStaffMessage->created_at, true);
    }

    /**
     * Get resolution time in hours
     */
    public function getResolutionTime(): ?float
    {
        if (!$this->resolved_at) {
            return null;
        }

        return $this->created_at->diffInHours($this->resolved_at, true);
    }

    /**
     * Get last message
     */
    public function getLastMessage(): ?CustomerSupportMessage
    {
        return $this->messages()->latest()->first();
    }

    /**
     * Get unread message count for customer
     */
    public function getUnreadCountForCustomer(): int
    {
        return $this->messages()
            ->where('author_type', 'staff')
            ->where('is_internal', false)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Get unread message count for staff
     */
    public function getUnreadCountForStaff(): int
    {
        return $this->messages()
            ->where('author_type', 'customer')
            ->where('is_read', false)
            ->count();
    }

    /**
     * Mark messages as read by customer
     */
    public function markAsReadByCustomer(): void
    {
        $this->messages()
            ->where('author_type', 'staff')
            ->where('is_internal', false)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    /**
     * Mark messages as read by staff
     */
    public function markAsReadByStaff(): void
    {
        $this->messages()
            ->where('author_type', 'customer')
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to open tickets
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress', 'waiting_customer', 'waiting_business']);
    }

    /**
     * Scope to resolved tickets
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'closed']);
    }

    /**
     * Scope by priority
     */
    public function scopeWithPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope by category
     */
    public function scopeWithCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope by assigned user
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Scope unassigned tickets
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }
}