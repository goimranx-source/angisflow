<?php

namespace App\Domain\Booking;

use App\Models\BookingResource;
use App\Models\BookingService as BookingServiceModel;
use App\Models\ResourceAvailability;
use App\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing bookable resources and their availability
 *
 * Handles resource creation, availability management, scheduling optimization,
 * and resource utilization reporting.
 */
class ResourceSchedulingService
{
    /**
     * Create a new bookable resource
     */
    public function createResource(array $data): BookingResource
    {
        $tenant = app(TenantContext::class);
        
        $resourceData = array_merge($data, [
            'account_id' => $tenant->account()->id,
            'business_id' => $tenant->business()->id,
        ]);

        return BookingResource::create($resourceData);
    }

    /**
     * Update resource details
     */
    public function updateResource(BookingResource $resource, array $data): BookingResource
    {
        $resource->update($data);
        return $resource->fresh();
    }

    /**
     * Create resource from existing employee
     */
    public function createResourceFromEmployee(Employee $employee, array $additionalData = []): BookingResource
    {
        $resourceData = array_merge([
            'name' => $employee->name,
            'resource_type' => 'employee',
            'resource_id' => $employee->id,
            'description' => "Employee: {$employee->name}",
            'is_active' => $employee->status === 'active',
            'working_hours' => [
                'monday' => ['09:00-17:00'],
                'tuesday' => ['09:00-17:00'], 
                'wednesday' => ['09:00-17:00'],
                'thursday' => ['09:00-17:00'],
                'friday' => ['09:00-17:00'],
            ],
            'booking_increment_minutes' => 30,
            'allow_back_to_back' => true,
            'max_daily_hours' => 8,
        ], $additionalData);

        return $this->createResource($resourceData);
    }

    /**
     * Set resource availability for a specific date
     */
    public function setAvailability(BookingResource $resource, \DateTimeInterface $date, array $timeRanges, string $availabilityType = 'available'): void
    {
        // Remove existing availability for this date
        ResourceAvailability::where('resource_id', $resource->id)
                           ->where('date', $date->format('Y-m-d'))
                           ->where('availability_type', $availabilityType)
                           ->delete();

        // Add new availability entries
        foreach ($timeRanges as $timeRange) {
            [$startTime, $endTime] = explode('-', $timeRange);
            
            ResourceAvailability::create([
                'account_id' => $resource->account_id,
                'business_id' => $resource->business_id,
                'resource_id' => $resource->id,
                'date' => $date->format('Y-m-d'),
                'start_time' => trim($startTime),
                'end_time' => trim($endTime),
                'availability_type' => $availabilityType,
                'is_override' => true,
            ]);
        }
    }

    /**
     * Set resource unavailability (holidays, breaks, etc.)
     */
    public function setUnavailability(BookingResource $resource, \DateTimeInterface $date, array $timeRanges, string $reason = null): void
    {
        $this->setAvailability($resource, $date, $timeRanges, 'unavailable');
        
        // Update the reason if provided
        if ($reason) {
            ResourceAvailability::where('resource_id', $resource->id)
                               ->where('date', $date->format('Y-m-d'))
                               ->where('availability_type', 'unavailable')
                               ->update(['override_reason' => $reason]);
        }
    }

    /**
     * Bulk set working hours for multiple resources
     */
    public function bulkSetWorkingHours(array $resourceIds, array $workingHours): void
    {
        $tenant = app(TenantContext::class);

        BookingResource::whereIn('id', $resourceIds)
                      ->where('business_id', $tenant->business()->id)
                      ->update(['working_hours' => $workingHours]);
    }

    /**
     * Get resource schedule for a date range
     */
    public function getResourceSchedule(BookingResource $resource, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $schedule = [];
        $current = clone $start;
        
        while ($current <= $end) {
            $dateKey = $current->format('Y-m-d');
            $dayOfWeek = strtolower($current->format('l'));
            
            // Get default working hours for this day
            $defaultHours = $resource->getWorkingHoursForDay($dayOfWeek);
            
            // Get availability overrides for this date
            $overrides = ResourceAvailability::where('resource_id', $resource->id)
                                           ->where('date', $dateKey)
                                           ->orderBy('start_time')
                                           ->get();
            
            // Get bookings for this date
            $bookings = $resource->getBookingsForPeriod($current, $current->copy()->endOfDay());
            
            $schedule[$dateKey] = [
                'date' => $dateKey,
                'day_of_week' => $current->format('l'),
                'default_hours' => $defaultHours,
                'availability_overrides' => $overrides->map(function ($override) {
                    return [
                        'start_time' => $override->start_time,
                        'end_time' => $override->end_time,
                        'type' => $override->availability_type,
                        'reason' => $override->override_reason,
                    ];
                })->toArray(),
                'bookings' => $bookings,
                'total_booked_hours' => $resource->getTotalBookedHours($current),
                'is_fully_booked' => $resource->getTotalBookedHours($current) >= $resource->max_daily_hours,
            ];
            
            $current->addDay();
        }
        
        return $schedule;
    }

