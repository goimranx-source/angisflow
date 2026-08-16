<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bookable service offering (haircut, massage, meeting room, etc.)
 *
 * Services define what customers can book, how long it takes,
 * how much it costs, and what resources are required.
 */
class BookingService extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id', 
        'public_id',
        'name',
        'description',
        'category',
        'duration_minutes',
        'buffer_minutes',
        'is_active',
        'pricing_type',
        'base_price_minor',
        'currency',
        'max_participants',
        'advance_booking_hours',
        'max_advance_days',
        'allow_cancellation',
        'cancellation_hours',
        'required_resource_types',
        'preferred_employee_positions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_cancellation' => 'boolean',
        'required_resource_types' => 'array',
        'preferred_employee_positions' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'service_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(BookingTemplate::class, 'service_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get the service price as a Money object
     */
    public function getBasePrice(): Money
    {
        return new Money($this->base_price_minor, $this->currency);
    }

    /**
     * Get the total duration including buffer time
     */
    public function getTotalDurationMinutes(): int
    {
        return $this->duration_minutes + $this->buffer_minutes;
    }

    /**
     * Check if advance booking time requirement is met
     */
    public function canBookForTime(\DateTimeInterface $scheduledTime): bool
    {
        $minTime = now()->addHours($this->advance_booking_hours);
        return $scheduledTime >= $minTime;
    }

    /**
     * Check if booking is within maximum advance booking window
     */
    public function isWithinAdvanceBookingWindow(\DateTimeInterface $scheduledTime): bool
    {
        $maxTime = now()->addDays($this->max_advance_days);
        return $scheduledTime <= $maxTime;
    }

    /**
     * Check if cancellation is allowed for a given booking time
     */
    public function canCancelBooking(\DateTimeInterface $scheduledTime): bool
    {
        if (!$this->allow_cancellation) {
            return false;
        }

        $cutoffTime = (new \DateTime($scheduledTime->format('Y-m-d H:i:s')))
            ->modify("-{$this->cancellation_hours} hours");
        
        return now() <= $cutoffTime;
    }

    /**
     * Get pricing display text
     */
    public function getPricingDisplay(): string
    {
        $basePrice = $this->getBasePrice();
        
        return match($this->pricing_type) {
            'fixed' => $basePrice->toDecimalString() . ' ' . $this->currency,
            'hourly' => $basePrice->toDecimalString() . '/' . __('hour'),
            'per_person' => $basePrice->toDecimalString() . '/' . __('person'),
            default => $basePrice->toDecimalString(),
        };
    }

    /**
     * Calculate price for given participants and duration
     */
    public function calculatePrice(int $participants = 1, ?int $durationMinutes = null): Money
    {
        $duration = $durationMinutes ?? $this->duration_minutes;
        $basePrice = $this->getBasePrice();

        return match($this->pricing_type) {
            'fixed' => $basePrice,
            'hourly' => $basePrice->times($duration / 60),
            'per_person' => $basePrice->times($participants),
            default => $basePrice,
        };
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBookableNow($query)
    {
        return $query->where('is_active', true)
                    ->where('advance_booking_hours', '<=', 24); // Reasonable default
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Get compatible resources for this service
     */
    public function getCompatibleResources(): array
    {
        if (empty($this->required_resource_types)) {
            return BookingResource::where('business_id', $this->business_id)
                                 ->where('is_active', true)
                                 ->get()
                                 ->toArray();
        }

        return BookingResource::where('business_id', $this->business_id)
                             ->where('is_active', true)
                             ->whereIn('resource_type', $this->required_resource_types)
                             ->get()
                             ->toArray();
    }

    /**
     * Validate service constraints for booking request
     */
    public function validateBookingRequest(array $bookingData): array
    {
        $errors = [];
        
        // Check participant limit
        $participants = $bookingData['participant_count'] ?? 1;
        if ($participants > $this->max_participants) {
            $errors[] = "Maximum {$this->max_participants} participants allowed";
        }
        
        // Check advance booking requirements
        if (isset($bookingData['scheduled_start'])) {
            $scheduledTime = new \DateTime($bookingData['scheduled_start']);
            
            if (!$this->canBookForTime($scheduledTime)) {
                $errors[] = "Requires {$this->advance_booking_hours} hours advance notice";
            }
            
            if (!$this->isWithinAdvanceBookingWindow($scheduledTime)) {
                $errors[] = "Cannot book more than {$this->max_advance_days} days in advance";
            }
        }

        return $errors;
    }
}