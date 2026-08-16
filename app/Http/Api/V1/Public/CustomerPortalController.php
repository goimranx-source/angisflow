<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Domain\Storefront\CustomerPortalService;
use App\Domain\Sales\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer Portal Controller
 *
 * Handles customer account management, authentication, orders,
 * bookings, support tickets, and wishlist operations.
 */
class CustomerPortalController extends Controller
{
    public function __construct(
        private CustomerPortalService $customerPortalService
    ) {}

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Customer login
     */
    public function login(Request $request): JsonResponse
    {
        $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_device' => 'sometimes|boolean',
        ]);

        try {
            $sessionData = [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'remember_device' => $request->get('remember_device', false),
            ];

            $auth = $this->customerPortalService->authenticateCustomer(
                $request->email,
                $request->password,
                $sessionData
            );

            return response()->json([
                'success' => true,
                'token' => $auth['token'],
                'customer' => [
                    'id' => $auth['customer']->public_id,
                    'name' => $auth['customer']->name,
                    'email' => $auth['customer']->email,
                    'phone' => $auth['customer']->phone,
                    'preferences' => $auth['customer']->preferences ?? [],
                ],
                'session_expires_at' => $auth['session']->expires_at->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'AUTH_ERROR',
            ], 401);
        }
    }

    /**
     * Customer registration
     */
    public function register(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8|confirmed',
            'terms_accepted' => 'required|accepted',
        ]);

        try {
            $customer = $this->customerPortalService->registerCustomer($request->only([
                'name', 'email', 'phone', 'password'
            ]));

            return response()->json([
                'success' => true,
                'customer' => [
                    'id' => $customer->public_id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                ],
                'message' => 'Account created successfully. Please check your email to verify your account.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'REGISTRATION_ERROR',
            ], 422);
        }
    }

    /**
     * Customer logout
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json(['error' => 'Token required'], 401);
        }

        $this->customerPortalService->logout($token);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current customer info
     */
    public function me(Request $request): JsonResponse
    {
        $customer = $request->customer;

        return response()->json([
            'customer' => [
                'id' => $customer->public_id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'addresses' => $customer->addresses ?? [],
                'preferences' => $customer->preferences ?? [],
                'email_verified' => $customer->email_verified_at !== null,
                'created_at' => $customer->created_at->toIso8601String(),
            ],
        ]);
    }

    // ── Account Management ────────────────────────────────────────────────────

    /**
     * Update customer profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:50',
            'preferences' => 'sometimes|array',
        ]);

        try {
            $customer = $this->customerPortalService->updateProfile(
                $request->customer,
                $request->only(['name', 'phone', 'preferences'])
            );

            return response()->json([
                'success' => true,
                'customer' => [
                    'id' => $customer->public_id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'preferences' => $customer->preferences ?? [],
                ],
                'message' => 'Profile updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'PROFILE_ERROR',
            ], 422);
        }
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $this->validate($request, [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->customerPortalService->changePassword(
                $request->customer,
                $request->current_password,
                $request->password
            );

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully. You will need to log in again.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'PASSWORD_ERROR',
            ], 422);
        }
    }

    /**
     * Update customer addresses
     */
    public function updateAddresses(Request $request): JsonResponse
    {
        $this->validate($request, [
            'addresses' => 'required|array',
            'addresses.*.type' => 'required|in:shipping,billing',
            'addresses.*.name' => 'required|string|max:255',
            'addresses.*.line1' => 'required|string|max:255',
            'addresses.*.line2' => 'nullable|string|max:255',
            'addresses.*.city' => 'required|string|max:255',
            'addresses.*.state' => 'required|string|max:255',
            'addresses.*.postal_code' => 'required|string|max:20',
            'addresses.*.country' => 'required|string|size:2',
            'addresses.*.is_default' => 'sometimes|boolean',
        ]);

        try {
            $customer = $this->customerPortalService->updateAddresses(
                $request->customer,
                $request->addresses
            );

            return response()->json([
                'success' => true,
                'addresses' => $customer->addresses,
                'message' => 'Addresses updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'ADDRESS_ERROR',
            ], 422);
        }
    }

    // ── Dashboard ──────────────────────────────────────────────────────────────

    /**
     * Get customer dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        $dashboardData = $this->customerPortalService->getDashboardData($request->customer);

        return response()->json([
            'dashboard' => [
                'recent_orders' => $dashboardData['recent_orders']->map(function ($order) {
                    return [
                        'id' => $order->public_id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total' => $order->total?->toDecimalString(),
                        'currency' => $order->currency,
                        'created_at' => $order->created_at->format('Y-m-d H:i'),
                    ];
                }),
                'upcoming_bookings' => $dashboardData['upcoming_bookings']->map(function ($booking) {
                    return [
                        'id' => $booking->public_id,
                        'service_name' => $booking->service_name,
                        'scheduled_start' => $booking->scheduled_start->toIso8601String(),
                        'scheduled_end' => $booking->scheduled_end->toIso8601String(),
                        'status' => $booking->status,
                    ];
                }),
                'open_tickets' => $dashboardData['open_tickets'],
                'wishlist_count' => $dashboardData['wishlist_count'],
                'notifications' => array_slice($dashboardData['notifications'], 0, 5),
            ],
        ]);
    }

    // ── Orders ─────────────────────────────────────────────────────────────────

    /**
     * Get customer orders
     */
    public function getOrders(Request $request): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|string',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $result = $this->customerPortalService->getCustomerOrders(
            $request->customer,
            $request->only(['status', 'from_date', 'to_date']),
            $request->get('page', 1),
            $request->get('per_page', 10)
        );

        return response()->json([
            'orders' => collect($result['orders'])->map(function ($order) {
                return [
                    'id' => $order->public_id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total' => $order->total?->toDecimalString(),
                    'currency' => $order->currency,
                    'items_count' => $order->lines->count(),
                    'created_at' => $order->created_at->format('Y-m-d H:i'),
                    'can_cancel' => $order->canBeCancelled(),
                    'tracking_number' => $order->getTrackingNumber(),
                ];
            }),
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * Get order details
     */
    public function getOrder(Request $request, string $orderPublicId): JsonResponse
    {
        $order = $this->customerPortalService->getOrderDetails($request->customer, $orderPublicId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'order' => [
                'id' => $order->public_id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => $order->total?->toDecimalString(),
                'subtotal' => $order->subtotal?->toDecimalString(),
                'tax_amount' => $order->tax_amount?->toDecimalString(),
                'shipping_amount' => $order->shipping_amount?->toDecimalString(),
                'currency' => $order->currency,
                'notes' => $order->notes,
                'created_at' => $order->created_at->toIso8601String(),
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'items' => $order->lines->map(function ($line) {
                    return [
                        'product_name' => $line->product_name,
                        'variant_name' => $line->variant_name,
                        'sku' => $line->sku,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price?->toDecimalString(),
                        'line_total' => $line->line_total?->toDecimalString(),
                        'product_id' => $line->product?->public_id,
                        'variant_id' => $line->variant?->public_id,
                    ];
                }),
                'payments' => $order->payments->map(function ($payment) {
                    return [
                        'id' => $payment->public_id,
                        'amount' => $payment->amount?->toDecimalString(),
                        'method' => $payment->method,
                        'status' => $payment->status,
                        'processed_at' => $payment->processed_at?->toIso8601String(),
                    ];
                }),
                'shipments' => $order->shipments->map(function ($shipment) {
                    return [
                        'id' => $shipment->public_id,
                        'tracking_number' => $shipment->tracking_number,
                        'carrier' => $shipment->carrier,
                        'status' => $shipment->status,
                        'shipped_at' => $shipment->shipped_at?->toIso8601String(),
                        'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                    ];
                }),
                'can_cancel' => $order->canBeCancelled(),
                'can_return' => $order->canBeReturned(),
            ],
        ]);
    }

    // ── Bookings ───────────────────────────────────────────────────────────────

    /**
     * Get customer bookings
     */
    public function getBookings(Request $request): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|string',
            'upcoming' => 'sometimes|boolean',
            'past' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $result = $this->customerPortalService->getCustomerBookings(
            $request->customer,
            $request->only(['status', 'upcoming', 'past']),
            $request->get('page', 1),
            $request->get('per_page', 10)
        );

        return response()->json([
            'bookings' => collect($result['bookings'])->map(function ($booking) {
                return [
                    'id' => $booking->public_id,
                    'service_name' => $booking->service_name,
                    'scheduled_start' => $booking->scheduled_start->toIso8601String(),
                    'scheduled_end' => $booking->scheduled_end->toIso8601String(),
                    'duration_minutes' => $booking->duration_minutes,
                    'status' => $booking->status,
                    'total_price' => $booking->total_price?->toDecimalString(),
                    'currency' => $booking->currency,
                    'notes' => $booking->notes,
                    'can_cancel' => $booking->canBeCancelled(),
                    'can_reschedule' => $booking->canBeRescheduled(),
                ];
            }),
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * Cancel booking
     */
    public function cancelBooking(Request $request, string $bookingPublicId): JsonResponse
    {
        $this->validate($request, [
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $booking = $this->customerPortalService->cancelBooking(
                $request->customer,
                $bookingPublicId,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'booking' => [
                    'id' => $booking->public_id,
                    'status' => $booking->status,
                    'cancelled_at' => $booking->cancelled_at->toIso8601String(),
                ],
                'message' => 'Booking cancelled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'BOOKING_ERROR',
            ], 422);
        }
    }

    // ── Support Tickets ───────────────────────────────────────────────────────

    /**
     * Get support tickets
     */
    public function getSupportTickets(Request $request): JsonResponse
    {
        $this->validate($request, [
            'status' => 'sometimes|string',
        ]);

        $tickets = $this->customerPortalService->getSupportTickets(
            $request->customer,
            $request->only(['status'])
        );

        return response()->json([
            'tickets' => $tickets->map(function ($ticket) {
                return [
                    'id' => $ticket->public_id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'message_count' => $ticket->messages->count(),
                    'unread_count' => $ticket->getUnreadCount(),
                    'created_at' => $ticket->created_at->toIso8601String(),
                    'last_reply_at' => $ticket->getLastReplyAt()?->toIso8601String(),
                    'is_open' => $ticket->isOpen(),
                ];
            }),
        ]);
    }

    /**
     * Create support ticket
     */
    public function createSupportTicket(Request $request): JsonResponse
    {
        $this->validate($request, [
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category' => 'sometimes|string|in:order,billing,product,technical,other',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'related_order_id' => 'nullable|string',
            'related_booking_id' => 'nullable|string',
            'related_products' => 'nullable|array',
        ]);

        try {
            $ticket = $this->customerPortalService->createSupportTicket(
                $request->customer,
                $request->only([
                    'subject', 'description', 'category', 'priority',
                    'related_order_id', 'related_booking_id', 'related_products'
                ])
            );

            return response()->json([
                'success' => true,
                'ticket' => [
                    'id' => $ticket->public_id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                ],
                'message' => 'Support ticket created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'TICKET_ERROR',
            ], 422);
        }
    }

    /**
     * Get support ticket details
     */
    public function getSupportTicket(Request $request, string $ticketPublicId): JsonResponse
    {
        $ticket = $this->customerPortalService->getSupportTickets($request->customer)
            ->where('public_id', $ticketPublicId)
            ->first();

        if (!$ticket) {
            return response()->json(['error' => 'Ticket not found'], 404);
        }

        // Mark as read by customer
        $this->customerPortalService->markTicketAsRead($request->customer, $ticketPublicId);

        return response()->json([
            'ticket' => [
                'id' => $ticket->public_id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at->toIso8601String(),
                'updated_at' => $ticket->updated_at->toIso8601String(),
                'is_open' => $ticket->isOpen(),
                'messages' => $ticket->messages->map(function ($message) {
                    return [
                        'id' => $message->public_id,
                        'message' => $message->message,
                        'author_type' => $message->author_type,
                        'author_name' => $message->getAuthorName(),
                        'is_internal' => $message->is_internal,
                        'created_at' => $message->created_at->toIso8601String(),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Add message to support ticket
     */
    public function addTicketMessage(Request $request, string $ticketPublicId): JsonResponse
    {
        $this->validate($request, [
            'message' => 'required|string|max:5000',
        ]);

        try {
            $ticket = $this->customerPortalService->addTicketMessage(
                $request->customer,
                $ticketPublicId,
                $request->message
            );

            return response()->json([
                'success' => true,
                'message' => 'Message added successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'TICKET_ERROR',
            ], 422);
        }
    }

    // ── Wishlist ───────────────────────────────────────────────────────────────

    /**
     * Get wishlist items
     */
    public function getWishlist(Request $request): JsonResponse
    {
        $this->validate($request, [
            'list_name' => 'sometimes|string|max:100',
        ]);

        $items = $this->customerPortalService->getWishlistItems(
            $request->customer,
            $request->get('list_name', 'default')
        );

        $listNames = $this->customerPortalService->getWishlistNames($request->customer);

        return response()->json([
            'wishlist' => [
                'items' => $items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product->public_id,
                        'variant_id' => $item->variant?->public_id,
                        'product_name' => $item->product_name,
                        'variant_name' => $item->variant_name,
                        'current_price' => $item->getCurrentPrice(),
                        'price_when_added' => number_format($item->price_when_added, 2),
                        'has_price_dropped' => $item->hasPriceDropped(),
                        'is_available' => $item->isAvailable(),
                        'image_url' => $item->product->getImageUrl(),
                        'list_name' => $item->list_name,
                        'added_at' => $item->created_at->toIso8601String(),
                    ];
                }),
                'available_lists' => $listNames,
                'current_list' => $request->get('list_name', 'default'),
            ],
        ]);
    }

    /**
     * Add item to wishlist
     */
    public function addToWishlist(Request $request): JsonResponse
    {
        $this->validate($request, [
            'product_id' => 'required|string',
            'variant_id' => 'nullable|string',
            'list_name' => 'sometimes|string|max:100',
        ]);

        try {
            $item = $this->customerPortalService->addToWishlist(
                $request->customer,
                $request->product_id,
                $request->variant_id,
                $request->get('list_name', 'default')
            );

            return response()->json([
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'list_name' => $item->list_name,
                ],
                'message' => 'Item added to wishlist',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'WISHLIST_ERROR',
            ], 422);
        }
    }

    /**
     * Remove item from wishlist
     */
    public function removeFromWishlist(Request $request, int $itemId): JsonResponse
    {
        $removed = $this->customerPortalService->removeFromWishlist($request->customer, $itemId);

        if ($removed) {
            return response()->json([
                'success' => true,
                'message' => 'Item removed from wishlist',
            ]);
        }

        return response()->json(['error' => 'Item not found'], 404);
    }

    // ── Notifications ──────────────────────────────────────────────────────────

    /**
     * Get customer notifications
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $notifications = $this->customerPortalService->getNotifications($request->customer);

        return response()->json([
            'notifications' => $notifications,
        ]);
    }
}