    /**
     * Find optimal resource assignment for a booking
     */
    public function findOptimalResource(int $serviceId, \DateTimeInterface $start, \DateTimeInterface $end, array $preferences = []): ?BookingResource
    {
        $service = BookingServiceModel::findOrFail($serviceId);
        $compatibleResources = collect($service->getCompatibleResources());
        
        if ($compatibleResources->isEmpty()) {
            return null;
        }

        // Filter by availability
        $availableResources = $compatibleResources->filter(function ($resource) use ($start, $end) {
            $resourceModel = BookingResource::find($resource['id']);
            return $resourceModel && $resourceModel->isAvailableDuring($start, $end);
        });

        if ($availableResources->isEmpty()) {
            return null;
        }

        // Apply preferences and scoring
        $scoredResources = $availableResources->map(function ($resource) use ($start, $preferences) {
            $resourceModel = BookingResource::find($resource['id']);
            $score = 0;

            // Prefer resources with matching tags
            if (isset($preferences['tags']) && !empty($resource['tags'])) {
                $matchingTags = array_intersect($preferences['tags'], $resource['tags']);
                $score += count($matchingTags) * 10;
            }

            // Prefer less busy resources (load balancing)
            $dailyHours = $resourceModel->getTotalBookedHours($start);
            $utilizationRate = $dailyHours / $resourceModel->max_daily_hours;
            $score += (1 - $utilizationRate) * 5; // Favor less utilized resources

            // Prefer specific resource types
            if (isset($preferences['resource_type']) && $resource['resource_type'] === $preferences['resource_type']) {
                $score += 15;
            }

            return [
                'resource' => $resourceModel,
                'score' => $score,
            ];
        })->sortByDesc('score');

        return $scoredResources->first()['resource'] ?? null;
    }

    /**
     * Get resource utilization report
     */
    public function getResourceUtilization(\DateTimeInterface $start, \DateTimeInterface $end, ?array $resourceIds = null): array
    {
        $businessId = app(TenantContext::class)->business()->id;

        $resourceQuery = BookingResource::where('business_id', $businessId)
                                       ->where('is_active', true);
        
        if ($resourceIds) {
            $resourceQuery->whereIn('id', $resourceIds);
        }

        $resources = $resourceQuery->get();
        $utilizationData = [];

        foreach ($resources as $resource) {
            $totalBookingHours = 0;
            $totalAvailableHours = 0;
            $bookingCount = 0;

            $current = clone $start;
            while ($current <= $end) {
                $dailyBookedHours = $resource->getTotalBookedHours($current);
                $totalBookingHours += $dailyBookedHours;

                // Calculate available hours for this day
                $dayOfWeek = strtolower($current->format('l'));
                $workingHours = $resource->getWorkingHoursForDay($dayOfWeek);
                $dailyAvailableHours = 0;

                foreach ($workingHours as $timeRange) {
                    if (str_contains($timeRange, '-')) {
                        [$start_time, $end_time] = explode('-', $timeRange);
                        $startHour = Carbon::createFromTimeString(trim($start_time));
                        $endHour = Carbon::createFromTimeString(trim($end_time));
                        $dailyAvailableHours += $startHour->diffInHours($endHour);
                    }
                }

                $totalAvailableHours += min($dailyAvailableHours, $resource->max_daily_hours);

                // Count bookings for this day
                $dailyBookings = $resource->assignments()
                    ->whereHas('booking', function ($q) {
                        $q->whereIn('status', ['confirmed', 'completed']);
                    })
                    ->whereDate('assigned_start', $current->format('Y-m-d'))
                    ->count();

                $bookingCount += $dailyBookings;

                $current->addDay();
            }

            $utilizationRate = $totalAvailableHours > 0 ? ($totalBookingHours / $totalAvailableHours) * 100 : 0;

            $utilizationData[] = [
                'resource_id' => $resource->id,
                'resource_name' => $resource->name,
                'resource_type' => $resource->resource_type,
                'total_booked_hours' => round($totalBookingHours, 2),
                'total_available_hours' => round($totalAvailableHours, 2),
                'utilization_rate' => round($utilizationRate, 1),
                'booking_count' => $bookingCount,
                'average_booking_hours' => $bookingCount > 0 ? round($totalBookingHours / $bookingCount, 2) : 0,
            ];
        }

        // Sort by utilization rate descending
        usort($utilizationData, function ($a, $b) {
            return $b['utilization_rate'] <=> $a['utilization_rate'];
        });

        return [
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'resources' => $utilizationData,
            'summary' => [
                'total_resources' => count($utilizationData),
                'average_utilization' => count($utilizationData) > 0 
                    ? round(array_sum(array_column($utilizationData, 'utilization_rate')) / count($utilizationData), 1)
                    : 0,
                'total_booked_hours' => round(array_sum(array_column($utilizationData, 'total_booked_hours')), 2),
                'total_available_hours' => round(array_sum(array_column($utilizationData, 'total_available_hours')), 2),
            ],
        ];
    }

