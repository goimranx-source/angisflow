<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The two things the server serves over HTML
|------------------------------------------------------------------------------
|
| Everything else is /api/v1 — see routes/api.php.
|
*/

/*
| The email verification link.
|
| The one place a real navigation is unavoidable: a browser opening a link out
| of somebody's inbox is not running our JavaScript and cannot make an API call.
|
| EmailVerificationRequest does the work worth being careful about — the URL is
| signed, so its expiry and its user id cannot be edited, and the hash is
| checked against the address on the row rather than the one in the link. That
| last part is what stops a link issued for one address verifying a different
| one after the user changes it.
*/
Route::get('verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
    if (! $request->user()->hasVerifiedEmail() && $request->user()->markEmailAsVerified()) {
        event(new Illuminate\Auth\Events\Verified($request->user()));
    }

    return redirect('/profile?verified=1');
})->middleware(['auth', 'signed', 'throttle:6,1'])->name('verification.verify');

/*
|--------------------------------------------------------------------------
| Public Storefronts
|--------------------------------------------------------------------------
|
| Public storefront routes for customer-facing e-commerce functionality.
| These routes are accessible without authentication and provide product
| catalogs, shopping cart, and checkout capabilities.
*/
Route::prefix('storefront/{identifier}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [App\Http\Api\V1\Public\StorefrontController::class, 'getStorefront']);
    Route::get('products', [App\Http\Api\V1\Public\StorefrontController::class, 'getProducts']);
    Route::get('products/{productId}', [App\Http\Api\V1\Public\StorefrontController::class, 'getProduct']);
    Route::get('search', [App\Http\Api\V1\Public\StorefrontController::class, 'searchProducts']);
    Route::get('pages/{pageSlug}', [App\Http\Api\V1\Public\StorefrontController::class, 'getPage']);
    
    // Cart operations
    Route::get('cart', [App\Http\Api\V1\Public\StorefrontController::class, 'getCart']);
    Route::post('cart/add', [App\Http\Api\V1\Public\StorefrontController::class, 'addToCart']);
    Route::put('cart/update', [App\Http\Api\V1\Public\StorefrontController::class, 'updateCartItem']);
    Route::delete('cart/remove', [App\Http\Api\V1\Public\StorefrontController::class, 'removeFromCart']);
    Route::delete('cart/clear', [App\Http\Api\V1\Public\StorefrontController::class, 'clearCart']);
    
    // Checkout
    Route::post('checkout', [App\Http\Api\V1\Public\StorefrontController::class, 'checkout']);
});

/*
|--------------------------------------------------------------------------
| Public Booking Pages
|--------------------------------------------------------------------------
|
| Public booking page routes for appointment scheduling functionality.
| These routes allow customers to view services, check availability,
| and create bookings without authentication.
*/
Route::prefix('booking/{identifier}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [App\Http\Api\V1\Public\BookingPageController::class, 'getBookingPage']);
    Route::get('services', [App\Http\Api\V1\Public\BookingPageController::class, 'getServices']);
    Route::get('services/{serviceId}', [App\Http\Api\V1\Public\BookingPageController::class, 'getService']);
    Route::get('services/{serviceId}/availability', [App\Http\Api\V1\Public\BookingPageController::class, 'checkAvailability']);
    
    // Booking management
    Route::post('bookings', [App\Http\Api\V1\Public\BookingPageController::class, 'createBooking']);
    Route::get('bookings/{confirmationCode}', [App\Http\Api\V1\Public\BookingPageController::class, 'getBooking']);
    Route::put('bookings/{confirmationCode}/cancel', [App\Http\Api\V1\Public\BookingPageController::class, 'cancelBooking']);
    Route::put('bookings/{confirmationCode}/reschedule', [App\Http\Api\V1\Public\BookingPageController::class, 'rescheduleBooking']);
});

/*
|--------------------------------------------------------------------------
| Customer Portal
|--------------------------------------------------------------------------
|
| Customer self-service portal routes. Authentication routes are public,
| while account management routes require customer authentication.
*/
Route::prefix('portal')->middleware('throttle:60,1')->group(function () {
    // Public authentication routes
    Route::post('auth/login', [App\Http\Api\V1\Public\CustomerPortalController::class, 'login']);
    Route::post('auth/register', [App\Http\Api\V1\Public\CustomerPortalController::class, 'register']);
    
    // Authenticated customer routes
    Route::middleware(App\Http\Middleware\CustomerAuth::class)->group(function () {
        Route::post('auth/logout', [App\Http\Api\V1\Public\CustomerPortalController::class, 'logout']);
        Route::get('me', [App\Http\Api\V1\Public\CustomerPortalController::class, 'me']);
        Route::get('dashboard', [App\Http\Api\V1\Public\CustomerPortalController::class, 'dashboard']);
        
        // Profile management
        Route::put('profile', [App\Http\Api\V1\Public\CustomerPortalController::class, 'updateProfile']);
        Route::put('profile/password', [App\Http\Api\V1\Public\CustomerPortalController::class, 'changePassword']);
        Route::put('profile/addresses', [App\Http\Api\V1\Public\CustomerPortalController::class, 'updateAddresses']);
        
        // Orders
        Route::get('orders', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getOrders']);
        Route::get('orders/{orderPublicId}', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getOrder']);
        
        // Bookings
        Route::get('bookings', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getBookings']);
        Route::put('bookings/{bookingPublicId}/cancel', [App\Http\Api\V1\Public\CustomerPortalController::class, 'cancelBooking']);
        
        // Support tickets
        Route::get('support/tickets', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getSupportTickets']);
        Route::post('support/tickets', [App\Http\Api\V1\Public\CustomerPortalController::class, 'createSupportTicket']);
        Route::get('support/tickets/{ticketPublicId}', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getSupportTicket']);
        Route::post('support/tickets/{ticketPublicId}/messages', [App\Http\Api\V1\Public\CustomerPortalController::class, 'addTicketMessage']);
        
        // Wishlist
        Route::get('wishlist', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getWishlist']);
        Route::post('wishlist', [App\Http\Api\V1\Public\CustomerPortalController::class, 'addToWishlist']);
        Route::delete('wishlist/{itemId}', [App\Http\Api\V1\Public\CustomerPortalController::class, 'removeFromWishlist']);
        
        // Notifications
        Route::get('notifications', [App\Http\Api\V1\Public\CustomerPortalController::class, 'getNotifications']);
    });
});

/*
| The shell.
|
| Every other address gets the same document, and React decides what to draw
| from the URL. The exclusion keeps /api, the built assets and the health probe
| from being swallowed by it — a catch-all that answers everything will happily
| return an HTML page to a JSON client, and the resulting parse error is a long
| way from the actual mistake.
|
| From the first render onwards this route is never hit again: navigating inside
| the application is a client-side transition and touches the network only for
| the data the new screen needs.
*/
Route::get('/{any?}', SpaController::class)
    ->where('any', '^(?!api/|build/|storage/|up$|storefront/|booking/|portal/).*$')
    ->name('spa');
