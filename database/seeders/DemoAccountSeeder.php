<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\RegisterAccount;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * One subscriber to sign in as while building.
 *
 * Goes through the same RegisterAccount action a real sign-up does, rather than
 * inserting rows directly. That is the point: if registration ever stops
 * producing a working account, seeding breaks too, and it breaks here rather
 * than in front of the first real customer.
 */
class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (User::withoutGlobalScopes()->where('email', 'owner@prism.local')->exists()) {
            $this->command?->info('Demo account already exists — skipping.');

            return;
        }

        $owner = app(RegisterAccount::class)->handle([
            'name' => 'Imran',
            'business' => 'Povaly',
            'email' => 'owner@prism.local',
            'password' => 'prism-dev-password',
            'currency' => 'BDT',
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
        ]);

        // Verified so the prompt is out of the way while developing. A real
        // sign-up is not, and should not be.
        $owner->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info('Demo owner: owner@prism.local / prism-dev-password');
    }
}