    /**
     * Get available resources for a service at a specific time
     */
    public function getAvailableResources(int $serviceId, \DateTimeInterface $start, \DateTimeInterface $end): Collection
    {
        $service = BookingServiceModel::findOrFail($serviceId);
        $compatibleResources = collect($service->getCompatibleResources());
        
        return $compatibleResources->filter(function ($resource) use ($start, $end) {
            $resourceModel = BookingResource::find($resource['id']);
            return $resourceModel && $resourceModel->isAvailableDuring($start, $end);
        })->map(function ($resource) {
            return BookingResource::find($resource['id']);
        })->values();
    }

    /**
     * Set resource as temporarily unavailable (sick leave, vacation, etc.)
     */
    public function setTemporaryUnavailability(BookingResource $resource, \DateTimeInterface $startDate, \DateTimeInterface $endDate, string $reason): void
    {
        $current = clone $startDate;
        
        while ($current <= $endDate) {
            // Get working hours for this day
            $dayOfWeek = strtolower($current->format('l'));
            $workingHours = $resource->getWorkingHoursForDay($dayOfWeek);
            
            if (!empty($workingHours)) {
                $this->setUnavailability($resource, $current, $workingHours, $reason);
            }
            
            $current->addDay();
        }
    }

    /**
     * Get resource conflicts for a booking
     */
    public function getResourceConflicts(int $resourceId, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeBookingId = null): array
    {
        $resource = BookingResource::findOrFail($resourceId);
        
        $conflictQuery = $resource->assignments()
            ->with(['booking.service', 'booking.customer'])
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
            });

        if ($excludeBookingId) {
            $conflictQuery->where('booking_id', '!=', $excludeBookingId);
        }

        return $conflictQuery->get()->map(function ($assignment) {
            return [
                'booking_id' => $assignment->booking->id,
                'booking_number' => $assignment->booking->booking_number,
                'service_name' => $assignment->booking->service->name,
                'customer_name' => $assignment->booking->customer_name,
                'scheduled_start' => $assignment->assigned_start,
                'scheduled_end' => $assignment->assigned_end,
                'status' => $assignment->booking->status,
            ];
        })->toArray();
    }

    /**
     * Auto-assign resources to pending bookings
     */
    public function autoAssignResources(): array
    {
        $tenant = app(TenantContext::class);

        $pendingBookings = \App\Models\Booking::where('business_id', $tenant->business()->id)
                                            ->where('status', 'pending')
                                            ->whereDoesntHave('resources')
                                            ->with('service')
                                            ->get();

        $assignments = [];

        foreach ($pendingBookings as $booking) {
            $optimalResource = $this->findOptimalResource(
                $booking->service_id,
                $booking->scheduled_start,
                $booking->scheduled_end
            );

            if ($optimalResource) {
                \App\Models\BookingResourceAssignment::create([
                    'account_id' => $booking->account_id,
                    'business_id' => $booking->business_id,
                    'booking_id' => $booking->id,
                    'resource_id' => $optimalResource->id,
                    'role' => 'primary',
                    'assigned_start' => $booking->scheduled_start,
                    'assigned_end' => $booking->scheduled_end,
                ]);

                $assignments[] = [
                    'booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'resource_id' => $optimalResource->id,
                    'resource_name' => $optimalResource->name,
                ];
            }
        }

        return $assignments;
    }

    /**
     * Get resources that need attention (overbooked, conflicts, etc.)
     */
    public function getResourceAlerts(\DateTimeInterface $date = null): array
    {
        $date = $date ?? now();
        $businessId = app(TenantContext::class)->business()->id;
        $alerts = [];

        $resources = BookingResource::where('business_id', $businessId)
                                   ->where('is_active', true)
                                   ->get();

        foreach ($resources as $resource) {
            $dailyHours = $resource->getTotalBookedHours($date);
            
            // Overbooked alert
            if ($dailyHours > $resource->max_daily_hours) {
                $alerts[] = [
                    'type' => 'overbooked',
                    'severity' => 'high',
                    'resource_id' => $resource->id,
                    'resource_name' => $resource->name,
                    'message' => "Overbooked by " . round($dailyHours - $resource->max_daily_hours, 1) . " hours",
                    'booked_hours' => $dailyHours,
                    'max_hours' => $resource->max_daily_hours,
                ];
            }
            
            // Near capacity alert
            elseif ($dailyHours > ($resource->max_daily_hours * 0.9)) {
                $alerts[] = [
                    'type' => 'near_capacity',
                    'severity' => 'medium',
                    'resource_id' => $resource->id,
                    'resource_name' => $resource->name,
                    'message' => "Near capacity at " . round(($dailyHours / $resource->max_daily_hours) * 100, 1) . "%",
                    'booked_hours' => $dailyHours,
                    'max_hours' => $resource->max_daily_hours,
                ];
            }
            
            // Check for booking conflicts
            $conflicts = $this->getResourceConflicts(
                $resource->id,
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay()
            );
            
            if (!empty($conflicts)) {
                $alerts[] = [
                    'type' => 'conflicts',
                    'severity' => 'high',
                    'resource_id' => $resource->id,
                    'resource_name' => $resource->name,
                    'message' => count($conflicts) . " booking conflicts detected",
                    'conflicts' => $conflicts,
                ];
            }
        }

        return $alerts;
    }
}