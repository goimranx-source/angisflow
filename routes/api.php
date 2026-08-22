<?php

declare(strict_types=1);

use App\Http\Api\V1\Auth\EmailVerificationEndpoint;
use App\Http\Api\V1\Auth\LoginEndpoint;
use App\Http\Api\V1\Auth\PasskeyEndpoint;
use App\Http\Api\V1\Auth\PasswordEndpoint;
use App\Http\Api\V1\Auth\RegisterEndpoint;
use App\Http\Api\V1\ReportsEndpoint;
use App\Http\Api\V1\VaultEndpoint;
use App\Http\Api\V1\Auth\TwoFactorEndpoint;
use App\Http\Api\V1\EntitlementEndpoint;
use App\Http\Api\V1\OperatorEndpoint;
use App\Http\Api\V1\AssistantEndpoint;
use App\Http\Api\V1\BillingEndpoint;
use App\Http\Api\V1\BootstrapEndpoint;
use App\Http\Api\V1\CategoryDashboardEndpoint;
use App\Http\Api\V1\DashboardPanelsEndpoint;
use App\Http\Api\V1\BusinessEndpoint;
use App\Http\Api\V1\CourierEndpoint;
use App\Http\Api\V1\CouriersEndpoint;
use App\Http\Api\V1\IntegrationsEndpoint;
use App\Http\Api\V1\OrdersEndpoint;
use App\Http\Api\V1\StorefrontsEndpoint;
use App\Http\Api\V1\CurrencyEndpoint;
use App\Http\Api\V1\DashboardEndpoint;
use App\Http\Api\V1\DashboardExportEndpoint;
use App\Http\Api\V1\HealthEndpoint;
use App\Http\Api\V1\MediaEndpoint;
use App\Http\Api\V1\ProfileEndpoint;
use App\Http\Api\V1\OrganiserEndpoint;
use App\Http\Api\V1\SettingsEndpoint;
use App\Http\Api\V1\WorkspaceEndpoint;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\Webhooks\CourierWebhookController;
use App\Http\Api\Webhooks\IntegrationWebhookController;
use App\Http\Api\Webhooks\MessageWebhookController;
use App\Http\Api\Webhooks\WebchatController;

/*
|------------------------------------------------------------------------------
| The API — v1
|------------------------------------------------------------------------------
|
| The entire surface the application talks to. The server renders one HTML
| document (SpaController) and nothing else; every screen, every figure and
| every action in the product goes through a route in this file.
|
| ── Groups, and what each one guarantees ────────────────────────────────────
|
|   open      no session needed. Health checks and the boot payload, which
|             deliberately answers 200 with `auth: null` for a guest — "am I
|             still signed in" is a question whose answer is sometimes no, and
|             that is not an error.
|
|   guest     signed out only. Sign-in, sign-up, password resets. Throttled by
|             address because there is no account to charge yet.
|
|   auth      signed in, but not necessarily past the second factor. The 2FA
|             challenge lives here — it has to, or somebody who has not
|             answered it could never be shown the question.
|
|   session   signed in, past 2FA, tenant resolved, account in good standing.
|             Everything that touches a subscriber's data.
|
|   sensitive session, plus a password typed in the last few minutes. Anything
|             that changes how somebody proves who they are.
|
*/

// ── Operator admin panel ─────────────────────────────────────────────────────
//
// Platform-staff only. The 'operator' middleware returns 403 for everyone else.
// No account.usable check — operators need to reach suspended accounts.

Route::middleware(['auth', 'two-factor', 'operator', 'throttle:api'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('accounts',                          [OperatorEndpoint::class, 'accounts'])->name('accounts.index');
    Route::get('accounts/{id}',                     [OperatorEndpoint::class, 'accountDetail'])->name('accounts.show');
    Route::post('accounts/{id}/suspend',            [OperatorEndpoint::class, 'suspend'])->name('accounts.suspend');
    Route::post('accounts/{id}/unsuspend',          [OperatorEndpoint::class, 'unsuspend'])->name('accounts.unsuspend');
    Route::post('accounts/{id}/extend-trial',       [OperatorEndpoint::class, 'extendTrial'])->name('accounts.extend-trial');
    Route::post('accounts/{id}/change-plan',        [OperatorEndpoint::class, 'changePlan'])->name('accounts.change-plan');
    Route::get('plans',                             [OperatorEndpoint::class, 'plans'])->name('plans.index');
    Route::post('workspaces/{id}/grant-module',     [OperatorEndpoint::class, 'grantModule'])->name('workspaces.grant-module');
    Route::post('workspaces/{id}/revoke-module',    [OperatorEndpoint::class, 'revokeModule'])->name('workspaces.revoke-module');
    Route::delete('workspaces/{id}/overrides/{key}',[OperatorEndpoint::class, 'removeOverride'])->name('workspaces.overrides.destroy');
});



Route::get('health', HealthEndpoint::class)->name('health');

Route::middleware(['tenant.optional'])->group(function () {
    Route::get('bootstrap', [BootstrapEndpoint::class, 'show'])->name('bootstrap');
});

Route::get('modules', [BootstrapEndpoint::class, 'modules'])->name('modules');

// ── Guest ────────────────────────────────────────────────────────────────────

Route::middleware(['guest', 'throttle:auth'])->group(function () {
    Route::post('auth/login', [LoginEndpoint::class, 'store'])->name('auth.login');
    Route::post('auth/register', [RegisterEndpoint::class, 'store'])->name('auth.register');

    // Passkey sign-in. Two steps because WebAuthn is a challenge and a
    // response: the server issues something random, the device signs it.
    Route::post('auth/passkey/options', [PasskeyEndpoint::class, 'loginOptions'])->name('auth.passkey.options');
    Route::post('auth/passkey/login', [PasskeyEndpoint::class, 'login'])->name('auth.passkey.login');

    Route::post('auth/password/reset', [PasswordEndpoint::class, 'reset'])->name('auth.password.reset');
});

