<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckAccount extends Command
{
    protected $signature = 'dev:check-account {email}';

    protected $description = 'Check account status and enable all modules/categories';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $this->info("Account ID: {$user->account_id}");
        $this->info("User: {$user->name} ({$user->email})");
        $this->newLine();

        // Check workspaces
        $workspaces = Workspace::withoutGlobalScopes()
            ->where('account_id', $user->account_id)
            ->get();

        $this->info("Workspaces: {$workspaces->count()}");
        foreach ($workspaces as $workspace) {
            $this->line("  - {$workspace->name} (ID: {$workspace->id})");
            $this->line("    Current category: " . ($workspace->business_category_id ?? 'None'));
            
            // Check modules
            $moduleCount = DB::table('workspace_modules')
                ->where('workspace_id', $workspace->id)
                ->count();
            $this->line("    Modules enabled: {$moduleCount}");
        }
        $this->newLine();

        // Check businesses
        $businesses = Business::withoutGlobalScopes()
            ->where('account_id', $user->account_id)
            ->get();

        $this->info("Businesses: {$businesses->count()}");
        foreach ($businesses as $business) {
            $this->line("  - {$business->name} (ID: {$business->id})");
        }
        $this->newLine();

        // Get all categories
        $allCategories = BusinessCategory::whereNull('parent_id')->get();
        $this->info("Available categories: {$allCategories->count()}");
        foreach ($allCategories as $cat) {
            $this->line("  - {$cat->name} ({$cat->key})");
        }
        $this->newLine();

        // Now enable everything
        if ($this->confirm('Enable all modules for all workspaces?', true)) {
            $allModules = Module::all();

            foreach ($workspaces as $workspace) {
                // Enable all modules
                $moduleIds = $allModules->pluck('id')->toArray();
                
                DB::table('workspace_modules')
                    ->where('workspace_id', $workspace->id)
                    ->delete();
                
                foreach ($moduleIds as $moduleId) {
                    DB::table('workspace_modules')->insert([
                        'workspace_id' => $workspace->id,
                        'module_id' => $moduleId,
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->info("✓ Enabled {$allModules->count()} modules for workspace: {$workspace->name}");
            }

            $this->newLine();
            $this->info('✅ All modules enabled!');
            $this->newLine();
            $this->comment('Note: Categories are assigned to workspaces, not businesses.');
            $this->comment('To see all menus, the workspace needs to have the appropriate category set.');
            $this->comment('Currently, menus are shown based on the workspace\'s single category.');
        }

        return 0;
    }
}
