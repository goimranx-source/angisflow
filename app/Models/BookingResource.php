<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * A bookable resource (employee, room, equipment, vehicle)
 *
 * Resources are the "who" or "what" that gets scheduled.
 * Can be polymorphic to link to employees, rooms, equipment, etc.
 */
class BookingResource extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'name',
        'resource_type',
        'resource_id',
        'description',
        'is_active',
        'working_hours',
        'booking_increment_minutes',
        'allow_back_to_back',
        'max_daily_hours',
        'service_ids',
        'tags',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_back_to_back' => 'boolean',
        'working_hours' => 'array',
        'service_ids' => 'array',
        'tags' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    /**
     * Polymorphic relationship to the actual resource (Employee, Room, etc.)
     */
    public function resource(): MorphTo
    {
        return $this->morphTo('resource', 'resource_type', 'resource_id');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(ResourceAvailability::class, 'resource_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BookingResourceAssignment::class, 'resource_id');
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_resource_assignments', 'resource_id', 'booking_id')
                    ->withPivot(['role', 'assigned_start', 'assigned_end', 'resource_notes'])
                    ->withTimestamps();
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Get working hours for a specific day
     */
    public function getWorkingHoursForDay(string $dayOfWeek): array
    {
        $workingHours = $this->working_hours ?? [];
        $dayKey = strtolower($dayOfWeek);
        
        if (!isset($workingHours[$dayKey])) {
            return [];
        }

        $hours = $workingHours[$dayKey];
        if (is_string($hours)) {
            return [$hours]; // Single time range
        }

        return is_array($hours) ? $hours : [];
    }

    /**
     * Check if resource can provide a specific service
     */
    public function canProvideService(int $serviceId): bool
    {
        if (empty($this->service_ids)) {
            return true; // No restrictions means can provide any service
        }

        return in_array($serviceId, $this->service_ids);
    }

    /**
     * Get next available time slot
     */
    public function getNextAvailableSlot(\DateTimeInterface $from, int $durationMinutes): ?\DateTimeInterface
    {
        $startTime = max($from, now());
        
        // Look ahead up to 30 days
        for ($i = 0; $i < 30; $i++) {
            $checkDate = (clone $startTime)->addDays($i);
            $dayOfWeek = $checkDate->format('l'); // Monday, Tuesday, etc.
            
            $workingHours = $this->getWorkingHoursForDay($dayOfWeek);
            if (empty($workingHours)) {
                continue; // Not working this day
            }

            foreach ($workingHours as $timeRange) {
                $availableSlot = $this->findSlotInRange($checkDate, $timeRange, $durationMinutes);
                if ($availableSlot) {
                    return $availableSlot;
                }
            }
        }

        return null;
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeType($query, string $resourceType)
    {
        return $query->where('resource_type', $resourceType);
    }

    public function scopeCanProvideService($query, int $serviceId)
    {
        return $query->where(function ($q) use ($serviceId) {
            $q->whereNull('service_ids')
              ->orWhereJsonContains('service_ids', $serviceId);
        });
    }

    public function scopeWithTags($query, array $tags)
    {
        return $query->where(function ($q) use ($tags) {
            foreach ($tags as $tag) {
                $q->whereJsonContains('tags', $tag);
            }
        });
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Check if resource is available during a specific time period
     */
    public function isAvailableDuring(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        // Check basic working hours
        $dayOfWeek = $start->format('l');
        $workingHours = $this->getWorkingHoursForDay($dayOfWeek);
        
        if (empty($workingHours)) {
            return false; // Not working this day
        }

        // Check if requested time falls within working hours
        $withinWorkingHours = false;
        foreach ($workingHours as $timeRange) {
            if ($this->isTimeWithinRange($start, $end, $timeRange)) {
                $withinWorkingHours = true;
                break;
            }
        }

        if (!$withinWorkingHours) {
            return false;
        }

        // Check for any conflicting bookings
        $conflictingBookings = $this->assignments()
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', ['confirmed', 'in_progress']);
            })
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('assigned_start', [$start, $end])
                  ->orWhereBetween('assigned_end', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->where('assigned_start', '<=', $start)
                         ->where('assigned_end', '>=', $end);
                  });
            })
            ->exists();

        if ($conflictingBookings) {
            return false;
        }

        // Check availability overrides
        $date = $start->format('Y-m-d');
        $unavailableOverrides = $this->availability()
            ->where('date', $date)
            ->where('availability_type', 'unavailable')
            ->where(function ($q) use ($start, $end) {
                $startTime = $start->format('H:i:s');
                $endTime = $end->format('H:i:s');
                
                $q->where(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<=', $startTime)
                       ->where('end_time', '>', $startTime);
                })->orWhere(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<', $endTime)
                       ->where('end_time', '>=', $endTime);
                })->orWhere(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '>=', $startTime)
                       ->where('end_time', '<=', $endTime);
                });
            })
            ->exists();

        return !$unavailableOverrides;
    }

    /**
     * Get all bookings for a specific date range
     */
    public function getBookingsForPeriod(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->assignments()
            ->with('booking.service', 'booking.customer')
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', ['confirmed', 'in_progress', 'completed']);
            })
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('assigned_start', [$start, $end])
                  ->orWhereBetween('assigned_end', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->where('assigned_start', '<=', $start)
                         ->where('assigned_end', '>=', $end);
                  });
            })
            ->orderBy('assigned_start')
            ->get()
            ->map(function ($assignment) {
                return [
                    'booking_id' => $assignment->booking->id,
                    'booking_number' => $assignment->booking->booking_number,
                    'service_name' => $assignment->booking->service->name,
                    'customer_name' => $assignment->booking->customer_name,
                    'start_time' => $assignment->assigned_start,
                    'end_time' => $assignment->assigned_end,
                    'status' => $assignment->booking->status,
                    'role' => $assignment->role,
                ];
            })
            ->toArray();
    }

    /**
     * Calculate total hours booked for a specific date
     */
    public function getTotalBookedHours(\DateTimeInterface $date): float
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $assignments = $this->assignments()
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', ['confirmed', 'in_progress', 'completed']);
            })
            ->where(function ($q) use ($startOfDay, $endOfDay) {
                $q->whereBetween('assigned_start', [$startOfDay, $endOfDay])
                  ->orWhereBetween('assigned_end', [$startOfDay, $endOfDay])
                  ->orWhere(function ($q2) use ($startOfDay, $endOfDay) {
                      $q2->where('assigned_start', '<=', $startOfDay)
                         ->where('assigned_end', '>=', $endOfDay);
                  });
            })
            ->get();

        $totalMinutes = 0;
        foreach ($assignments as $assignment) {
            $start = max($assignment->assigned_start, $startOfDay);
            $end = min($assignment->assigned_end, $endOfDay);
            $totalMinutes += $start->diffInMinutes($end);
        }

        return $totalMinutes / 60;
    }

    // ── Private Methods ────────────────────────────────────────────────────

    private function isTimeWithinRange(\DateTimeInterface $start, \DateTimeInterface $end, string $timeRange): bool
    {
        if (!str_contains($timeRange, '-')) {
            return false;
        }

        [$rangeStart, $rangeEnd] = explode('-', $timeRange);
        
        $dayStart = Carbon::createFromFormat('Y-m-d H:i:s', $start->format('Y-m-d') . ' ' . trim($rangeStart) . ':00');
        $dayEnd = Carbon::createFromFormat('Y-m-d H:i:s', $start->format('Y-m-d') . ' ' . trim($rangeEnd) . ':00');

        return $start >= $dayStart && $end <= $dayEnd;
    }

    private function findSlotInRange(\DateTimeInterface $date, string $timeRange, int $durationMinutes): ?\DateTimeInterface
    {
        if (!str_contains($timeRange, '-')) {
            return null;
        }

        [$rangeStart, $rangeEnd] = explode('-', $timeRange);
        
        $current = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . trim($rangeStart) . ':00');
        $endOfRange = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . trim($rangeEnd) . ':00');
        
        $increment = $this->booking_increment_minutes;

        while ($current->copy()->addMinutes($durationMinutes) <= $endOfRange) {
            $slotEnd = $current->copy()->addMinutes($durationMinutes);
            
            if ($this->isAvailableDuring($current, $slotEnd)) {
                return $current;
            }

            $current->addMinutes($increment);
        }

        return null;
    }
}