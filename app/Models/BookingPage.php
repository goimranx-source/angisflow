<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Booking Page Model
 *
 * Represents a public booking interface for service businesses.
 * Allows customers to schedule appointments through a branded page.
 */
class BookingPage extends Model
{
    use BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'title',
        'description',
        'service_ids',
        'resource_ids',
        'booking_window_days',
        'min_advance_hours',
        'operating_hours',
        'require_account',
        'require_phone',
        'require_email',
        'custom_fields',
        'theme_template',
        'theme_config',
        'branding',
        'send_confirmations',
        'send_reminders',
        'notification_settings',
        'enable_chat_widget',
        'chat_widget_id',
        'analytics_config',
        'is_active',
        'published_at',
    ];

    protected $casts = [
        'service_ids' => 'array',
        'resource_ids' => 'array',
        'operating_hours' => 'array',
        'require_account' => 'boolean',
        'require_phone' => 'boolean',
        'require_email' => 'boolean',
        'custom_fields' => 'array',
        'theme_config' => 'array',
        'branding' => 'array',
        'send_confirmations' => 'boolean',
        'send_reminders' => 'boolean',
        'notification_settings' => 'array',
        'enable_chat_widget' => 'boolean',
        'analytics_config' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Bookings created through this page
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'booking_page_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Get the full URL for this booking page
     */
    public function getUrl(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        return "{$baseUrl}/book/{$this->slug}";
    }

    /**
     * Get available services for booking
     */
    public function getAvailableServices()
    {
        if (empty($this->service_ids)) {
            return collect();
        }

        return BookingService::where('business_id', $this->business_id)
            ->whereIn('public_id', $this->service_ids)
            ->where('is_active', true)
            ->with(['category', 'resources'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get available resources if specified
     */
    public function getAvailableResources()
    {
        if (empty($this->resource_ids)) {
            return collect();
        }

        return BookingResource::where('business_id', $this->business_id)
            ->whereIn('public_id', $this->resource_ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get available time slots for a service on a date
     */
    public function getAvailableTimeSlots(string $serviceId, Carbon $date): array
    {
        $service = BookingService::where('business_id', $this->business_id)
            ->where('public_id', $serviceId)
            ->firstOrFail();

        // Check if date is within booking window
        $maxDate = now()->addDays($this->booking_window_days);
        if ($date->isAfter($maxDate)) {
            return [];
        }

        // Check if date meets minimum advance notice
        $minDateTime = now()->addHours($this->min_advance_hours);
        if ($date->isBefore($minDateTime->startOfDay())) {
            return [];
        }

        // Get operating hours for the day of week
        $dayOfWeek = strtolower($date->format('l'));
        $operatingHours = $this->operating_hours[$dayOfWeek] ?? null;

        if (!$operatingHours || !$operatingHours['enabled']) {
            return []; // Closed on this day
        }

        // Generate time slots based on service duration
        $slots = [];
        $startTime = Carbon::parse($date->format('Y-m-d') . ' ' . $operatingHours['start']);
        $endTime = Carbon::parse($date->format('Y-m-d') . ' ' . $operatingHours['end']);
        $duration = $service->duration_minutes;

        $current = $startTime->copy();
        while ($current->copy()->addMinutes($duration)->lte($endTime)) {
            // Check if slot meets minimum advance notice
            if ($current->gte($minDateTime)) {
                $slots[] = [
                    'time' => $current->format('H:i'),
                    'datetime' => $current->toIso8601String(),
                    'available' => $this->isTimeSlotAvailable($serviceId, $current),
                ];
            }
            
            $current->addMinutes($duration);
        }

        return $slots;
    }

    /**
     * Check if a specific time slot is available
     */
    private function isTimeSlotAvailable(string $serviceId, Carbon $datetime): bool
    {
        $service = BookingService::where('business_id', $this->business_id)
            ->where('public_id', $serviceId)
            ->first();

        if (!$service) {
            return false;
        }

        // Check for existing bookings at this time
        $existingBookings = Booking::where('business_id', $this->business_id)
            ->where('service_id', $service->id)
            ->where('scheduled_start', '<=', $datetime)
            ->where('scheduled_end', '>', $datetime)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->count();

        // Check service capacity
        return $existingBookings < ($service->max_concurrent_bookings ?? 1);
    }

    /**
     * Get theme configuration with defaults
     */
    public function getThemeConfig(): array
    {
        $defaults = [
            'primary_color' => '#3b82f6',
            'secondary_color' => '#64748b',
            'font_family' => 'Inter',
            'show_duration' => true,
            'show_price' => true,
            'show_description' => true,
            'calendar_view' => 'month',
            'time_format' => '12', // 12 or 24 hour
        ];

        return array_merge($defaults, $this->theme_config ?? []);
    }

    /**
     * Get branding information
     */
    public function getBranding(): array
    {
        $defaults = [
            'logo_url' => null,
            'business_name' => $this->business->name ?? 'Business',
            'tagline' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
        ];

        return array_merge($defaults, $this->branding ?? []);
    }

    /**
     * Get custom form fields
     */
    public function getCustomFields(): array
    {
        return $this->custom_fields ?? [];
    }

    /**
     * Get operating hours with defaults
     */
    public function getOperatingHours(): array
    {
        $defaults = [
            'monday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            'tuesday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            'wednesday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            'thursday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            'friday' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
            'saturday' => ['enabled' => false, 'start' => '09:00', 'end' => '17:00'],
            'sunday' => ['enabled' => false, 'start' => '09:00', 'end' => '17:00'],
        ];

        return array_merge($defaults, $this->operating_hours ?? []);
    }

    /**
     * Validate booking request data
     */
    public function validateBookingRequest(array $data): array
    {
        $errors = [];

        // Check required fields
        if ($this->require_email && empty($data['customer_email'])) {
            $errors[] = 'Email address is required';
        }

        if ($this->require_phone && empty($data['customer_phone'])) {
            $errors[] = 'Phone number is required';
        }

        if ($this->require_account && empty($data['customer_id'])) {
            $errors[] = 'Customer account is required';
        }

        // Validate custom fields
        foreach ($this->getCustomFields() as $field) {
            if ($field['required'] && empty($data['custom_fields'][$field['key']])) {
                $errors[] = ($field['label'] ?? $field['key']) . ' is required';
            }
        }

        // Validate service exists and is available
        if (isset($data['service_id'])) {
            if (!in_array($data['service_id'], $this->service_ids)) {
                $errors[] = 'Selected service is not available';
            }
        }

        // Validate booking time is in the future and within window
        if (isset($data['scheduled_start'])) {
            $bookingTime = Carbon::parse($data['scheduled_start']);
            $minTime = now()->addHours($this->min_advance_hours);
            $maxTime = now()->addDays($this->booking_window_days);

            if ($bookingTime->isBefore($minTime)) {
                $errors[] = "Bookings require at least {$this->min_advance_hours} hours notice";
            }

            if ($bookingTime->isAfter($maxTime)) {
                $errors[] = "Bookings can only be made {$this->booking_window_days} days in advance";
            }

            // Check operating hours
            $dayOfWeek = strtolower($bookingTime->format('l'));
            $operatingHours = $this->operating_hours[$dayOfWeek] ?? null;
            
            if (!$operatingHours || !$operatingHours['enabled']) {
                $errors[] = 'Bookings are not available on ' . $bookingTime->format('l');
            }
        }

        return $errors;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to active booking pages only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to published pages
     */
    public function scopePublished($query)
    {
        return $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('published_at')
                          ->orWhere('published_at', '<=', now());
                    });
    }

    /**
     * Scope by slug
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}