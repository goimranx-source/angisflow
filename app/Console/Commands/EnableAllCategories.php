<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Console\Command;

class EnableAllCategories extends Command
{
    protected $signature = 'dev:enable-all-categories {email}';

    protected $description = 'Enable all business categories for all businesses owned by account';

    public function handle(): int
    {
        $email = $this->argument('email');

        // Find user and their businesses
        $user = \App\Domain\Identity\Models\User::withoutGlobalScopes()
            ->where('email', $email)
            ->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return 1;
        }

        // Get all businesses for this account
        $businesses = Business::withoutGlobalScopes()
            ->where('account_id', $user->account_id)
            ->get();

        if ($businesses->isEmpty()) {
            $this->error("No businesses found for account.");
            return 1;
        }

        // Get all parent categories (main categories, not subcategories)
        $allCategories = BusinessCategory::whereNull('parent_id')->get();

        $this->info("Found {$allCategories->count()} categories:");
        foreach ($allCategories as $category) {
            $this->line("  - {$category->name} ({$category->key})");
        }
        $this->newLine();

        $categoryIds = $allCategories->pluck('id')->toArray();

        foreach ($businesses as $business) {
            // Sync all categories to this business
            $business->categories()->sync($categoryIds);
            
            $this->info("✓ Enabled all categories for: {$business->name}");
        }

        $this->newLine();
        $this->info('='.str_repeat('=', 60).'=');
        $this->info('  ALL CATEGORIES ENABLED');
        $this->info('='.str_repeat('=', 60).'=');
        $this->info("Businesses updated: {$businesses->count()}");
        $this->info("Categories per business: {$allCategories->count()}");

        return 0;
    }
}
