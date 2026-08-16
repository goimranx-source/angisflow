<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    |
    | What the tool calls itself on the sign-in screen and in the sidebar.
    | A subscriber's own name and logo live on their account row; this is the
    | platform's.
    |
    */

    'brand' => [
        'name' => env('PRISM_BRAND_NAME', 'Angisflow'),
        'tagline' => env('PRISM_BRAND_TAGLINE', 'Run the whole business from one place.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-up
    |--------------------------------------------------------------------------
    |
    | Registration creates an account, not just a login: a subscriber, a trial,
    | an owner, a first business and a starting set of roles. Turning it off
    | leaves every other auth route working, which is what you want the day
    | this goes invite-only.
    |
    */

    'registration' => [
        'enabled' => (bool) env('PRISM_REGISTRATION_ENABLED', true),
        'default_plan' => env('PRISM_DEFAULT_PLAN', 'starter'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Throttles are per minute and deliberately strict on the endpoints that
    | can be attacked in bulk. They are counted per email *and* per IP, not per
    | IP alone: a botnet has as many addresses as it likes but still has to
    | guess one address's password, and rate limiting only the address it comes
    | from is the version of this control that does nothing.
    |
    */

    'auth' => [
        'login_attempts' => (int) env('PRISM_LOGIN_ATTEMPTS', 5),
        'login_decay_minutes' => (int) env('PRISM_LOGIN_DECAY', 1),

        // How long a passed second factor is trusted for on one session.
        'two_factor_lifetime_minutes' => (int) env('PRISM_2FA_LIFETIME', 60 * 12),

        // How many one-shot recovery codes are handed out when 2FA is enabled.
        'recovery_code_count' => 8,

        // How long an authentication event is kept before the retention sweep
        // removes it. Long enough to investigate an incident, short enough that
        // holding IP addresses is not a liability.
        'event_retention_days' => (int) env('PRISM_AUTH_EVENT_RETENTION', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    |
    | The audit firehose. Events are buffered in memory during a request and
    | written as one statement at the end of it, never one INSERT at a time.
    |
    */

    'activity' => [
        'enabled' => (bool) env('PRISM_ACTIVITY_ENABLED', true),

        // Flush early if a single request produces more than this, so a bulk
        // import does not hold a hundred thousand rows in memory.
        'buffer_limit' => (int) env('PRISM_ACTIVITY_BUFFER', 500),

        // Months of history to keep. On MySQL the sweep drops whole partitions.
        'retention_months' => (int) env('PRISM_ACTIVITY_RETENTION_MONTHS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot protection
    |--------------------------------------------------------------------------
    |
    | Entirely inert unless both keys are set.
    |
    */

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

];
