<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Plans first: registering an account puts it on a trial of one, so
        // seeding a subscriber before the catalogue exists would create an
        // account with no subscription behind it.
        $this->call([
            PlanSeeder::class,
            DemoAccountSeeder::class,
        ]);
    }
}
