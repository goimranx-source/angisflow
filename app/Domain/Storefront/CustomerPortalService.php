<?php

declare(strict_types=1);

namespace App\Domain\Storefront;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Sales\CustomerDirectory;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\TenantContext;
use App\Models\Booking;
use App\Models\CustomerPortalSession;
use App\Models\CustomerSupportTicket;
use App\Models\CustomerWishlistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Customer Portal Service
 *
 * Manages customer self-service portal functionality including
 * authentication, order tracking, support tickets, and account management.
 */
class CustomerPortalService
{
    public function __construct(
        private TenantContext $tenantContext,
        private CustomerDirectory $customerDirectory
    ) {}

    // ── Authentication and Session Management ─────────────────────────────────

    /**
     * Authenticate customer and create session
     */
    public function authenticateCustomer(string $email, string $password, array $sessionData = []): array
    {
        $customer = Customer::where('business_id', $this->tenantContext->business()->id)
            ->where('email', $email)
            ->first();

        if (! $customer || ! Hash::check($password, $customer->password)) {
            throw new \InvalidArgumentException('Invalid email or password');
        }

        if (! $customer->is_active) {
            throw new \InvalidArgumentException('Account is inactive');
        }

        // Create session
        $session = CustomerPortalSession::createForCustomer($customer, $sessionData);

        return [
            'customer' => $customer,
            'session' => $session,
            'token' => $session->session_token,
        ];
    }

    /**
     * Register new customer account
     */
    public function registerCustomer(array $customerData): Customer
    {
        return DB::transaction(function () use ($customerData) {
            // Check if email already exists
            $existing = Customer::where('business_id', $this->tenantContext->business()->id)
                ->where('email', $customerData['email'])
                ->first();

            if ($existing) {
                throw new \InvalidArgumentException('Email address is already registered');
            }

            // Create customer
            $customer = $this->customerDirectory->create([
                'name' => $customerData['name'],
                'email' => $customerData['email'],
                'phone' => $customerData['phone'] ?? null,
                'password' => Hash::make($customerData['password']),
                'is_active' => true,
                'email_verified_at' => null, // Will be verified via email
            ]);

            // TODO: Send email verification

            return $customer;
        });
    }

    /**
     * Find session by token
     */
    public function findSession(string $token): ?CustomerPortalSession
    {
        return CustomerPortalSession::findByToken($token);
    }

    /**
     * Validate and refresh session
     */
    public function validateSession(string $token): ?CustomerPortalSession
    {
        $session = $this->findSession($token);

        if (! $session || ! $session->isValid()) {
            return null;
        }

        $session->updateActivity();

        return $session;
    }

    /**
     * Logout customer session
     */
    public function logout(string $token): bool
    {
        $session = $this->findSession($token);

        if ($session) {
            $session->revoke();

            return true;
        }

        return false;
    }

    // ── Account Management ────────────────────────────────────────────────────

    /**
     * Update customer profile
     */
    public function updateProfile(Customer $customer, array $data): Customer
    {
        $allowedFields = ['name', 'phone', 'preferences'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        $customer->update($updateData);

        return $customer;
    }

    /**
     * Change customer password
     */
    public function changePassword(Customer $customer, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $customer->password)) {
            throw new \InvalidArgumentException('Current password is incorrect');
        }

        $customer->update([
            'password' => Hash::make($newPassword),
        ]);