Route::middleware(['guest', 'throttle:mail'])->group(function () {
    Route::post('auth/password/forgot', [PasswordEndpoint::class, 'forgot'])->name('auth.password.forgot');
});

// ── Signed in, second factor not yet required ────────────────────────────────

Route::middleware(['auth', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [LoginEndpoint::class, 'destroy'])->name('auth.logout');

    Route::get('auth/two-factor', [TwoFactorEndpoint::class, 'status'])->name('auth.two-factor.status');
    Route::post('auth/two-factor/challenge', [TwoFactorEndpoint::class, 'challenge'])
        ->middleware('throttle:auth')->name('auth.two-factor.challenge');

    Route::post('auth/password/confirm', [PasswordEndpoint::class, 'confirm'])
        ->middleware('throttle:auth')->name('auth.password.confirm');

    Route::post('auth/email/resend', [EmailVerificationEndpoint::class, 'resend'])
        ->middleware('throttle:mail')->name('auth.email.resend');
});

// ── The application ──────────────────────────────────────────────────────────

Route::middleware(['auth', 'two-factor', 'tenant', 'account.usable', 'throttle:api'])->group(function () {

    Route::get('dashboard', [DashboardEndpoint::class, 'index'])
        ->middleware('can:dashboard.view')->name('dashboard');

    Route::get('category-dashboard', [CategoryDashboardEndpoint::class, 'show'])
        ->name('category-dashboard');

    /*
    | The dashboard as a file — PDF or spreadsheet.
    |
    | Outside the dashboard.* prefix group below because those are JSON panels
    | and this returns a download; sharing their name prefix would make the
    | route list read as though it were another panel.
    */
    Route::get('dashboard-export', DashboardExportEndpoint::class)
        ->middleware('can:dashboard.view')->name('dashboard.export');

    /*
    | The dashboard's panels.
    |
    | Each panel fetches its own slice so a slow one never holds up the rest.
    | All of them read the real tables and return whatever is there — which is
    | nothing at all until the trading modules ship. An empty list is the
    | honest answer to "what sold this month" before anything has sold.
    */
    Route::prefix('dashboard')->name('dashboard.')->middleware('can:dashboard.view')->group(function () {
        Route::get('sales-chart',   [DashboardPanelsEndpoint::class, 'salesChart'])->name('sales-chart');
        Route::get('cash-flow',     [DashboardPanelsEndpoint::class, 'cashFlow'])->name('cash-flow');
        Route::get('expense-breakdown', [DashboardPanelsEndpoint::class, 'expenseBreakdown'])->name('expense-breakdown');
        Route::get('top-products',  [DashboardPanelsEndpoint::class, 'topProducts'])->name('top-products');
        Route::get('recent-orders', [DashboardPanelsEndpoint::class, 'recentOrders'])->name('recent-orders');
        Route::get('top-customers', [DashboardPanelsEndpoint::class, 'topCustomers'])->name('top-customers');
        Route::get('pending-tasks', [DashboardPanelsEndpoint::class, 'pendingTasks'])->name('pending-tasks');
        Route::get('quick-actions', [DashboardPanelsEndpoint::class, 'quickActions'])->name('quick-actions');
    });

    // What the current workspace's plan permits — used by billing screen and
    // operator panel. Readable by anyone who can view settings.
    Route::get('entitlement', [EntitlementEndpoint::class, 'show'])
        ->middleware('can:settings.view')->name('entitlement');

    /*
    | Financial reports — P&L, balance sheet, trial balance, receivables aging,
    | chart of accounts, and account ledger.
    |
    | All figures are computed from journal_lines on demand. No cached balance
    | table to drift from the postings beneath it.
    */
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('profit-and-loss',   [ReportsEndpoint::class, 'profitAndLoss'])->name('pl');
        Route::get('balance-sheet',     [ReportsEndpoint::class, 'balanceSheet'])->name('bs');
        Route::get('trial-balance',     [ReportsEndpoint::class, 'trialBalance'])->name('tb');
        Route::get('receivables-aging', [ReportsEndpoint::class, 'receivablesAging'])->name('aging');
    });

    // Chart of accounts — readable by anyone who can view transactions.
    Route::get('accounts', [ReportsEndpoint::class, 'accounts'])
        ->middleware('can:transactions.view')->name('accounts.index');
    Route::get('accounts/{id}/ledger', [ReportsEndpoint::class, 'accountLedger'])
        ->middleware('can:transactions.view')->name('accounts.ledger');

    /*
    | Credential vault — encrypted storage for API keys, OAuth tokens and
    | other secrets used by integrations.
    |
    | List and store require settings.edit. Reveal sits behind password.confirm
    | as well — a recent password confirmation is required to read a payload,
    | so a stolen session cookie is not enough to extract secrets.
    */
    Route::prefix('credentials')->name('credentials.')->middleware('can:settings.edit')->group(function () {
        Route::get('/',        [VaultEndpoint::class, 'index'])->name('index');
        Route::post('/',       [VaultEndpoint::class, 'store'])->name('store');
        Route::get('{id}',     [VaultEndpoint::class, 'show'])->name('show');
        Route::patch('{id}/rotate', [VaultEndpoint::class, 'rotate'])->name('rotate');
        Route::delete('{id}',  [VaultEndpoint::class, 'revoke'])->name('revoke');
    });

    // Reveal is behind password.confirm — a recent password is required.
    Route::get('credentials/{id}/reveal', [VaultEndpoint::class, 'reveal'])
        ->middleware(['can:settings.edit', 'password.confirm'])
        ->name('credentials.reveal');

    /*
    | Growth Marketing — campaigns, loyalty, reviews
    |
    | Multi-channel marketing campaigns with A/B testing, comprehensive loyalty
    | programs with points and rewards, and automated review request campaigns
    | with incentives for customer engagement and retention.
    */
    Route::prefix('growth')->name('growth.')->group(function () {
        // Marketing Campaigns
        Route::prefix('campaigns')->name('campaigns.')->group(function () {
            Route::get('/', [\App\Http\Api\V1\CampaignController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Api\V1\CampaignController::class, 'store'])->name('store');
            Route::get('{id}', [\App\Http\Api\V1\CampaignController::class, 'show'])->name('show');
            Route::patch('{id}', [\App\Http\Api\V1\CampaignController::class, 'update'])->name('update');
            Route::delete('{id}', [\App\Http\Api\V1\CampaignController::class, 'destroy'])->name('destroy');
            Route::post('{id}/launch', [\App\Http\Api\V1\CampaignController::class, 'launch'])->name('launch');
            Route::post('{id}/pause', [\App\Http\Api\V1\CampaignController::class, 'pause'])->name('pause');
            Route::post('{id}/resume', [\App\Http\Api\V1\CampaignController::class, 'resume'])->name('resume');
            Route::get('{id}/analytics', [\App\Http\Api\V1\CampaignController::class, 'analytics'])->name('analytics');
            Route::post('{id}/duplicate', [\App\Http\Api\V1\CampaignController::class, 'duplicate'])->name('duplicate');
        });

        // Loyalty Programs
        Route::prefix('loyalty')->name('loyalty.')->group(function () {
            // Programs
            Route::get('programs', [\App\Http\Api\V1\LoyaltyController::class, 'programs'])->name('programs.index');
            Route::post('programs', [\App\Http\Api\V1\LoyaltyController::class, 'createProgram'])->name('programs.store');
            Route::get('programs/{id}', [\App\Http\Api\V1\LoyaltyController::class, 'showProgram'])->name('programs.show');
            Route::patch('programs/{id}', [\App\Http\Api\V1\LoyaltyController::class, 'updateProgram'])->name('programs.update');
            
            // Memberships
            Route::get('memberships', [\App\Http\Api\V1\LoyaltyController::class, 'memberships'])->name('memberships.index');
            Route::post('memberships', [\App\Http\Api\V1\LoyaltyController::class, 'enrollCustomer'])->name('memberships.store');
            Route::get('memberships/{id}', [\App\Http\Api\V1\LoyaltyController::class, 'showMembership'])->name('memberships.show');
            Route::patch('memberships/{id}', [\App\Http\Api\V1\LoyaltyController::class, 'updateMembership'])->name('memberships.update');
            Route::get('memberships/{id}/history', [\App\Http\Api\V1\LoyaltyController::class, 'pointHistory'])->name('memberships.history');
            
            // Points
            Route::post('points/award', [\App\Http\Api\V1\LoyaltyController::class, 'awardPoints'])->name('points.award');
            Route::post('points/redeem', [\App\Http\Api\V1\LoyaltyController::class, 'redeemPoints'])->name('points.redeem');
            Route::post('points/adjust', [\App\Http\Api\V1\LoyaltyController::class, 'adjustPoints'])->name('points.adjust');
            
            // Rewards
            Route::get('rewards', [\App\Http\Api\V1\LoyaltyController::class, 'rewards'])->name('rewards.index');
            Route::post('rewards', [\App\Http\Api\V1\LoyaltyController::class, 'createReward'])->name('rewards.store');
            Route::post('rewards/redeem', [\App\Http\Api\V1\LoyaltyController::class, 'redeemReward'])->name('rewards.redeem');
            
            // Analytics
            Route::get('analytics', [\App\Http\Api\V1\LoyaltyController::class, 'analytics'])->name('analytics');
        });

        // Review Incentive Campaigns
        Route::prefix('reviews')->name('reviews.')->group(function () {
            // Campaigns
            Route::get('campaigns', [\App\Http\Api\V1\ReviewIncentiveController::class, 'campaigns'])->name('campaigns.index');
            Route::post('campaigns', [\App\Http\Api\V1\ReviewIncentiveController::class, 'createCampaign'])->name('campaigns.store');
            Route::get('campaigns/{id}', [\App\Http\Api\V1\ReviewIncentiveController::class, 'showCampaign'])->name('campaigns.show');
            Route::patch('campaigns/{id}', [\App\Http\Api\V1\ReviewIncentiveController::class, 'updateCampaign'])->name('campaigns.update');
            Route::delete('campaigns/{id}', [\App\Http\Api\V1\ReviewIncentiveController::class, 'destroyCampaign'])->name('campaigns.destroy');
            Route::post('campaigns/{id}/toggle', [\App\Http\Api\V1\ReviewIncentiveController::class, 'toggleCampaign'])->name('campaigns.toggle');
            Route::get('campaigns/{id}/analytics', [\App\Http\Api\V1\ReviewIncentiveController::class, 'campaignAnalytics'])->name('campaigns.analytics');
            
            // Requests
            Route::get('requests', [\App\Http\Api\V1\ReviewIncentiveController::class, 'requests'])->name('requests.index');
            Route::post('requests', [\App\Http\Api\V1\ReviewIncentiveController::class, 'createRequest'])->name('requests.store');
            Route::get('requests/{id}', [\App\Http\Api\V1\ReviewIncentiveController::class, 'showRequest'])->name('requests.show');
            Route::post('requests/{id}/send', [\App\Http\Api\V1\ReviewIncentiveController::class, 'sendRequest'])->name('requests.send');
            Route::post('requests/{id}/resend', [\App\Http\Api\V1\ReviewIncentiveController::class, 'resendRequest'])->name('requests.resend');
            Route::post('orders/process', [\App\Http\Api\V1\ReviewIncentiveController::class, 'processOrder'])->name('orders.process');
            
            // Analytics
            Route::get('analytics', [\App\Http\Api\V1\ReviewIncentiveController::class, 'overallAnalytics'])->name('analytics');
        });
    });

    /*
    | Partners — equity holders, not suppliers.
    |
    | Offered to every category because a partnership is a way of owning a
    | business rather than a way of trading one. Reading the register needs
    | settings.view; anything that moves money or verifies an identity needs
    | settings.edit, because both change who owns what.
    */
    Route::prefix('partners')->name('partners.')->group(function () {
        Route::get('/',            [\App\Http\Api\V1\PartnerController::class, 'index'])->middleware('can:settings.view')->name('index');
        Route::post('/',           [\App\Http\Api\V1\PartnerController::class, 'store'])->middleware('can:settings.edit')->name('store');

        // Before {publicId}, or "distributions" is read as a partner id.
        Route::get('distributions',         [\App\Http\Api\V1\PartnerController::class, 'distributionHistory'])->middleware('can:settings.view')->name('distributions.index');
        Route::get('distributions/preview', [\App\Http\Api\V1\PartnerController::class, 'distributionPreview'])->middleware('can:settings.view')->name('distributions.preview');
        Route::post('distributions',        [\App\Http\Api\V1\PartnerController::class, 'distribute'])->middleware('can:settings.edit')->name('distributions.store');

        Route::get('{publicId}',   [\App\Http\Api\V1\PartnerController::class, 'show'])->middleware('can:settings.view')->name('show');
        Route::patch('{publicId}', [\App\Http\Api\V1\PartnerController::class, 'update'])->middleware('can:settings.edit')->name('update');

        Route::post('{publicId}/verify',  [\App\Http\Api\V1\PartnerController::class, 'verify'])->middleware('can:settings.edit')->name('verify');
        Route::post('{publicId}/capital', [\App\Http\Api\V1\PartnerController::class, 'capital'])->middleware('can:settings.edit')->name('capital');
        Route::post('{publicId}/drawing', [\App\Http\Api\V1\PartnerController::class, 'drawing'])->middleware('can:settings.edit')->name('drawing');
        Route::post('{publicId}/expense', [\App\Http\Api\V1\PartnerController::class, 'expense'])->middleware('can:settings.edit')->name('expense');
        Route::post('{publicId}/settle',  [\App\Http\Api\V1\PartnerController::class, 'settle'])->middleware('can:settings.edit')->name('settle');
    });

    /*
    | Customers.
    |
    | The one record every category has: a shop has them, a clinic has them, a
    | consultancy has them. Scoped to the open business by the model's own
    | global scope, so a customer of one set of books never appears in another.
    */
    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/',             [\App\Http\Api\V1\CustomerController::class, 'index'])->name('index');
        Route::post('/',            [\App\Http\Api\V1\CustomerController::class, 'store'])->name('store');
        Route::get('{publicId}',    [\App\Http\Api\V1\CustomerController::class, 'show'])->name('show');
        Route::patch('{publicId}',  [\App\Http\Api\V1\CustomerController::class, 'update'])->name('update');
        Route::delete('{publicId}', [\App\Http\Api\V1\CustomerController::class, 'destroy'])->name('destroy');
    });

    /*
    | HR — employees and attendance.
    |
    | Wraps App\Domain\HR\HRService. Reading the directory or the attendance
    | log needs people.view; adding, changing or terminating an employee, or
    | recording attendance on someone's behalf, needs people.edit.
    */
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/',           [\App\Http\Api\V1\EmployeeController::class, 'index'])->middleware('can:people.view')->name('index');
        Route::post('/',          [\App\Http\Api\V1\EmployeeController::class, 'store'])->middleware('can:people.edit')->name('store');
        Route::get('{publicId}',  [\App\Http\Api\V1\EmployeeController::class, 'show'])->middleware('can:people.view')->name('show');
        Route::patch('{publicId}', [\App\Http\Api\V1\EmployeeController::class, 'update'])->middleware('can:people.edit')->name('update');
        Route::delete('{publicId}', [\App\Http\Api\V1\EmployeeController::class, 'destroy'])->middleware('can:people.edit')->name('destroy');
    });

    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/',          [\App\Http\Api\V1\AttendanceController::class, 'index'])->middleware('can:people.view')->name('index');
        Route::post('/',         [\App\Http\Api\V1\AttendanceController::class, 'store'])->middleware('can:people.edit')->name('store');
        Route::post('clock-in',  [\App\Http\Api\V1\AttendanceController::class, 'clockIn'])->middleware('can:people.edit')->name('clock-in');
        Route::post('clock-out', [\App\Http\Api\V1\AttendanceController::class, 'clockOut'])->middleware('can:people.edit')->name('clock-out');
    });

    Route::get('leave-requests', [\App\Http\Api\V1\AttendanceController::class, 'leaveRequests'])
        ->middleware('can:people.view')->name('leave-requests.index');

    Route::get('profile', [ProfileEndpoint::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileEndpoint::class, 'update'])->name('profile.update');
    Route::post('profile/avatar', [ProfileEndpoint::class, 'updateAvatar'])->name('profile.avatar.update');
    Route::delete('profile/avatar', [ProfileEndpoint::class, 'removeAvatar'])->name('profile.avatar.remove');
    Route::delete('profile/account', [ProfileEndpoint::class, 'deleteAccount'])->name('profile.account.delete');
    Route::get('timezones', [ProfileEndpoint::class, 'timezones'])->name('timezones');

    Route::post('businesses/switch', [BusinessEndpoint::class, 'switch'])->name('businesses.switch');

    Route::get('passkeys', [PasskeyEndpoint::class, 'index'])->name('passkeys.index');

    /*
    | Onboarding — multi-step wizard for new users.
    |
    | After registration, users are guided through workspace creation, business
    | setup, and plan selection. These endpoints track completion of each step.
    */
    Route::prefix('onboarding')->group(function () {
        Route::post('workspace', [OnboardingController::class, 'workspace'])->name('onboarding.workspace');
        Route::post('business', [OnboardingController::class, 'business'])->name('onboarding.business');
        Route::post('subscribe', [OnboardingController::class, 'subscribe'])->name('onboarding.subscribe');
        Route::post('skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    });

    /*
    | Settings.
    |
    | Read with settings.view, written with settings.edit — the endpoint sorts
    | out which, per group, because one of them (the media library) is readable
    | by anybody who can open settings and writable only by somebody who can
    | change them.
    */
    /*
    | Workspaces — what the subscription is sold in. Creating one is plan-gated
    | inside the endpoint; opening one switches the whole shell.
    */
    // Before the {id} routes below, or "categories" is read as a workspace id.
    Route::get('workspaces/categories', [WorkspaceEndpoint::class, 'categories'])
        ->name('workspaces.categories');
    Route::get('workspaces/countries', [WorkspaceEndpoint::class, 'countries'])
        ->name('workspaces.countries');
    
    // Business categories for business creation/editing
    Route::get('business-categories', [WorkspaceEndpoint::class, 'categories'])
        ->name('business-categories');
    Route::get('workspaces', [WorkspaceEndpoint::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceEndpoint::class, 'store'])->name('workspaces.store');
    Route::put('workspaces/{id}', [WorkspaceEndpoint::class, 'update'])->name('workspaces.update');
    Route::post('workspaces/{id}/businesses', [WorkspaceEndpoint::class, 'storeBusiness'])
        ->name('workspaces.businesses.store');
    Route::post('workspaces/open', [WorkspaceEndpoint::class, 'open'])->name('workspaces.open');
    Route::delete('workspaces/{id}', [WorkspaceEndpoint::class, 'destroy'])->name('workspaces.destroy');
    Route::post('businesses/{id}', [WorkspaceEndpoint::class, 'updateBusiness'])->name('businesses.update');

    /*
    | Sales — the order book.
    |
    | Read-only for now: creating an order moves stock and posts to the ledger,
    | and that belongs behind OrderService rather than a list endpoint.
    */
    /*
    | Storefronts — the shops a business sells through, and how each one's
    | records become ours. The mapping screens live on the shop, not in Settings.
    */
    Route::get('storefronts', [StorefrontsEndpoint::class, 'index'])
        ->middleware('can:sales.view')->name('storefronts.index');
    Route::post('storefronts', [StorefrontsEndpoint::class, 'store'])
        ->middleware('can:sales.edit')->name('storefronts.store');
    Route::post('storefronts/{id}/sync', [StorefrontsEndpoint::class, 'sync'])
        ->middleware('can:sales.edit')->name('storefronts.sync');
    Route::patch('storefronts/{id}', [StorefrontsEndpoint::class, 'update'])
        ->middleware('can:sales.edit')->name('storefronts.update');
    Route::delete('storefronts/{id}', [StorefrontsEndpoint::class, 'destroy'])
        ->middleware('can:sales.edit')->name('storefronts.destroy');
    Route::get('storefronts/{id}', [StorefrontsEndpoint::class, 'show'])
        ->middleware('can:sales.view')->name('storefronts.show');

    Route::get('orders', [OrdersEndpoint::class, 'index'])
        ->middleware('can:sales.view')->name('orders.index');
    Route::post('orders/bulk-update', [OrdersEndpoint::class, 'bulkUpdate'])
        ->middleware('can:sales.edit')->name('orders.bulk-update');
    Route::post('orders/bulk-dispatch', [OrdersEndpoint::class, 'bulkDispatch'])
        ->middleware('can:sales.edit')->name('orders.bulk-dispatch');
    Route::post('orders/{orderId}/dispatch', [OrdersEndpoint::class, 'dispatch'])
        ->middleware('can:sales.edit')->name('orders.dispatch');
    Route::post('orders/{orderId}/cancel-dispatch', [OrdersEndpoint::class, 'cancelDispatch'])
        ->middleware('can:sales.edit')->name('orders.cancel-dispatch');
    
    // Courier connections management
    Route::get('couriers', [CouriersEndpoint::class, 'index'])
        ->middleware('can:sales.view')->name('couriers.index');
    Route::post('couriers', [CouriersEndpoint::class, 'store'])
        ->middleware('can:sales.edit')->name('couriers.store');
    Route::get('couriers/{id}', [CouriersEndpoint::class, 'show'])
        ->middleware('can:sales.view')->name('couriers.show');
    Route::post('couriers/{id}/simulate', [CouriersEndpoint::class, 'simulate'])
        ->middleware('can:sales.edit')->name('couriers.simulate');
    Route::put('couriers/{id}', [CouriersEndpoint::class, 'update'])
        ->middleware('can:sales.edit')->name('couriers.update');
    Route::delete('couriers/{id}', [CouriersEndpoint::class, 'destroy'])
        ->middleware('can:sales.edit')->name('couriers.destroy');
    
    Route::post('orders/import', [\App\Http\Api\V1\OrderImportEndpoint::class, 'import'])
        ->middleware('can:sales.edit')->name('orders.import');

    /*
    | Integrations — the shops a business sells through.
    |
    | No route here names a platform either, for the same reason: the catalogue
    | is built from the registered drivers, and the form is built from whichever
    | driver was chosen. Adding a fifth platform adds a class, not a route.
    |
    | Reading a connection needs settings.view; anything that changes credentials
    | or reaches out to a shop needs settings.edit — a connection test is a
    | request made with this business's keys, and a sync writes records.
    */
    Route::prefix('settings/integrations')->name('settings.integrations.')->group(function () {
        Route::get('catalogue', [IntegrationsEndpoint::class, 'catalogue'])
            ->middleware('can:settings.view')->name('catalogue');

        Route::get('/', [IntegrationsEndpoint::class, 'index'])
            ->middleware('can:settings.view')->name('index');

        Route::post('test', [IntegrationsEndpoint::class, 'test'])
            ->middleware('can:settings.edit')->name('test');

        Route::post('/', [IntegrationsEndpoint::class, 'store'])
            ->middleware('can:settings.edit')->name('store');

        Route::get('{id}/sample-paths', [IntegrationsEndpoint::class, 'samplePaths'])
            ->middleware('can:settings.view')->name('sample-paths');
        Route::post('{id}/fields', [IntegrationsEndpoint::class, 'addField'])
            ->middleware('can:settings.edit')->name('fields.store');
        Route::put('{id}/field-maps', [IntegrationsEndpoint::class, 'saveFieldMaps'])
            ->middleware('can:settings.edit')->name('field-maps.save');

        Route::get('{id}/status-map', [IntegrationsEndpoint::class, 'statusMap'])
            ->middleware('can:settings.view')->name('status-map');
        Route::put('{id}/status-map', [IntegrationsEndpoint::class, 'saveStatusMap'])
            ->middleware('can:settings.edit')->name('status-map.save');

        Route::post('{id}/statuses', [IntegrationsEndpoint::class, 'addStatus'])
            ->middleware('can:settings.edit')->name('statuses.store');

        Route::post('{id}/sync', [IntegrationsEndpoint::class, 'sync'])
            ->middleware('can:settings.edit')->name('sync');

        /*
        | Making the shop call us, rather than telling somebody how to.
        |
        | Reading is a view permission and writing an edit one, because the
        | first is a diagnosis — is this shop wired up, and to what address —
        | and the second changes configuration on somebody's live storefront.
        */
        Route::get('{id}/webhooks', [IntegrationsEndpoint::class, 'webhooks'])
            ->middleware('can:settings.view')->name('webhooks');
        Route::post('{id}/webhooks/repair', [IntegrationsEndpoint::class, 'repairWebhooks'])
            ->middleware('can:settings.edit')->name('webhooks.repair');

        Route::patch('{id}', [IntegrationsEndpoint::class, 'update'])
            ->middleware('can:settings.edit')->name('update');
        Route::delete('{id}', [IntegrationsEndpoint::class, 'destroy'])
            ->middleware('can:settings.edit')->name('destroy');
    });

    /*
    | Couriers — one dashboard for all of them.
    |
    | No route here names a courier, and none ever will: adding a tenth is a
    | settings page, not a release. See App\Domain\Delivery\CourierDashboard.
    */
    Route::get('couriers/overview', [CourierEndpoint::class, 'overview'])->name('couriers.overview');
    Route::get('couriers/shipments', [CourierEndpoint::class, 'shipments'])->name('couriers.shipments');
    Route::get('couriers/statuses', [CourierEndpoint::class, 'statuses'])->name('couriers.statuses');
    Route::get('couriers/connections/{id}/pending', [CourierEndpoint::class, 'pendingMappings'])
        ->name('couriers.mappings.pending');
    Route::post('couriers/connections/{id}/mappings', [CourierEndpoint::class, 'mapStatus'])
        ->name('couriers.mappings.store');
    Route::delete('businesses/{id}', [WorkspaceEndpoint::class, 'destroyBusiness'])
        ->name('businesses.destroy');

    /*
    | Tags, favourites, and the marks on the to-do list. Small, per-account (or
    | per-person, for the two that are somebody's own preference).
    */
    Route::get('tags', [OrganiserEndpoint::class, 'index'])->name('tags.index');
    Route::post('tags', [OrganiserEndpoint::class, 'store'])->name('tags.store');
    Route::patch('tags/{id}', [OrganiserEndpoint::class, 'update'])->name('tags.update');
    Route::delete('tags/{id}', [OrganiserEndpoint::class, 'destroy'])->name('tags.destroy');
    Route::post('tags/{id}/assign', [OrganiserEndpoint::class, 'assign'])->name('tags.assign');
    Route::post('favourites', [OrganiserEndpoint::class, 'favourite'])->name('favourites.set');
    Route::post('todos/{key}', [OrganiserEndpoint::class, 'markTodo'])->name('todos.mark');

    // Ask a question instead of searching for one. Throttled here as well as
    // per account inside the endpoint: this route can spend real money, so a
    // burst is stopped before it reaches the handler at all.
    Route::post('assistant/ask', [AssistantEndpoint::class, 'ask'])
        ->middleware('throttle:20,1')
        ->name('assistant.ask');

    Route::get('settings', [SettingsEndpoint::class, 'index'])->name('settings.index');
    Route::get('settings/{group}', [SettingsEndpoint::class, 'show'])->name('settings.show');
    Route::patch('settings/{group}', [SettingsEndpoint::class, 'update'])->name('settings.update');

    // The media library. Listing is cursor-paginated; uploading is capped by
    // the route's own throttle as well as by the request's file rules.
    Route::get('media', [MediaEndpoint::class, 'index'])
        ->middleware('can:settings.view')->name('media.index');
    Route::get('media/stats', [MediaEndpoint::class, 'stats'])
        ->middleware('can:settings.view')->name('media.stats');
    Route::post('media', [MediaEndpoint::class, 'store'])
        ->middleware(['can:settings.edit', 'throttle:uploads'])->name('media.store');
    Route::patch('media/{id}', [MediaEndpoint::class, 'update'])
        ->middleware('can:settings.edit')->name('media.update');
    Route::delete('media/{id}', [MediaEndpoint::class, 'destroy'])
        ->middleware('can:settings.edit')->name('media.destroy');
    Route::delete('media', [MediaEndpoint::class, 'destroyMany'])
        ->middleware('can:settings.edit')->name('media.destroy-many');

    /*
    | Currency.
    |
    | Rates are rows rather than settings — added, edited and deleted one at a
    | time, and there can be a hundred and fifty of them — so they live beside
    | the settings group rather than inside it.
    */
    Route::middleware('can:settings.edit')->group(function () {
        Route::post('currency/base', [CurrencyEndpoint::class, 'setBase'])->name('currency.base');
        Route::post('currency/rates', [CurrencyEndpoint::class, 'store'])->name('currency.rates.store');
        Route::delete('currency/rates', [CurrencyEndpoint::class, 'destroy'])->name('currency.rates.destroy');

        // Calls out to somebody else's server while a user watches a button.
        // Nobody needs to do that six times a minute.
        Route::post('currency/refresh', [CurrencyEndpoint::class, 'refresh'])
            ->middleware('throttle:heavy')->name('currency.refresh');
    });
});

/*
| Billing stays reachable when the account itself has been stopped — which is
| the one moment somebody most needs to reach it. Note the absent
| 'account.usable': a lock-out screen behind the lock-out check is a door with
| the key on the inside.
*/
Route::middleware(['auth', 'two-factor', 'tenant', 'throttle:api'])->group(function () {
    Route::get('billing', [BillingEndpoint::class, 'show'])->name('billing.show');
});

// ── Changing how somebody proves who they are ────────────────────────────────
//
// Behind a password typed in the last few minutes. A live session proves
// somebody signed in this morning; it does not prove the person at the keyboard
// now is the same one, and for exactly these routes that difference is the
// whole point.

Route::middleware(['auth', 'two-factor', 'tenant', 'password.confirm', 'throttle:auth'])->group(function () {
    Route::post('auth/two-factor/enable', [TwoFactorEndpoint::class, 'begin'])->name('auth.two-factor.enable');
    Route::post('auth/two-factor/confirm', [TwoFactorEndpoint::class, 'confirm'])->name('auth.two-factor.confirm');
    Route::delete('auth/two-factor', [TwoFactorEndpoint::class, 'destroy'])->name('auth.two-factor.disable');
    Route::post('auth/two-factor/recovery-codes', [TwoFactorEndpoint::class, 'regenerateRecoveryCodes'])
        ->name('auth.two-factor.recovery-codes');

    Route::patch('auth/password', [PasswordEndpoint::class, 'update'])->name('auth.password.update');

    Route::post('passkeys/options', [PasskeyEndpoint::class, 'options'])->name('passkeys.options');
    Route::post('passkeys', [PasskeyEndpoint::class, 'register'])->name('passkeys.register');
    Route::patch('passkeys/{id}', [PasskeyEndpoint::class, 'update'])->name('passkeys.update');
    Route::delete('passkeys/{id}', [PasskeyEndpoint::class, 'destroy'])->name('passkeys.destroy');
});

/*
|--------------------------------------------------------------------------
| Courier webhooks
|--------------------------------------------------------------------------
|
| Outside every auth group on purpose: a courier has no session, no CSRF token
| and no way to get either. The connection's unguessable public id in the path
| identifies it; the signature, where the courier can sign one, proves it. See
| App\Domain\Delivery\WebhookReceiver for why this almost never returns an
| error — a non-2xx tells a courier's retry queue to try again and then to give
| up, which loses data that is already safely written down.
|
| `any` because couriers use whatever verb they like, including GET with the
| status in the query string.
*/
Route::any('webhooks/couriers/{connection}', CourierWebhookController::class)
    ->name('webhooks.couriers');

/*
| A connected shop, telling us an order changed.
|
| Outside every auth group for the same reason as the courier webhooks: a shop
| has no session and no CSRF token. The token in the path identifies which
| connection is being addressed; the signature, checked by that platform's own
| driver, proves the body is genuine.
|
| `any` because platforms use whichever verb they like. The address is shown to
| the subscriber on the connection's row in Settings → Integrations.
*/
Route::any('webhooks/integrations/{token}', IntegrationWebhookController::class)
    ->name('webhooks.integrations');

/*
|--------------------------------------------------------------------------
| Live chat widget
|--------------------------------------------------------------------------
|
| Called from the open internet, by a script on a subscriber's own website.
| No session, no CSRF, no tenant middleware — the widget key names the
| business and the visitor token scopes everything after that.
|
| Throttled hard and by address: this is the one surface anybody can reach,
| and an unthrottled "start a conversation" endpoint is a way to fill a
| subscriber's inbox with noise from a single laptop.
*/
Route::middleware('throttle:60,1')->prefix('chat')->name('chat.')->group(function () {
    Route::get('boot', [WebchatController::class, 'boot'])->name('boot');
    Route::post('start', [WebchatController::class, 'start'])->name('start');
    Route::post('send', [WebchatController::class, 'send'])->name('send');
    Route::get('transcript', [WebchatController::class, 'transcript'])->name('transcript');
    Route::post('identify', [WebchatController::class, 'identify'])->name('identify');
});

/*
|--------------------------------------------------------------------------
| Public review submission
|--------------------------------------------------------------------------
|
| Public endpoints for customers to submit reviews via tokenized links.
| No authentication required — the token identifies the review request.
| Throttled by address to prevent spam and abuse.
*/
Route::middleware('throttle:60,1')->prefix('reviews/public')->name('reviews.public.')->group(function () {
    Route::get('{token}', [\App\Http\Api\V1\ReviewIncentiveController::class, 'getReviewForm'])->name('form');
    Route::post('submit', [\App\Http\Api\V1\ReviewIncentiveController::class, 'submitReview'])->name('submit');
    Route::post('track', [\App\Http\Api\V1\ReviewIncentiveController::class, 'trackEngagement'])->name('track');
});

/*
|--------------------------------------------------------------------------
| Messaging webhooks
|--------------------------------------------------------------------------
|
| One URL, two verbs. Meta subscribes with a GET carrying a challenge and
| delivers with POST, and they have to be the same address — verifying one
| endpoint and receiving on another is a channel that passes setup and then
| stays silent.
|
| Outside every auth group, for the same reason as the courier webhooks: the
| platform has no session and never will.
*/
Route::get('webhooks/messages/{channel}', [MessageWebhookController::class, 'verify'])
    ->name('webhooks.messages.verify');
Route::post('webhooks/messages/{channel}', [MessageWebhookController::class, 'receive'])
    ->name('webhooks.messages.receive');

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| External API for third-party integrations. Authenticated via API keys
| rather than sessions, because the caller is a server and never has one.
| Rate limited by API key, account, and IP address.
|
| All routes are versioned (v1) and scoped by API permissions.
|
| -- What is live, and what is not -------------------------------------------
|
| API management, orders, products and webhooks are wired: every action below
| exists on its endpoint class. Customers, invoices and inventory are not --
| CustomersEndpoint, InvoicesEndpoint and InventoryEndpoint were never written.
| Their routes are kept below, commented, as the shape to build against rather
| than deleted, but they are named honestly here instead of the whole block
| claiming to be unimplemented while four working endpoints sat behind it.
*/
// 'public', not 'api/v1/public': this file is already mounted under api/v1
// (bootstrap/app.php), so the original prefix produced /api/v1/api/v1/public.
Route::prefix('public')->middleware(['api.auth', 'api.rate-limit'])->group(function () {

    // -- API Management ------------------------------------------------------

    Route::prefix('api')->group(function () {
        Route::get('keys', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'listApiKeys'])->name('public-api.keys.index');
        Route::post('keys', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'createApiKey'])->name('public-api.keys.store');
        Route::get('keys/{publicId}', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'showApiKey'])->name('public-api.keys.show');
        Route::patch('keys/{publicId}', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'updateApiKey'])->name('public-api.keys.update');
        Route::delete('keys/{publicId}', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'revokeApiKey'])->name('public-api.keys.revoke');

        Route::get('usage', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'getUsageAnalytics'])->name('public-api.usage');
        Route::get('health', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'getHealthStatus'])->name('public-api.health');
        Route::get('scopes', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'getScopes'])->name('public-api.scopes');
        Route::post('test', [\App\Http\Api\V1\Public\PublicApiEndpoint::class, 'testApiKey'])->name('public-api.test');
    });

    // -- Core Resources ------------------------------------------------------

    Route::prefix('orders')->group(function () {
        Route::get('/', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'index'])->name('public-api.orders.index');
        Route::post('/', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'store'])->name('public-api.orders.store');
        Route::get('{id}', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'show'])->name('public-api.orders.show');
        Route::patch('{id}', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'update'])->name('public-api.orders.update');
        Route::post('{id}/fulfill', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'fulfill'])->name('public-api.orders.fulfill');
        Route::post('{id}/cancel', [\App\Http\Api\V1\Public\OrdersEndpoint::class, 'cancel'])->name('public-api.orders.cancel');
    });

    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'index'])->name('public-api.products.index');
        Route::post('/', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'store'])->name('public-api.products.store');
        Route::get('{id}', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'show'])->name('public-api.products.show');
        Route::patch('{id}', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'update'])->name('public-api.products.update');
        Route::delete('{id}', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'destroy'])->name('public-api.products.destroy');
        Route::get('{id}/variants', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'variants'])->name('public-api.products.variants');
        Route::get('{id}/stock', [\App\Http\Api\V1\Public\ProductsEndpoint::class, 'stock'])->name('public-api.products.stock');
    });

    Route::prefix('webhooks')->group(function () {
        Route::get('/', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'index'])->name('public-api.webhooks.index');
        Route::post('/', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'store'])->name('public-api.webhooks.store');
        Route::get('{id}', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'show'])->name('public-api.webhooks.show');
        Route::patch('{id}', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'update'])->name('public-api.webhooks.update');
        Route::delete('{id}', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'destroy'])->name('public-api.webhooks.destroy');
        Route::get('{id}/deliveries', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'deliveries'])->name('public-api.webhooks.deliveries');
        Route::post('{id}/test', [\App\Http\Api\V1\Public\WebhooksEndpoint::class, 'test'])->name('public-api.webhooks.test');
    });

    /*
     * Not yet built. CustomersEndpoint, InvoicesEndpoint and InventoryEndpoint
     * do not exist; uncomment each block as its class lands.
     *
     * Route::prefix('customers')->group(function () {
     *     Route::get('/', [CustomersEndpoint::class, 'index']);
     *     Route::post('/', [CustomersEndpoint::class, 'store']);
     *     Route::get('{id}', [CustomersEndpoint::class, 'show']);
     *     Route::patch('{id}', [CustomersEndpoint::class, 'update']);
     *     Route::get('{id}/orders', [CustomersEndpoint::class, 'orders']);
     *     Route::get('{id}/invoices', [CustomersEndpoint::class, 'invoices']);
     * });
     *
     * Route::prefix('invoices')->group(function () {
     *     Route::get('/', [InvoicesEndpoint::class, 'index']);
     *     Route::post('/', [InvoicesEndpoint::class, 'store']);
     *     Route::get('{id}', [InvoicesEndpoint::class, 'show']);
     *     Route::patch('{id}', [InvoicesEndpoint::class, 'update']);
     *     Route::post('{id}/send', [InvoicesEndpoint::class, 'send']);
     *     Route::post('{id}/payments', [InvoicesEndpoint::class, 'recordPayment']);
     * });
     *
     * Route::prefix('inventory')->group(function () {
     *     Route::get('/', [InventoryEndpoint::class, 'index']);
     *     Route::get('movements', [InventoryEndpoint::class, 'movements']);
     *     Route::post('adjust', [InventoryEndpoint::class, 'adjust']);
     *     Route::get('batches', [InventoryEndpoint::class, 'batches']);
     * });
     */
});
