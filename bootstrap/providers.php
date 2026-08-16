<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    AuthorizationServiceProvider::class,
    RateLimitServiceProvider::class,
];
