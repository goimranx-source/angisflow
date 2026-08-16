<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpgradeDevAccount extends Command
{
    protected $signature = 'dev:upgrade-account {email}';

    protected $description = 'Upgrade a development account to Enterprise with all modules and categories';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return 1;
        }

        $account = Account::withoutGlobalScopes()->find($user->account_id);

        if (!$account) {
            $this->error("Account not found for user {$email}.");
            return 1;
        }

        DB::transaction(function () use ($account) {
            // Get Enterprise plan
            $enterprisePlan = Plan::where('code', 'enterprise')->first();

            if (!$enterprisePlan) {
                $this->error('Enterprise plan not found. Run php artisan db:seed --class=PlanSeeder first.');
                return 1;
            }

            // Create or update subscription to Enterprise plan
            $subscription = Subscription::updateOrCreate(
                ['account_id' => $account->id],
                [
                    'plan_id' => $enterprisePlan->id,
                    'status' => 'active',
                    'trial_ends_at' => now()->addYear(),
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                    'cancelled_at' => null,
                ]
            );

            $this->info("✓ Upgraded to Enterprise plan (ID: {$enterprisePlan->id})");

            // Get all workspaces for this account
            $workspaces = Workspace::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->get();

            foreach ($workspaces as $workspace) {
                // Enable ALL modules for each workspace
                $allModules = Module::all();

                $moduleData = [];
                foreach ($allModules as $module) {
                    $moduleData[$module->id] = [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Sync all modules
                $workspace->modules()->sync($moduleData);

                $this->info("✓ Enabled {$allModules->count()} modules for workspace: {$workspace->name}");

                // Enable ALL business categories for each business in workspace
                $businesses = $workspace->businesses()->get();
                
                if ($businesses->isEmpty()) {
                    $this->warn("  ⚠ No businesses found in workspace: {$workspace->name}");
                    continue;
                }

                foreach ($businesses as $business) {
                    $allCategories = BusinessCategory::whereNull('parent_id')->get();

                    $categoryIds = $allCategories->pluck('id')->toArray();
                    $business->categories()->sync($categoryIds);

                    $this->info("  ✓ Enabled {$allCategories->count()} categories for business: {$business->name}");
                }
            }
        });

        $this->info('');
        $this->info('='.str_repeat('=', 60).'=');
        $this->info('  ACCOUNT UPGRADED SUCCESSFULLY');
        $this->info('='.str_repeat('=', 60).'=');
        $this->info('');
        $this->info("Email: {$email}");
        $this->info("Plan: Enterprise (Unlimited)");
        $this->info("All modules: ENABLED");
        $this->info("All categories: ENABLED");
        $this->info('');
        $this->info('SUPPORT TOKEN: ' . base64_encode($account->public_id . '|' . $account->id));
        $this->info('');
        $this->comment('Subscribers should provide this support token when requesting help.');
        $this->comment('This token identifies their account without exposing sensitive data.');
        $this->info('');

        return 0;
    }
}
