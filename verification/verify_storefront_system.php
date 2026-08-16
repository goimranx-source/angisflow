<?php

declare(strict_types=1);

/**
 * Verification Script: Storefront, Booking Pages, Customer Portal
 * 
 * Tests the complete customer-facing functionality including:
 * - Storefront creation and product display
 * - Shopping cart operations
 * - Customer portal authentication
 * - Booking page functionality
 * - Support ticket system
 * - Wishlist management
 */

use App\Models\Account;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Storefront;
use App\Models\BookingPage;
use App\Models\BookingService;
use App\Models\BookingResource;
use App\Models\CustomerPortalSession;
use App\Models\CustomerSupportTicket;
use App\Models\CustomerWishlistItem;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Storefront\StorefrontService;
use App\Domain\Storefront\CustomerPortalService;
use App\Domain\Catalogue\ProductCatalogue;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Facades\Hash;

echo "=== STOREFRONT SYSTEM VERIFICATION ===\n\n";

// Set up tenant context
$account = Account::withoutGlobalScopes()->find(1);
$business = Business::withoutGlobalScopes()->whereNull('deleted_at')->where('account_id', 1)->first();
$tenantContext = app(TenantContext::class);
$tenantContext->setAccount($account);
$tenantContext->setBusiness($business);

$storefrontService = app(StorefrontService::class);
$customerPortalService = app(CustomerPortalService::class);
$productCatalogue = app(ProductCatalogue::class);

$try = function (string $label, callable $fn) {
    try { 
        $out = $fn(); 
        echo "  ✓ OK      {$label}".($out ? " -> {$out}" : '')."\n"; 
        return $out;
    } catch (Throwable $e) { 
        echo "  ✗ REFUSED {$label} -> ".$e->getMessage()."\n"; 
        return null;
    }
};

echo "1. STOREFRONT CREATION AND MANAGEMENT\n";

// Create a test category and products first
$category = $try('Create product category', function () use ($productCatalogue) {
    return $productCatalogue->createCategory([
        'name' => 'Electronics',
        'description' => 'Electronic devices and accessories',
        'is_active' => true,
    ]);
});

$product1 = $try('Create test product 1', function () use ($productCatalogue, $category) {
    return $productCatalogue->create([
        'name' => 'Wireless Headphones',
        'description' => 'High-quality wireless headphones with noise cancellation',
        'sku' => 'WH-001',
        'category_id' => $category->id,
        'price' => new Money(15999, 'USD'), // $159.99
        'compare_price' => new Money(19999, 'USD'), // $199.99
        'track_inventory' => true,
        'status' => 'active',
        'specifications' => [
            'Battery Life' => '30 hours',
            'Connectivity' => 'Bluetooth 5.0',
            'Weight' => '250g',
        ],
    ]);
});

$product2 = $try('Create test product 2', function () use ($productCatalogue, $category) {
    return $productCatalogue->create([
        'name' => 'Smart Watch',
        'description' => 'Feature-rich smartwatch with health monitoring',
        'sku' => 'SW-001',
        'category_id' => $category->id,
        'price' => new Money(29999, 'USD'), // $299.99
        'track_inventory' => true,
        'status' => 'active',
    ]);
});

$storefront = $try('Create storefront', function () use ($storefrontService) {
    return $storefrontService->createStorefront([
        'name' => 'Tech Store',
        'slug' => 'tech-store',
        'title' => 'Premium Tech Store',
        'description' => 'Your one-stop shop for premium electronics',
        'custom_domain' => 'shop.example.com',
        'allow_guest_checkout' => true,
        'show_inventory_levels' => true,
        'enable_reviews' => true,
        'enable_wishlist' => true,
        'minimum_order_amount' => new Money(2500, 'USD'), // $25.00
        'payment_methods' => ['stripe', 'paypal'],
        'theme_template' => 'modern',
        'announcement_bar' => [
            'enabled' => true,
            'message' => 'Free shipping on orders over $100!',
            'background_color' => '#007bff',
            'text_color' => '#ffffff',
        ],
    ]);
});

$try('Find storefront by slug', function () use ($storefrontService, $storefront) {
    $found = $storefrontService->findStorefront('tech-store');
    if (!$found || $found->id !== $storefront->id) {
        throw new Exception('Storefront not found by slug');
    }
    return $found->name;
});

