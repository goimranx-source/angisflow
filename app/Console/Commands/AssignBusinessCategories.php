<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Console\Command;

class AssignBusinessCategories extends Command
{
    protected $signature = 'dev:assign-categories {email}';

    protected $description = 'Assign all categories to all businesses for dev testing';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $businesses = Business::withoutGlobalScopes()
            ->where('account_id', $user->account_id)
            ->get();

        if ($businesses->isEmpty()) {
            $this->error('No businesses found');
            return 1;
        }

        // Get "other" category - gives broadest module access
        $otherCategory = BusinessCategory::where('key', 'other')->first();

        if (!$otherCategory) {
            $this->error('Category "other" not found. Run: php artisan db:seed --class=CatalogueSeeder');
            return 1;
        }

        $this->info("Assigning category '{$otherCategory->name}' to all businesses...");
        $this->newLine();

        foreach ($businesses as $business) {
            $business->business_category_id = $otherCategory->id;
            $business->save();

            $this->info("✓ {$business->name} → {$otherCategory->name}");
        }

        $this->newLine();
        $this->info('✅ All businesses now have categories assigned!');
        $this->comment('Each business will show menus based on its category.');
        $this->comment('Switch business in the header to see different menus.');

        return 0;
    }
}
