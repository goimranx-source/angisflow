<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Domain\Booking\BookingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\BookingPage;
use App\Models\BookingResource;
use App\Models\BookingService as BookingServiceModel;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Booking Page Controller
 *
 * Handles public booking page display, availability checking,
 * and appointment scheduling for external customers.
 */
class BookingPageController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private TenantContext $tenantContext
    ) {}

    /**
     * Get booking page configuration
     */
    public function getBookingPage(Request $request, string $identifier): JsonResponse
    {
        $bookingPage = BookingPage::where('slug', $identifier)
            ->orWhere('public_id', $identifier)
            ->active()
            ->first();
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        // Set tenant context
        $this->tenantContext->setAccount($bookingPage->account);
        $this->tenantContext->setBusiness($bookingPage->business);

        return response()->json([
            'booking_page' => [
                'id' => $bookingPage->public_id,
                'title' => $bookingPage->title,
                'description' => $bookingPage->description,
                'slug' => $bookingPage->slug,
                'timezone' => $bookingPage->timezone,
                'settings' => [
                    'allow_guest_booking' => $bookingPage->allow_guest_booking,
                    'require_approval' => $bookingPage->require_approval,
                    'advance_booking_days' => $bookingPage->advance_booking_days,
                    'cancellation_hours' => $bookingPage->cancellation_hours,
                    'multiple_bookings_per_slot' => $bookingPage->multiple_bookings_per_slot,
                    'buffer_minutes' => $bookingPage->buffer_minutes,
                    'minimum_notice_hours' => $bookingPage->minimum_notice_hours,
                ],
                'branding' => [
                    'primary_color' => $bookingPage->primary_color,
                    'secondary_color' => $bookingPage->secondary_color,
                    'logo_url' => $bookingPage->logo_url,
                    'header_image_url' => $bookingPage->header_image_url,
                ],
                'contact_info' => $bookingPage->contact_info,
                'custom_fields' => $bookingPage->custom_fields ?? [],
                'confirmation_message' => $bookingPage->confirmation_message,
                'cancellation_policy' => $bookingPage->cancellation_policy,
                'seo_config' => $bookingPage->seo_config ?? [],
            ],
            'services' => $this->getAvailableServices($bookingPage),
            'operating_hours' => $bookingPage->operating_hours ?? [],
            'blocked_dates' => $this->getBlockedDates($bookingPage),
        ]);
    }

    /**
     * Get available services for booking page
     */
    public function getServices(Request $request, string $identifier): JsonResponse
    {
        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $services = $this->getAvailableServices($bookingPage);

        return response()->json(['services' => $services]);
    }

    /**
     * Get service details
     */
    public function getService(Request $request, string $identifier, string $serviceId): JsonResponse
    {
        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $service = BookingServiceModel::where('business_id', $bookingPage->business_id)
            ->where('public_id', $serviceId)
            ->where('is_active', true)
            ->with(['resources'])
            ->first();

        if (!$service) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        return response()->json([
            'service' => [
                'id' => $service->public_id,
                'name' => $service->name,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price?->toDecimalString(),
                'currency' => $service->currency,
                'category' => $service->category,
                'instructions' => $service->instructions,
                'preparation_notes' => $service->preparation_notes,
                'max_advance_days' => $service->max_advance_days,
                'min_notice_hours' => $service->min_notice_hours,
                'allow_online_booking' => $service->allow_online_booking,
                'requires_approval' => $service->requires_approval,
                'available_resources' => $service->resources->map(function ($resource) {
                    return [
                        'id' => $resource->public_id,
                        'name' => $resource->name,
                        'type' => $resource->type,
                        'capacity' => $resource->capacity,
                    ];
                }),
                'custom_fields' => $service->custom_fields ?? [],
            ],
        ]);
    }

    /**
     * Check availability for a service
     */
    public function checkAvailability(Request $request, string $identifier, string $serviceId): JsonResponse
    {
        $this->validate($request, [
            'date' => 'required|date|after:today',
            'resource_id' => 'nullable|string',
            'duration' => 'sometimes|integer|min:15',
        ]);

        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $service = BookingServiceModel::where('business_id', $bookingPage->business_id)
            ->where('public_id', $serviceId)
            ->where('is_active', true)
            ->first();

        if (!$service) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        // Set tenant context
        $this->tenantContext->setAccount($bookingPage->account);
        $this->tenantContext->setBusiness($bookingPage->business);

        try {
            $date = $request->date;
            $resourceId = $request->resource_id;
            $duration = $request->get('duration', $service->duration_minutes);

            $availability = $this->bookingService->getAvailableSlots(
                $service->id,
                $date,
                $resourceId ? BookingResource::where('public_id', $resourceId)->first()?->id : null,
                $duration
            );

            return response()->json([
                'date' => $date,
                'service_duration' => $duration,
                'available_slots' => $availability,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'AVAILABILITY_ERROR',
            ], 422);
        }
    }

    /**
     * Create a new booking
     */
    public function createBooking(Request $request, string $identifier): JsonResponse
    {
        $this->validate($request, [
            'service_id' => 'required|string',
            'resource_id' => 'nullable|string',
            'scheduled_date' => 'required|date|after:now',
            'scheduled_time' => 'required|date_format:H:i',
            'duration_minutes' => 'sometimes|integer|min:15',
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email',
            'customer_phone' => 'required|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'custom_fields' => 'sometimes|array',
        ]);

        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $service = BookingServiceModel::where('business_id', $bookingPage->business_id)
            ->where('public_id', $request->service_id)
            ->where('is_active', true)
            ->first();

        if (!$service) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        // Set tenant context
        $this->tenantContext->setAccount($bookingPage->account);
        $this->tenantContext->setBusiness($bookingPage->business);

        try {
            // Find or create customer
            $customerData = [
                'name' => $request->customer_name,
                'email' => $request->customer_email,
                'phone' => $request->customer_phone,
            ];

            // Parse datetime
            $scheduledStart = \Carbon\Carbon::createFromFormat(
                'Y-m-d H:i',
                $request->scheduled_date . ' ' . $request->scheduled_time,
                $bookingPage->timezone
            )->utc();

            $duration = $request->get('duration_minutes', $service->duration_minutes);
            $scheduledEnd = $scheduledStart->copy()->addMinutes($duration);

            // Find resource if specified
            $resource = null;
            if ($request->resource_id) {
                $resource = BookingResource::where('business_id', $bookingPage->business_id)
                    ->where('public_id', $request->resource_id)
                    ->first();
            }

            // Create booking
            $booking = $this->bookingService->createBooking([
                'service_id' => $service->id,
                'resource_id' => $resource?->id,
                'customer_data' => $customerData,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'notes' => $request->notes,
                'custom_fields' => $request->custom_fields ?? [],
                'source' => 'booking_page',
                'source_reference' => $bookingPage->public_id,
                'requires_approval' => $bookingPage->require_approval || $service->requires_approval,
            ]);

            $status = $booking->requires_approval ? 'pending' : 'confirmed';

            return response()->json([
                'success' => true,
                'booking' => [
                    'id' => $booking->public_id,
                    'booking_number' => $booking->booking_number,
                    'status' => $status,
                    'service_name' => $service->name,
                    'scheduled_start' => $booking->scheduled_start->setTimezone($bookingPage->timezone)->toIso8601String(),
                    'scheduled_end' => $booking->scheduled_end->setTimezone($bookingPage->timezone)->toIso8601String(),
                    'customer_name' => $booking->customer_name,
                    'customer_email' => $booking->customer_email,
                    'total_price' => $booking->total_price?->toDecimalString(),
                    'currency' => $booking->currency,
                    'confirmation_code' => $booking->confirmation_code,
                ],
                'message' => $status === 'pending' 
                    ? 'Booking request submitted and is pending approval'
                    : 'Booking confirmed successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'BOOKING_ERROR',
            ], 422);
        }
    }

    /**
     * Get booking details by confirmation code
     */
    public function getBooking(Request $request, string $identifier, string $confirmationCode): JsonResponse
    {
        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $booking = Booking::where('business_id', $bookingPage->business_id)
            ->where('confirmation_code', $confirmationCode)
            ->with(['service', 'resource', 'customer'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        return response()->json([
            'booking' => [
                'id' => $booking->public_id,
                'booking_number' => $booking->booking_number,
                'status' => $booking->status,
                'service_name' => $booking->service->name,
                'resource_name' => $booking->resource?->name,
                'scheduled_start' => $booking->scheduled_start->setTimezone($bookingPage->timezone)->toIso8601String(),
                'scheduled_end' => $booking->scheduled_end->setTimezone($bookingPage->timezone)->toIso8601String(),
                'duration_minutes' => $booking->duration_minutes,
                'customer_name' => $booking->customer_name,
                'customer_email' => $booking->customer_email,
                'customer_phone' => $booking->customer_phone,
                'notes' => $booking->notes,
                'total_price' => $booking->total_price?->toDecimalString(),
                'currency' => $booking->currency,
                'confirmation_code' => $booking->confirmation_code,
                'custom_fields' => $booking->custom_fields ?? [],
                'can_cancel' => $booking->canBeCancelled(),
                'can_reschedule' => $booking->canBeRescheduled(),
                'created_at' => $booking->created_at->setTimezone($bookingPage->timezone)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Cancel booking
     */
    public function cancelBooking(Request $request, string $identifier, string $confirmationCode): JsonResponse
    {
        $this->validate($request, [
            'reason' => 'nullable|string|max:500',
        ]);

        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $booking = Booking::where('business_id', $bookingPage->business_id)
            ->where('confirmation_code', $confirmationCode)
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if (!$booking->canBeCancelled()) {
            return response()->json([
                'error' => 'This booking cannot be cancelled',
                'code' => 'CANNOT_CANCEL',
            ], 422);
        }

        // Set tenant context
        $this->tenantContext->setAccount($bookingPage->account);
        $this->tenantContext->setBusiness($bookingPage->business);

        try {
            $this->bookingService->cancelBooking($booking, $request->reason ?? 'Customer cancellation');

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'CANCELLATION_ERROR',
            ], 422);
        }
    }

    /**
     * Reschedule booking
     */
    public function rescheduleBooking(Request $request, string $identifier, string $confirmationCode): JsonResponse
    {
        $this->validate($request, [
            'new_date' => 'required|date|after:now',
            'new_time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:500',
        ]);

        $bookingPage = $this->findBookingPage($identifier);
        
        if (!$bookingPage) {
            return response()->json(['error' => 'Booking page not found'], 404);
        }

        $booking = Booking::where('business_id', $bookingPage->business_id)
            ->where('confirmation_code', $confirmationCode)
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if (!$booking->canBeRescheduled()) {
            return response()->json([
                'error' => 'This booking cannot be rescheduled',
                'code' => 'CANNOT_RESCHEDULE',
            ], 422);
        }

        // Set tenant context
        $this->tenantContext->setAccount($bookingPage->account);
        $this->tenantContext->setBusiness($bookingPage->business);

        try {
            // Parse new datetime
            $newStart = \Carbon\Carbon::createFromFormat(
                'Y-m-d H:i',
                $request->new_date . ' ' . $request->new_time,
                $bookingPage->timezone
            )->utc();

            $newEnd = $newStart->copy()->addMinutes($booking->getDurationMinutes());

            $this->bookingService->rescheduleBooking(
                $booking,
                $newStart,
                $newEnd
            );

            return response()->json([
                'success' => true,
                'message' => 'Booking rescheduled successfully',
                'new_scheduled_start' => $newStart->setTimezone($bookingPage->timezone)->toIso8601String(),
                'new_scheduled_end' => $newEnd->setTimezone($bookingPage->timezone)->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'RESCHEDULE_ERROR',
            ], 422);
        }
    }

    // ── Private Helper Methods ────────────────────────────────────────────────

    /**
     * Find booking page by identifier
     */
    private function findBookingPage(string $identifier): ?BookingPage
    {
        return BookingPage::where('slug', $identifier)
            ->orWhere('public_id', $identifier)
            ->active()
            ->first();
    }

    /**
     * Get available services for booking page
     */
    private function getAvailableServices(BookingPage $bookingPage): Collection
    {
        return BookingServiceModel::where('business_id', $bookingPage->business_id)
            ->where('is_active', true)
            ->where('allow_online_booking', true)
            ->with(['resources'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->public_id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $service->price?->toDecimalString(),
                    'currency' => $service->currency,
                    'category' => $service->category,
                    'image_url' => $service->image_url,
                    'requires_approval' => $service->requires_approval,
                    'available_resources' => $service->resources->where('is_active', true)->map(function ($resource) {
                        return [
                            'id' => $resource->public_id,
                            'name' => $resource->name,
                            'type' => $resource->type,
                            'capacity' => $resource->capacity,
                        ];
                    })->values(),
                ];
            });
    }

    /**
     * Get blocked dates for booking page
     */
    private function getBlockedDates(BookingPage $bookingPage): array
    {
        // Get blocked dates from business holidays and booking page settings
        $blockedDates = [];

        // Add configured blocked dates
        if (!empty($bookingPage->blocked_dates)) {
            $blockedDates = array_merge($blockedDates, $bookingPage->blocked_dates);
        }

        // Add business holidays (if any)
        // TODO: Implement business holiday functionality

        return array_unique($blockedDates);
    }
}