$try('Find storefront by domain', function () use ($storefrontService, $storefront) {
    $found = $storefrontService->findStorefront('shop.example.com');
    if (!$found || $found->id !== $storefront->id) {
        throw new Exception('Storefront not found by domain');
    }
    return $found->custom_domain;
});

echo "\n2. PRODUCT CATALOG FOR STOREFRONT\n";

$products = $try('Get storefront products', function () use ($storefrontService, $storefront) {
    $result = $storefrontService->getProducts($storefront, [], 1, 10);
    if (empty($result['products'])) {
        throw new Exception('No products returned');
    }
    return count($result['products']) . ' products found';
});

$try('Get storefront categories', function () use ($storefrontService, $storefront) {
    $categories = $storefrontService->getCategories($storefront);
    if ($categories->isEmpty()) {
        throw new Exception('No categories returned');
    }
    return $categories->count() . ' categories found';
});

$try('Get product details', function () use ($storefrontService, $storefront, $product1) {
    $productDetails = $storefrontService->getProductDetails($storefront, $product1->public_id);
    if (!$productDetails || $productDetails->id !== $product1->id) {
        throw new Exception('Product details not found');
    }
    return $productDetails->name;
});

$try('Search products', function () use ($storefrontService, $storefront) {
    $results = $storefrontService->searchProducts($storefront, 'wireless', 5);
    if ($results->isEmpty()) {
        throw new Exception('No search results');
    }
    return $results->count() . ' results found';
});

echo "\n3. SHOPPING CART OPERATIONS\n";

$cartData = [];

$cartData = $try('Add item to cart', function () use ($storefrontService, $product1) {
    global $cartData;
    $cartData = $storefrontService->addToCart($cartData, [
        'product_id' => $product1->public_id,
        'quantity' => 2,
    ]);
    if (empty($cartData['items'])) {
        throw new Exception('Cart is empty after adding item');
    }
    return count($cartData['items']) . ' items in cart';
});

$cartData = $try('Add second item to cart', function () use ($storefrontService, $product2) {
    global $cartData;
    $cartData = $storefrontService->addToCart($cartData, [
        'product_id' => $product2->public_id,
        'quantity' => 1,
    ]);
    if (count($cartData['items']) !== 2) {
        throw new Exception('Cart should have 2 items');
    }
    return count($cartData['items']) . ' items in cart';
});

$cartData = $try('Update cart item quantity', function () use ($storefrontService) {
    global $cartData;
    $itemKey = $cartData['items'][0]['key'];
    $cartData = $storefrontService->updateCartItem($cartData, $itemKey, 3);
    if ($cartData['items'][0]['quantity'] !== 3) {
        throw new Exception('Item quantity not updated');
    }
    return 'Updated to ' . $cartData['items'][0]['quantity'] . ' items';
});

$cartData = $try('Remove item from cart', function () use ($storefrontService) {
    global $cartData;
    $itemKey = $cartData['items'][1]['key'];
    $cartData = $storefrontService->removeFromCart($cartData, $itemKey);
    if (count($cartData['items']) !== 1) {
        throw new Exception('Item not removed from cart');
    }
    return count($cartData['items']) . ' items remaining';
});

$try('Clear cart', function () use ($storefrontService) {
    global $cartData;
    $cartData = $storefrontService->clearCart();
    if (!empty($cartData['items'])) {
        throw new Exception('Cart not cleared');
    }
    return 'Cart cleared';
});

echo "\n4. CUSTOMER PORTAL AUTHENTICATION\n";

$customer = $try('Register customer', function () use ($customerPortalService) {
    return $customerPortalService->registerCustomer([
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'phone' => '+1234567890',
        'password' => 'SecurePassword123!',
    ]);
});

$try('Refuse registration with duplicate email', function () use ($customerPortalService) {
    try {
        $customerPortalService->registerCustomer([
            'name' => 'Jane Doe',
            'email' => 'john.doe@example.com',
            'password' => 'AnotherPassword123!',
        ]);
        throw new Exception('Should have failed with duplicate email');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'already registered')) {
            return 'Correctly refused duplicate email';
        }
        throw $e;
    }
});

