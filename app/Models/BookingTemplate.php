<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booking template for recurring patterns
 *
 * Defines reusable booking configurations that can be used
 * to create recurring bookings or quick booking presets.
 */
class BookingTemplate extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'service_id',
        'default_resources',
        'default_duration_minutes',
        'recurrence_pattern',
        'recurrence_config',
        'template_data',
        'auto_create',
        'create_ahead_days',
    ];

    protected $casts = [
        'default_resources' => 'array',
        'recurrence_config' => 'array',
        'template_data' => 'array',
        'auto_create' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'service_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeAutoCreate($query)
    {
        return $query->where('auto_create', true);
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Create booking from template
     */
    public function createBooking(array $overrides = []): Booking
    {
        $templateData = $this->template_data;
        $bookingData = array_merge($templateData, $overrides);

        // Ensure required fields
        $bookingData['service_id'] = $this->service_id;
        $bookingData['booking_number'] = Booking::generateBookingNumber();
        
        if (!isset($bookingData['scheduled_start'])) {
            throw new \InvalidArgumentException('scheduled_start is required');
        }

        $scheduledStart = new \DateTime($bookingData['scheduled_start']);
        $duration = $bookingData['duration_minutes'] ?? $this->default_duration_minutes ?? $this->service->duration_minutes;
        $bookingData['scheduled_end'] = $scheduledStart->copy()->addMinutes($duration);

        $booking = Booking::create($bookingData);

        // Assign default resources
        if (!empty($this->default_resources)) {
            foreach ($this->default_resources as $resourceId) {
                BookingResourceAssignment::create([
                    'account_id' => $this->account_id,
                    'business_id' => $this->business_id,
                    'booking_id' => $booking->id,
                    'resource_id' => $resourceId,
                    'role' => 'primary',
                    'assigned_start' => $booking->scheduled_start,
                    'assigned_end' => $booking->scheduled_end,
                ]);
            }
        }

        return $booking->fresh();
    }

    /**
     * Get next auto-creation date for this template
     */
    public function getNextAutoCreateDate(): ?\DateTimeInterface
    {
        if (!$this->auto_create) {
            return null;
        }

        // Find the last booking created from this template
        $lastBooking = Booking::where('business_id', $this->business_id)
                             ->where('service_id', $this->service_id)
                             ->whereJsonContains('custom_fields->template_id', $this->id)
                             ->orderBy('scheduled_start', 'desc')
                             ->first();

        $baseDate = $lastBooking ? $lastBooking->scheduled_start : now();
        
        return match($this->recurrence_pattern) {
            'weekly' => $baseDate->copy()->addWeeks($this->recurrence_config['every'] ?? 1),
            'monthly' => $baseDate->copy()->addMonths($this->recurrence_config['every'] ?? 1),
            'yearly' => $baseDate->copy()->addYears($this->recurrence_config['every'] ?? 1),
            default => null,
        };
    }
}