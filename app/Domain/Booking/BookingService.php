<?php

namespace App\Domain\Booking;

use App\Domain\Sales\Models\Customer;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\BookingResourceAssignment;
use App\Models\BookingService as BookingServiceModel;
use App\Models\ResourceAvailability;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing bookings and appointments
 *
 * Handles the full lifecycle of bookings from creation to completion,
 * including resource scheduling, availability checking, and workflow management.
 */
class BookingService
{
    /** How many times to retry a booking number collision before refusing. */
    private const MAX_NUMBER_ATTEMPTS = 3;

    /**
     * Create a new booking
     *
     * Booking::generateBookingNumber() has no locking — two requests landing
     * in the same instant can both read the same "last number" and both try
     * to insert the next one. The unique index on booking_number is the real
     * guard; this retries the whole attempt with a freshly generated number
     * when it fires, so a storefront customer sees a confirmed booking
     * instead of a raw database error.
     */
    public function createBooking(array $data): Booking
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptCreateBooking($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_NUMBER_ATTEMPTS) {
                    throw new \RuntimeException(
                        'This booking could not be confirmed right now — please try again in a moment.',
                        previous: $e
                    );
                }
                // Somebody else claimed that booking number between our read
                // and our insert; generate a new one and try again.
            }
        }
    }

    private function attemptCreateBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            $tenant = app(TenantContext::class);
            
            // Validate service exists and is active
            $service = BookingServiceModel::where('business_id', $tenant->business()->id)
                                         ->where('id', $data['service_id'])
                                         ->where('is_active', true)
                                         ->firstOrFail();

            // Validate service constraints
            $validationErrors = $service->validateBookingRequest($data);
            if (!empty($validationErrors)) {
                throw new \InvalidArgumentException('Booking validation failed: ' . implode(', ', $validationErrors));
            }

            // Calculate scheduled end time if not provided
            if (!isset($data['scheduled_end'])) {
                $scheduledStart = new \DateTime($data['scheduled_start']);
                $duration = $data['duration_minutes'] ?? $service->duration_minutes;
                $data['scheduled_end'] = $scheduledStart->copy()->addMinutes($duration);
            }

            // Validate resource availability if resources specified
            if (isset($data['resource_ids']) && !empty($data['resource_ids'])) {
                $this->validateResourceAvailability(
                    $data['resource_ids'],
                    new \DateTime($data['scheduled_start']),
                    new \DateTime($data['scheduled_end'])
                );
            }

            // Calculate pricing
            $participants = $data['participant_count'] ?? 1;
            $duration = isset($data['duration_minutes']) ? $data['duration_minutes'] : null;
            $quotedPrice = $service->calculatePrice($participants, $duration);

            // Create customer record if needed
            if (isset($data['customer_email']) && !isset($data['customer_id'])) {
                $customer = $this->findOrCreateCustomer($data);
                $data['customer_id'] = $customer->id;
            }

            // Remove resource_ids from booking data as it's handled separately
            $resourceIds = $data['resource_ids'] ?? [];
            unset($data['resource_ids']);
            
            // Prepare booking data
            $bookingData = array_merge($data, [
                'account_id' => $tenant->account()->id,
                'business_id' => $tenant->business()->id,
                'booking_number' => Booking::generateBookingNumber(),
                'status' => $data['status'] ?? 'pending',
                'quoted_price_minor' => $quotedPrice->minor,
                'currency' => $tenant->business()->base_currency,
                'booking_source' => $data['booking_source'] ?? 'staff',
                'created_by' => auth()->id(),
            ]);

            // Create the booking
            $booking = Booking::create($bookingData);

            // Assign resources if specified
            if (!empty($resourceIds)) {
                $this->assignResourcesToBooking(
                    $booking,
                    $resourceIds,
                    $data['resource_roles'] ?? []
                );
            }

            return $booking->load(['service', 'customer', 'resources']);
        });
    }

    /**
     * Update an existing booking
     */
    public function updateBooking(Booking $booking, array $data): Booking
    {
        if (in_array($booking->status, ['completed', 'cancelled'])) {
            throw new \InvalidArgumentException('Cannot update completed or cancelled bookings');
        }

        return DB::transaction(function () use ($booking, $data) {
            // resources: read below to check availability for the new time.
            // service: read below to reprice when participant_count changes.
            $booking->loadMissing(['resources', 'service']);

            // If changing time, validate new availability
            if (isset($data['scheduled_start']) || isset($data['scheduled_end'])) {
                $newStart = isset($data['scheduled_start']) 
                    ? new \DateTime($data['scheduled_start'])
                    : $booking->scheduled_start;
                
                $newEnd = isset($data['scheduled_end'])
                    ? new \DateTime($data['scheduled_end'])
                    : $booking->scheduled_end;

                // Check resource availability for new time
                $resourceIds = $booking->resources->pluck('id')->toArray();
                if (!empty($resourceIds)) {
                    $this->validateResourceAvailability($resourceIds, $newStart, $newEnd, $booking->id);
                }

                // Update resource assignments
                if (isset($data['scheduled_start']) || isset($data['scheduled_end'])) {
                    $booking->resourceAssignments()->update([
                        'assigned_start' => $newStart,
                        'assigned_end' => $newEnd,
                    ]);
                }
            }

            // Recalculate pricing if participants changed
            if (isset($data['participant_count']) && $data['participant_count'] != $booking->participant_count) {
                $quotedPrice = $booking->service->calculatePrice($data['participant_count']);
                $data['quoted_price_minor'] = $quotedPrice->minor;
            }

            $booking->update($data);

            return $booking->fresh(['service', 'customer', 'resources']);
        });
    }

    /**
     * Confirm a pending booking
     */
    public function confirmBooking(Booking $booking, ?int $confirmedBy = null): Booking
    {
        return $booking->confirm($confirmedBy ?? auth()->id());
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking(Booking $booking, ?string $reason = null, ?int $cancelledBy = null): Booking
    {
        // cancel() -> canBeCancelled() reads $booking->service to check the
        // service's own cancellation window.
        $booking->loadMissing('service');

        return $booking->cancel($cancelledBy ?? auth()->id(), $reason);
    }

    /**
     * Start a booking (mark in progress)
     */
    public function startBooking(Booking $booking, ?int $startedBy = null): Booking
    {
        return $booking->start($startedBy ?? auth()->id());
    }

    /**
     * Complete a booking
     */
    public function completeBooking(Booking $booking, ?array $completionData = null): Booking
    {
        $finalPrice = null;
        if (isset($completionData['final_price_minor'])) {
            $finalPrice = $completionData['final_price_minor'];
        }

        return $booking->complete(auth()->id(), $finalPrice);
    }

    /**
     * Mark booking as no-show
     */
    public function markNoShow(Booking $booking, ?string $reason = null): Booking
    {
        return $booking->markNoShow(auth()->id(), $reason);
    }

    /**
     * Reschedule a booking to new time
     */
    public function rescheduleBooking(Booking $booking, \DateTimeInterface $newStart, \DateTimeInterface $newEnd): Booking
    {
        // resources: read below to check availability for the new time.
        $booking->loadMissing('resources');

        // Validate new time availability
        $resourceIds = $booking->resources->pluck('id')->toArray();
        if (!empty($resourceIds)) {
            $this->validateResourceAvailability($resourceIds, $newStart, $newEnd, $booking->id);
        }

        return $booking->reschedule($newStart, $newEnd, auth()->id());
    }

    /**
     * Get bookings for a specific date range
     */
    public function getBookingsForPeriod(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): Collection
    {
        $tenant = app(TenantContext::class);
        
        $query = Booking::where('business_id', $tenant->business()->id)
                        ->with(['service', 'customer', 'resources'])
                        ->between($start, $end);

        // Apply filters
        if (isset($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (isset($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['resource_id'])) {
            $query->whereHas('resources', function ($q) use ($filters) {
                $q->where('booking_resources.id', $filters['resource_id']);
            });
        }

        return $query->orderBy('scheduled_start')->get();
    }

    /**
     * Get upcoming bookings (next 7 days)
     */
    public function getUpcomingBookings(int $days = 7): Collection
    {
        $tenant = app(TenantContext::class);

        return Booking::where('business_id', $tenant->business()->id)
                      ->with(['service', 'customer', 'resources'])
                      ->upcoming()
                      ->where('scheduled_start', '<=', now()->addDays($days))
                      ->orderBy('scheduled_start')
                      ->get();
    }

    /**
     * Get today's bookings
     */
    public function getTodaysBookings(): Collection
    {
        $tenant = app(TenantContext::class);

        return Booking::where('business_id', $tenant->business()->id)
                      ->with(['service', 'customer', 'resources'])
                      ->today()
                      ->orderBy('scheduled_start')
                      ->get();
    }

    /**
     * Get booking statistics for a period
     */
    public function getBookingStats(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenant = app(TenantContext::class);
        $businessId = $tenant->business()->id;
        
        $totalBookings = Booking::where('business_id', $businessId)
                               ->between($start, $end)
                               ->count();

        $confirmedBookings = Booking::where('business_id', $businessId)
                                   ->between($start, $end)
                                   ->where('status', 'confirmed')
                                   ->count();

        $completedBookings = Booking::where('business_id', $businessId)
                                   ->between($start, $end)
                                   ->where('status', 'completed')
                                   ->count();

        $cancelledBookings = Booking::where('business_id', $businessId)
                                   ->between($start, $end)
                                   ->where('status', 'cancelled')
                                   ->count();

        $noShowBookings = Booking::where('business_id', $businessId)
                                ->between($start, $end)
                                ->where('status', 'no_show')
                                ->count();

        // Revenue calculations
        $totalRevenue = Booking::where('business_id', $businessId)
                              ->between($start, $end)
                              ->where('status', 'completed')
                              ->get()
                              ->sum(function ($booking) {
                                  return $booking->getEffectivePrice()->minor;
                              });

        $currency = $tenant->business()->base_currency;

        return [
            'period' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'total_bookings' => $totalBookings,
            'confirmed_bookings' => $confirmedBookings,
            'completed_bookings' => $completedBookings,
            'cancelled_bookings' => $cancelledBookings,
            'no_show_bookings' => $noShowBookings,
            'completion_rate' => $totalBookings > 0 ? round(($completedBookings / $totalBookings) * 100, 1) : 0,
            'cancellation_rate' => $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 1) : 0,
            'no_show_rate' => $totalBookings > 0 ? round(($noShowBookings / $totalBookings) * 100, 1) : 0,
            'total_revenue' => new Money($totalRevenue, $currency),
            'average_booking_value' => $completedBookings > 0 
                ? new Money((int) round($totalRevenue / $completedBookings), $currency)
                : new Money(0, $currency),
        ];
    }

    /**
     * Get customer's booking history
     */
    public function getCustomerBookings(int $customerId, int $limit = 50): Collection
    {
        $tenant = app(TenantContext::class);

        return Booking::where('business_id', $tenant->business()->id)
                      ->where('customer_id', $customerId)
                      ->with(['service', 'resources'])
                      ->orderBy('scheduled_start', 'desc')
                      ->limit($limit)
                      ->get();
    }

    /**
     * Find available time slots for a service
     */
    public function findAvailableSlots(int $serviceId, \DateTimeInterface $date, ?array $resourceIds = null): array
    {
        $service = BookingServiceModel::findOrFail($serviceId);
        $slots = [];

        // Get compatible resources if none specified
        if (empty($resourceIds)) {
            $compatibleResources = $this->getCompatibleResourcesForService($service);
            $resourceIds = collect($compatibleResources)->pluck('id')->toArray();
        }

        if (empty($resourceIds)) {
            return []; // No resources available
        }

        // Generate time slots for the day
        $startOfDay = (clone $date)->setTime(8, 0); // Default start time
        $endOfDay = (clone $date)->setTime(18, 0);  // Default end time
        $increment = 30; // 30-minute increments

        $current = clone $startOfDay;
        while ($current->copy()->addMinutes($service->duration_minutes) <= $endOfDay) {
            $slotEnd = $current->copy()->addMinutes($service->duration_minutes);

            // Check if any resource is available for this slot
            $availableResources = [];
            foreach ($resourceIds as $resourceId) {
                $resource = BookingResource::find($resourceId);
                if ($resource && $resource->isAvailableDuring($current, $slotEnd)) {
                    $availableResources[] = $resource;
                }
            }

            if (!empty($availableResources)) {
                $slots[] = [
                    'start_time' => $current->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                    'available_resources' => collect($availableResources)->map(function ($resource) {
                        return [
                            'id' => $resource->id,
                            'name' => $resource->name,
                            'resource_type' => $resource->resource_type,
                        ];
                    })->toArray(),
                ];
            }

            $current->addMinutes($increment);
        }

        return $slots;
    }

    // ── Private Helper Methods ─────────────────────────────────────────────

    /**
     * Validate that resources are available during specified time
     */
    private function validateResourceAvailability(array $resourceIds, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeBookingId = null): void
    {
        foreach ($resourceIds as $resourceId) {
            $resource = BookingResource::findOrFail($resourceId);
            
            if (!$resource->isAvailableDuring($start, $end)) {
                throw new \InvalidArgumentException("Resource '{$resource->name}' is not available during the requested time");
            }

            // Check for conflicting bookings (excluding current booking if updating)
            $conflictQuery = $resource->assignments()
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

            if ($conflictQuery->exists()) {
                throw new \InvalidArgumentException("Resource '{$resource->name}' has conflicting bookings during the requested time");
            }
        }
    }

    /**
     * Assign resources to a booking
     */
    private function assignResourcesToBooking(Booking $booking, array $resourceIds, array $roles = []): void
    {
        foreach ($resourceIds as $index => $resourceId) {
            BookingResourceAssignment::create([
                'account_id' => $booking->account_id,
                'business_id' => $booking->business_id,
                'booking_id' => $booking->id,
                'resource_id' => $resourceId,
                'role' => $roles[$index] ?? 'primary',
                'assigned_start' => $booking->scheduled_start,
                'assigned_end' => $booking->scheduled_end,
            ]);
        }
    }

    /**
     * Find or create customer from booking data
     */
    private function findOrCreateCustomer(array $data): Customer
    {
        $tenant = app(TenantContext::class);

        // Try to find existing customer by email
        if (isset($data['customer_email'])) {
            $existingCustomer = Customer::where('business_id', $tenant->business()->id)
                                      ->where('email', $data['customer_email'])
                                      ->first();

            if ($existingCustomer) {
                return $existingCustomer;
            }
        }

        // Create new customer
        return Customer::create([
            'account_id' => $tenant->account()->id,
            'business_id' => $tenant->business()->id,
            'name' => $data['customer_name'],
            'email' => $data['customer_email'] ?? null,
            'phone' => $data['customer_phone'] ?? null,
            'currency' => $tenant->business()->base_currency,
        ]);
    }

    /**
     * Get resources compatible with a service
     */
    private function getCompatibleResourcesForService(BookingServiceModel $service): array
    {
        return $service->getCompatibleResources();
    }
}