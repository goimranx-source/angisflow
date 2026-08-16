<?php

namespace App\Models;

use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * A booking/appointment record
 *
 * Represents a scheduled appointment between a customer and business,
 * involving specific services and resources at a scheduled time.
 */
class Booking extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'booking_number',
        'status',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_notes',
        'service_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'participant_count',
        'quoted_price_minor',
        'currency',
        'final_price_minor',
        'price_notes',
        'recurrence_pattern',
        'recurrence_config',
        'recurrence_until',
        'parent_booking_id',
        'confirmed_at',
        'confirmed_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'special_instructions',
        'custom_fields',
        'booking_source',
        'created_by',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'recurrence_until' => 'date',
        'recurrence_config' => 'array',
        'custom_fields' => 'array',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No Show',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'service_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'parent_booking_id');
    }

    public function childBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'parent_booking_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function resourceAssignments(): HasMany
    {
        return $this->hasMany(BookingResourceAssignment::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(BookingResource::class, 'booking_resource_assignments', 'booking_id', 'resource_id')
                    ->withPivot(['role', 'assigned_start', 'assigned_end', 'resource_notes'])
                    ->withTimestamps();
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get the quoted price as a Money object
     */
    public function getQuotedPrice(): Money
    {
        return new Money($this->quoted_price_minor, $this->currency);
    }

    /**
     * Get the final price as a Money object
     */
    public function getFinalPrice(): ?Money
    {
        return $this->final_price_minor 
            ? new Money($this->final_price_minor, $this->currency)
            : null;
    }

    /**
     * Get the effective price (final or quoted)
     */
    public function getEffectivePrice(): Money
    {
        return $this->getFinalPrice() ?? $this->getQuotedPrice();
    }

    /**
     * Get duration in minutes
     */
    public function getDurationMinutes(): int
    {
        return $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }

    /**
     * Get actual duration if booking was completed
     */
    public function getActualDurationMinutes(): ?int
    {
        if (!$this->actual_start || !$this->actual_end) {
            return null;
        }

        return $this->actual_start->diffInMinutes($this->actual_end);
    }

    /**
     * Check if booking is in the past
     */
    public function isPast(): bool
    {
        return $this->scheduled_end < now();
    }

    /**
     * Check if booking is today
     */
    public function isToday(): bool
    {
        return $this->scheduled_start->isToday();
    }

    /**
     * Check if booking is upcoming (future and confirmed)
     */
    public function isUpcoming(): bool
    {
        return in_array($this->status, ['confirmed', 'pending']) && 
               $this->scheduled_start > now();
    }

    /**
     * Check if booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        if (!in_array($this->status, ['pending', 'confirmed'])) {
            return false;
        }

        // Check service cancellation rules
        return $this->service->canCancelBooking($this->scheduled_start);
    }

    /**
     * Check if booking can be rescheduled
     */
    public function canBeRescheduled(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']) && 
               $this->scheduled_start > now();
    }

    /**
     * Check if booking is part of a recurring series
     */
    public function isRecurring(): bool
    {
        return !empty($this->recurrence_pattern);
    }

    /**
     * Check if this is the parent booking of a recurring series
     */
    public function isRecurringParent(): bool
    {
        return $this->isRecurring() && is_null($this->parent_booking_id);
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', ['confirmed', 'pending'])
                     ->where('scheduled_start', '>', now());
    }

    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_start', now()->toDateString());
    }

    public function scopeBetween($query, \DateTimeInterface $start, \DateTimeInterface $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('scheduled_start', [$start, $end])
              ->orWhereBetween('scheduled_end', [$start, $end])
              ->orWhere(function ($q2) use ($start, $end) {
                  $q2->where('scheduled_start', '<=', $start)
                     ->where('scheduled_end', '>=', $end);
              });
        });
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeRecurring($query)
    {
        return $query->whereNotNull('recurrence_pattern');
    }

    public function scopeRecurringParents($query)
    {
        return $query->whereNotNull('recurrence_pattern')
                     ->whereNull('parent_booking_id');
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Generate unique booking number
     */
    public static function generateBookingNumber(): string
    {
        $prefix = 'BK-' . date('Y') . '-';
        $lastBooking = static::where('booking_number', 'like', $prefix . '%')
                            ->orderBy('booking_number', 'desc')
                            ->first();

        if (!$lastBooking) {
            return $prefix . '000001';
        }

        $lastNumber = (int) substr($lastBooking->booking_number, strlen($prefix));
        return $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Confirm the booking
     */
    public function confirm(?int $confirmedByUserId = null): self
    {
        if ($this->status !== 'pending') {
            throw new \InvalidArgumentException("Can only confirm pending bookings");
        }

        $this->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $confirmedByUserId,
        ]);

        $this->recordStatusChange('pending', 'confirmed', 'Booking confirmed', $confirmedByUserId);

        return $this->fresh();
    }

    /**
     * Cancel the booking
     */
    public function cancel(?int $cancelledByUserId = null, ?string $reason = null): self
    {
        if (!$this->canBeCancelled()) {
            throw new \InvalidArgumentException("Booking cannot be cancelled");
        }

        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $cancelledByUserId,
            'cancellation_reason' => $reason,
        ]);

        $this->recordStatusChange($this->getOriginal('status'), 'cancelled', $reason, $cancelledByUserId);

        return $this->fresh();
    }

    /**
     * Start the booking (mark in progress)
     */
    public function start(?int $startedByUserId = null): self
    {
        if ($this->status !== 'confirmed') {
            throw new \InvalidArgumentException("Can only start confirmed bookings");
        }

        $this->update([
            'status' => 'in_progress',
            'actual_start' => now(),
        ]);

        $this->recordStatusChange('confirmed', 'in_progress', 'Booking started', $startedByUserId);

        return $this->fresh();
    }

    /**
     * Complete the booking
     */
    public function complete(?int $completedByUserId = null, ?int $finalPriceMinor = null): self
    {
        if (!in_array($this->status, ['confirmed', 'in_progress'])) {
            throw new \InvalidArgumentException("Can only complete confirmed or in-progress bookings");
        }

        $updateData = [
            'status' => 'completed',
            'actual_end' => now(),
        ];

        if (!$this->actual_start) {
            $updateData['actual_start'] = $this->scheduled_start;
        }

        if ($finalPriceMinor !== null) {
            $updateData['final_price_minor'] = $finalPriceMinor;
        }

        $this->update($updateData);

        $this->recordStatusChange($this->getOriginal('status'), 'completed', 'Booking completed', $completedByUserId);

        return $this->fresh();
    }

    /**
     * Mark booking as no-show
     */
    public function markNoShow(?int $markedByUserId = null, ?string $reason = null): self
    {
        if (!in_array($this->status, ['confirmed', 'in_progress'])) {
            throw new \InvalidArgumentException("Can only mark confirmed or in-progress bookings as no-show");
        }

        $this->update([
            'status' => 'no_show',
        ]);

        $this->recordStatusChange($this->getOriginal('status'), 'no_show', $reason ?? 'Customer did not show up', $markedByUserId);

        return $this->fresh();
    }

    /**
     * Reschedule the booking to a new time
     */
    public function reschedule(\DateTimeInterface $newStart, \DateTimeInterface $newEnd, ?int $rescheduledByUserId = null): self
    {
        if (!$this->canBeRescheduled()) {
            throw new \InvalidArgumentException("Booking cannot be rescheduled");
        }

        $oldStart = $this->scheduled_start;
        $oldEnd = $this->scheduled_end;

        $this->update([
            'scheduled_start' => $newStart,
            'scheduled_end' => $newEnd,
        ]);

        $this->recordStatusChange(
            $this->status, 
            $this->status, 
            "Rescheduled from {$oldStart->format('M j, Y g:i A')} to {$newStart->format('M j, Y g:i A')}", 
            $rescheduledByUserId
        );

        // Update resource assignments to new time
        $this->resourceAssignments()->update([
            'assigned_start' => $newStart,
            'assigned_end' => $newEnd,
        ]);

        return $this->fresh();
    }

    /**
     * Record status change in history
     */
    private function recordStatusChange(?string $fromStatus, string $toStatus, ?string $reason = null, ?int $changedByUserId = null): void
    {
        BookingStatusHistory::create([
            'account_id' => $this->account_id,
            'business_id' => $this->business_id,
            'booking_id' => $this->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'changed_by' => $changedByUserId,
            'changed_at' => now(),
        ]);
    }

    /**
     * Create recurring bookings based on pattern
     */
    public function createRecurringBookings(): array
    {
        if (!$this->isRecurringParent() || !$this->recurrence_until) {
            return [];
        }

        $createdBookings = [];
        $current = $this->scheduled_start->copy();
        $until = $this->recurrence_until;

        while ($current <= $until) {
            $current = $this->getNextRecurrenceDate($current);
            
            if ($current && $current <= $until) {
                $endTime = $current->copy()->addMinutes($this->getDurationMinutes());
                
                $recurringBooking = static::create([
                    'account_id' => $this->account_id,
                    'business_id' => $this->business_id,
                    'booking_number' => static::generateBookingNumber(),
                    'status' => 'confirmed',
                    'customer_id' => $this->customer_id,
                    'customer_name' => $this->customer_name,
                    'customer_email' => $this->customer_email,
                    'customer_phone' => $this->customer_phone,
                    'service_id' => $this->service_id,
                    'scheduled_start' => $current,
                    'scheduled_end' => $endTime,
                    'participant_count' => $this->participant_count,
                    'quoted_price_minor' => $this->quoted_price_minor,
                    'currency' => $this->currency,
                    'parent_booking_id' => $this->id,
                    'special_instructions' => $this->special_instructions,
                    'custom_fields' => $this->custom_fields,
                    'booking_source' => $this->booking_source,
                    'created_by' => $this->created_by,
                ]);

                // Copy resource assignments
                foreach ($this->resourceAssignments as $assignment) {
                    BookingResourceAssignment::create([
                        'account_id' => $this->account_id,
                        'business_id' => $this->business_id,
                        'booking_id' => $recurringBooking->id,
                        'resource_id' => $assignment->resource_id,
                        'role' => $assignment->role,
                        'assigned_start' => $current,
                        'assigned_end' => $endTime,
                        'resource_notes' => $assignment->resource_notes,
                    ]);
                }

                $createdBookings[] = $recurringBooking;
            }
        }

        return $createdBookings;
    }

    /**
     * Get next recurrence date based on pattern
     */
    private function getNextRecurrenceDate(\DateTimeInterface $from): ?\DateTimeInterface
    {
        $config = $this->recurrence_config ?? [];

        return match($this->recurrence_pattern) {
            'weekly' => $from->copy()->addWeeks($config['every'] ?? 1),
            'monthly' => $from->copy()->addMonths($config['every'] ?? 1),
            'yearly' => $from->copy()->addYears($config['every'] ?? 1),
            default => null,
        };
    }
}