        // Revoke all other sessions for security
        CustomerPortalSession::revokeAllForCustomer($customer->id);
    }

    /**
     * Update customer addresses
     */
    public function updateAddresses(Customer $customer, array $addresses): Customer
    {
        $customer->update(['addresses' => $addresses]);

        return $customer;
    }

    // ── Order Management ──────────────────────────────────────────────────────

    /**
     * Get customer orders with pagination
     */
    public function getCustomerOrders(
        Customer $customer,
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $query = Order::where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->with(['lines.product', 'lines.variant']);

        // Apply filters
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'orders' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'total_pages' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total_items' => $orders->total(),
                'has_more' => $orders->hasMorePages(),
            ],
        ];
    }

    /**
     * Get order details for customer
     */
    public function getOrderDetails(Customer $customer, string $orderPublicId): ?Order
    {
        return Order::where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->where('public_id', $orderPublicId)
            ->with(['lines.product', 'lines.variant', 'payments', 'shipments'])
            ->first();
    }

    // ── Booking Management ────────────────────────────────────────────────────

    /**
     * Get customer bookings
     */
    public function getCustomerBookings(
        Customer $customer,
        array $filters = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        $query = Booking::where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->with(['service', 'resources']);

        // Apply filters
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['upcoming'])) {
            $query->where('scheduled_start', '>=', now());
        }

        if (! empty($filters['past'])) {
            $query->where('scheduled_end', '<', now());
        }

        $bookings = $query->orderBy('scheduled_start', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'bookings' => $bookings->items(),
            'pagination' => [
                'current_page' => $bookings->currentPage(),
                'total_pages' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total_items' => $bookings->total(),
                'has_more' => $bookings->hasMorePages(),
            ],
        ];
    }

    /**
     * Cancel customer booking
     */
    public function cancelBooking(Customer $customer, string $bookingPublicId, ?string $reason = null): Booking
    {
        $booking = Booking::where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->where('public_id', $bookingPublicId)
            ->firstOrFail();

        if (! in_array($booking->status, ['confirmed', 'pending'])) {
            throw new \InvalidArgumentException('Booking cannot be cancelled in current status');
        }

        // Check cancellation policy (24 hours notice)
        if ($booking->scheduled_start->diffInHours(now()) < 24) {
            throw new \InvalidArgumentException('Bookings can only be cancelled with 24 hours notice');
        }

        $booking->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        return $booking;
    }

    // ── Support Ticket Management ─────────────────────────────────────────────

    /**
     * Create support ticket
     */
    public function createSupportTicket(Customer $customer, array $ticketData): CustomerSupportTicket
    {
        return CustomerSupportTicket::createTicket([
            'account_id' => $customer->account_id,
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'subject' => $ticketData['subject'],
            'description' => $ticketData['description'],
            'category' => $ticketData['category'] ?? 'other',
            'priority' => $ticketData['priority'] ?? 'normal',
            'related_order_id' => $ticketData['related_order_id'] ?? null,
            'related_booking_id' => $ticketData['related_booking_id'] ?? null,
            'related_products' => $ticketData['related_products'] ?? null,
        ]);
    }

    /**
     * Get customer support tickets
     */
    public function getSupportTickets(Customer $customer, array $filters = []): Collection
    {
        $query = CustomerSupportTicket::where('customer_id', $customer->id);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->with('messages')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Add message to support ticket
     */
    public function addTicketMessage(Customer $customer, string $ticketPublicId, string $message): CustomerSupportTicket
    {
        $ticket = CustomerSupportTicket::where('customer_id', $customer->id)
            ->where('public_id', $ticketPublicId)
            ->firstOrFail();

        if (! $ticket->isOpen()) {
            throw new \InvalidArgumentException('Cannot add message to closed ticket');
        }

        $ticket->addMessage([
            'message' => $message,
            'author_type' => 'customer',
            'author_id' => $customer->id,
        ]);

        return $ticket;
    }

    /**
     * Mark ticket messages as read by customer
     */
    public function markTicketAsRead(Customer $customer, string $ticketPublicId): void
    {
        $ticket = CustomerSupportTicket::where('customer_id', $customer->id)
            ->where('public_id', $ticketPublicId)
            ->firstOrFail();

        $ticket->markAsReadByCustomer();
    }

    // ── Wishlist Management ───────────────────────────────────────────────────

    /**
     * Add item to wishlist
     */
    public function addToWishlist(
        Customer $customer,
        string $productPublicId,
        ?string $variantPublicId = null,
        string $listName = 'default'
    ): CustomerWishlistItem {
        $product = Product::where('business_id', $customer->business_id)
            ->where('public_id', $productPublicId)
            ->where('is_active', true)
            ->firstOrFail();

        $variant = null;
        if ($variantPublicId) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('public_id', $variantPublicId)
                ->firstOrFail();
        }

        // Check if already in wishlist
        $existing = CustomerWishlistItem::where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->first();

        if ($existing) {
            // Move to specified list if different
            if ($existing->list_name !== $listName) {
                $existing->moveToList($listName);
            }

            return $existing;
        }

        // Price lives on the variant, never on the product — fall back to the
        // product's default variant when none was specified.
        $pricedVariant = $variant ?? $product->defaultVariant();
        $price = $pricedVariant?->price();

        // Add new item
        return CustomerWishlistItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'product_name' => $product->name,
            'variant_name' => $variant?->name,
            'price_when_added_minor' => $price?->minor ?? 0,
            'currency' => $price?->currency ?? $customer->currency ?? 'USD',
            'list_name' => $listName,
            'sort_order' => 0,
        ]);
    }

    /**
     * Remove item from wishlist
     */
    public function removeFromWishlist(Customer $customer, int $wishlistItemId): bool
    {
        $item = CustomerWishlistItem::where('customer_id', $customer->id)
            ->where('id', $wishlistItemId)
            ->first();

        if ($item) {
            $item->delete();

            return true;
        }

        return false;
    }

    /**
     * Get customer wishlist items
     */
    public function getWishlistItems(Customer $customer, string $listName = 'default'): Collection
    {
        return CustomerWishlistItem::forCustomer($customer->id)
            ->inList($listName)
            ->with(['product.media', 'variant'])
            ->ordered()
            ->get();
    }

    /**
     * Get all wishlist names for customer
     */
    public function getWishlistNames(Customer $customer): array
    {
        return CustomerWishlistItem::forCustomer($customer->id)
            ->select('list_name')
            ->distinct()
            ->pluck('list_name')
            ->toArray();
    }

    // ── Notifications and Alerts ──────────────────────────────────────────────

    /**
     * Get notifications for customer
     */
    public function getNotifications(Customer $customer): array
    {
        $notifications = [];

        // Price drop alerts
        $priceDropItems = CustomerWishlistItem::forCustomer($customer->id)
            ->withPriceDropNotifications()
            ->with('product')
            ->get()
            ->filter(function ($item) {
                return $item->hasPriceDropped() || $item->hasReachedTargetPrice();
            });

        foreach ($priceDropItems as $item) {
            $notifications[] = [
                'type' => 'price_drop',
                'title' => 'Price Drop Alert',
                'message' => "Price dropped for {$item->getDisplayName()}",
                'data' => $item,
                'created_at' => $item->updated_at,
            ];
        }

        // Back in stock alerts
        $stockItems = CustomerWishlistItem::forCustomer($customer->id)
            ->withStockNotifications()
            ->with('product')
            ->get()
            ->filter(function ($item) {
                return $item->isBackInStock();
            });

        foreach ($stockItems as $item) {
            $notifications[] = [
                'type' => 'back_in_stock',
                'title' => 'Back in Stock',
                'message' => "{$item->getDisplayName()} is back in stock",
                'data' => $item,
                'created_at' => $item->updated_at,
            ];
        }

        // Order updates
        $recentOrders = Order::where('customer_id', $customer->id)
            ->where('updated_at', '>=', now()->subDays(7))
            ->where('status', '!=', 'draft')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentOrders as $order) {
            $notifications[] = [
                'type' => 'order_update',
                'title' => 'Order Update',
                'message' => "Order #{$order->order_number} is now {$order->status}",
                'data' => $order,
                'created_at' => $order->updated_at,
            ];
        }

        // Sort by date
        usort($notifications, function ($a, $b) {
            return $b['created_at']->timestamp <=> $a['created_at']->timestamp;
        });

        return array_slice($notifications, 0, 10); // Limit to 10 most recent
    }

    // ── Customer Preferences ──────────────────────────────────────────────────

    /**
     * Update customer preferences
     */
    public function updatePreferences(Customer $customer, array $preferences): Customer
    {
        $current = $customer->preferences ?? [];
        $updated = array_merge($current, $preferences);

        $customer->update(['preferences' => $updated]);

        return $customer;
    }

    /**
     * Get customer dashboard data
     */
    public function getDashboardData(Customer $customer): array
    {
        return [
            'recent_orders' => Order::where('customer_id', $customer->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'upcoming_bookings' => Booking::where('customer_id', $customer->id)
                ->where('scheduled_start', '>=', now())
                ->where('status', 'confirmed')
                ->orderBy('scheduled_start')
                ->limit(5)
                ->get(),
            'open_tickets' => CustomerSupportTicket::where('customer_id', $customer->id)
                ->open()
                ->count(),
            'wishlist_count' => CustomerWishlistItem::forCustomer($customer->id)->count(),
            'notifications' => $this->getNotifications($customer),
        ];
    }
}
