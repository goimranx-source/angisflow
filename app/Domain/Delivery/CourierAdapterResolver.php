<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Delivery\Adapters\CourierAdapter;
use App\Domain\Delivery\Adapters\GenericAdapter;
use App\Domain\Delivery\Adapters\TestCourierAdapter;
use App\Domain\Delivery\Models\CourierConnection;

final class CourierAdapterResolver
{
    public function for(CourierConnection $connection): CourierAdapter
    {
        $adapter = $connection->courier?->adapter ?? 'generic';

        foreach ([TestCourierAdapter::class, GenericAdapter::class] as $class) {
            if (in_array($adapter, $class::handles(), true)) {
                return app($class);
            }
        }

        return app(GenericAdapter::class);
    }
}