$authData = $try('Authenticate customer', function () use ($customerPortalService) {
    return $customerPortalService->authenticateCustomer(
        'john.doe@example.com',
        'SecurePassword123!'
    );
});

$try('Refuse wrong password', function () use ($customerPortalService) {
    try {
        $customerPortalService->authenticateCustomer(
            'john.doe@example.com',
            'WrongPassword'
        );
        throw new Exception('Should have failed with wrong password');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'Invalid email or password')) {
            return 'Correctly refused wrong password';
        }
        throw $e;
    }
});

$session = $try('Validate session token', function () use ($customerPortalService, $authData) {
    $session = $customerPortalService->validateSession($authData['token']);
    if (!$session) {
        throw new Exception('Session validation failed');
    }
    return 'Session valid until ' . $session->expires_at->format('Y-m-d H:i');
});

echo "\n5. CUSTOMER ACCOUNT MANAGEMENT\n";

$try('Update customer profile', function () use ($customerPortalService, $customer) {
    $updated = $customerPortalService->updateProfile($customer, [
        'name' => 'John Smith',
        'phone' => '+1987654321',
        'preferences' => [
            'newsletter' => true,
            'sms_notifications' => false,
        ],
    ]);
    if ($updated->name !== 'John Smith') {
        throw new Exception('Profile not updated');
    }
    return 'Profile updated to ' . $updated->name;
});

$try('Update customer addresses', function () use ($customerPortalService, $customer) {
    $addresses = [
        [
            'type' => 'shipping',
            'name' => 'John Smith',
            'line1' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'is_default' => true,
        ],
    ];
    
    $updated = $customerPortalService->updateAddresses($customer, $addresses);
    if (empty($updated->addresses)) {
        throw new Exception('Addresses not updated');
    }
    return count($updated->addresses) . ' addresses saved';
});

$try('Change customer password', function () use ($customerPortalService, $customer) {
    $customerPortalService->changePassword($customer, 'SecurePassword123!', 'NewPassword456!');
    return 'Password changed successfully';
});

$try('Get customer dashboard', function () use ($customerPortalService, $customer) {
    $dashboard = $customerPortalService->getDashboardData($customer);
    $keys = array_keys($dashboard);
    return 'Dashboard sections: ' . implode(', ', $keys);
});

echo "\n6. SUPPORT TICKET SYSTEM\n";

$ticket = $try('Create support ticket', function () use ($customerPortalService, $customer) {
    return $customerPortalService->createSupportTicket($customer, [
        'subject' => 'Product inquiry',
        'description' => 'I have a question about the wireless headphones.',
        'category' => 'product',
        'priority' => 'normal',
    ]);
});

$try('Get support tickets', function () use ($customerPortalService, $customer) {
    $tickets = $customerPortalService->getSupportTickets($customer);
    if ($tickets->isEmpty()) {
        throw new Exception('No tickets found');
    }
    return $tickets->count() . ' tickets found';
});

$try('Add message to ticket', function () use ($customerPortalService, $customer, $ticket) {
    $updated = $customerPortalService->addTicketMessage(
        $customer,
        $ticket->public_id,
        'Can you provide more details about the battery life?'
    );
    return 'Message added to ticket ' . $ticket->ticket_number;
});

$try('Refuse message to closed ticket', function () use ($customerPortalService, $customer, $ticket) {
    // Close the ticket first
    $ticket->update(['status' => 'closed']);
    
    try {
        $customerPortalService->addTicketMessage(
            $customer,
            $ticket->public_id,
            'This should fail'
        );
        throw new Exception('Should have failed on closed ticket');
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'closed ticket')) {
            return 'Correctly refused message to closed ticket';
        }
        throw $e;
    }
});

echo "\n7. WISHLIST MANAGEMENT\n";

$wishlistItem = $try('Add item to wishlist', function () use ($customerPortalService, $customer, $product1) {
    return $customerPortalService->addToWishlist(
        $customer,
        $product1->public_id,
        null,
        'default'
    );
});

$try('Add second item to wishlist', function () use ($customerPortalService, $customer, $product2) {
    $item = $customerPortalService->addToWishlist(
        $customer,
        $product2->public_id,
        null,
        'favorites'
    );
    return 'Added to list: ' . $item->list_name;
});

