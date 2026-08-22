<?php

namespace Database\Seeders;

use App\Domain\Delivery\Models\Courier;
use Illuminate\Database\Seeder;

class CourierSeeder extends Seeder
{
    public function run(): void
    {
        $couriers = [
            [
                'name' => 'Pathao',
                'slug' => 'pathao',
                'is_active' => true,
            ],
            [
                'name' => 'Steadfast',
                'slug' => 'steadfast',
                'is_active' => true,
            ],
            [
                'name' => 'Redx',
                'slug' => 'redx',
                'is_active' => true,
            ],
            [
                'name' => 'Paperfly',
                'slug' => 'paperfly',
                'is_active' => true,
            ],
            [
                'name' => 'eCourier',
                'slug' => 'ecourier',
                'is_active' => true,
            ],
            [
                'name' => 'Sundarban Courier',
                'slug' => 'sundarban',
                'is_active' => true,
            ],
            [
                'name' => 'Test Courier (Sandbox)',
                'slug' => 'test-courier',
                'adapter' => 'test',
                'country' => null,
                'is_active' => true,
            ],
        ];

        foreach ($couriers as $courier) {
            Courier::firstOrCreate(
                ['slug' => $courier['slug']],
                $courier
            );
        }
    }
}