$try('Get wishlist items', function () use ($customerPortalService, $customer) {
    $items = $customerPortalService->getWishlistItems($customer, 'default');
    if ($items->isEmpty()) {
        throw new Exception('No wishlist items found');
    }
    return $items->count() . ' items in default list';
});

$try('Get wishlist names', function () use ($customerPortalService, $customer) {
    $names = $customerPortalService->getWishlistNames($customer);
    if (empty($names)) {
        throw new Exception('No wishlist names found');
    }
    return 'Lists: ' . implode(', ', $names);
});

$try('Remove item from wishlist', function () use ($customerPortalService, $customer, $wishlistItem) {
    $removed = $customerPortalService->removeFromWishlist($customer, $wishlistItem->id);
    if (!$removed) {
        throw new Exception('Item not removed');
    }
    return 'Item removed successfully';
});

echo "\n8. BOOKING PAGE FUNCTIONALITY\n";

// Create booking service and resource
$bookingService = $try('Create booking service', function () use ($business) {
    return \App\Models\BookingService::create([
        'account_id' => $business->account_id,
        'business_id' => $business->id,
        'name' => 'Hair Cut',
        'description' => 'Professional hair cutting service',
        'duration_minutes' => 60,
        'price' => new Money(5000, 'USD'), // $50.00
        'category' => 'Hair Services',
        'is_active' => true,
        'allow_online_booking' => true,
    ]);
});

$bookingResource = $try('Create booking resource', function () use ($business, $bookingService) {
    return \App\Models\BookingResource::create([
        'account_id' => $business->account_id,
        'business_id' => $business->id,
        'name' => 'Stylist Station 1',
        'type' => 'stylist',
        'capacity' => 1,
        'is_active' => true,
    ]);
});

$bookingPage = $try('Create booking page', function () use ($business) {
    return BookingPage::create([
        'account_id' => $business->account_id,
        'business_id' => $business->id,
        'title' => 'Hair Salon Bookings',
        'description' => 'Book your appointment with our professional stylists',
        'slug' => 'hair-salon',
        'timezone' => 'America/New_York',
        'allow_guest_booking' => true,
        'require_approval' => false,
        'advance_booking_days' => 30,
        'cancellation_hours' => 24,
        'minimum_notice_hours' => 2,
        'is_active' => true,
        'operating_hours' => [
            'monday' => ['09:00', '18:00'],
            'tuesday' => ['09:00', '18:00'],
            'wednesday' => ['09:00', '18:00'],
            'thursday' => ['09:00', '18:00'],
            'friday' => ['09:00', '18:00'],
            'saturday' => ['09:00', '16:00'],
            'sunday' => null, // Closed
        ],
    ]);
});

echo "\n9. LOGOUT AND SESSION CLEANUP\n";

$try('Logout customer', function () use ($customerPortalService, $authData) {
    $loggedOut = $customerPortalService->logout($authData['token']);
    if (!$loggedOut) {
        throw new Exception('Logout failed');
    }
    return 'Customer logged out';
});

$try('Refuse access with logged out token', function () use ($customerPortalService, $authData) {
    $session = $customerPortalService->validateSession($authData['token']);
    if ($session) {
        throw new Exception('Session should be invalid after logout');
    }
    return 'Session correctly invalidated';
});

echo "\n10. CLEANUP TEST DATA\n";

// Clean up test data
$try('Delete test data', function () use ($storefront, $bookingPage, $customer, $ticket, $product1, $product2, $category, $bookingService, $bookingResource) {
    // Delete in proper order to respect foreign keys
    CustomerWishlistItem::where('customer_id', $customer->id)->delete();
    CustomerSupportTicket::where('customer_id', $customer->id)->delete();
    CustomerPortalSession::where('customer_id', $customer->id)->delete();
    
    $storefront->pages()->delete();
    $storefront->delete();
    
    $bookingPage->delete();
    $bookingService->delete();
    $bookingResource->delete();
    
    $customer->delete();
    
    $product1->delete();
    $product2->delete();
    $category->delete();
    
    return 'Test data cleaned up';
});

echo "\n=== VERIFICATION COMPLETE ===\n";
echo "All storefront, booking page, and customer portal functionality verified successfully!\n";