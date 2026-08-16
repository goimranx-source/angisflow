<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move business category from workspace to business.
 *
 * ── Why this change matters ──────────────────────────────────────────────────
 *
 * Originally, business_category_id lived on workspaces, meaning one workspace
 * could only represent one type of business (retail OR e-commerce, not both).
 * This forced users with multiple business types to create multiple workspaces,
 * which is confusing and doesn't match how real businesses work.
 *
 * Moving the category to the business level means:
 * - One workspace can contain businesses of different types
 * - The business switcher changes the sidebar menus contextually
 * - Simpler mental model: "Select your business, see its menus"
 * - Matches how competitors (QuickBooks, Xero) work
 *
 * ── Migration strategy ────────────────────────────────────────────────────────
 *
 * For existing data:
 * - Copy workspace.business_category_id to all businesses in that workspace
 * - This preserves current behavior while enabling future flexibility
 * - Workspaces with no category → businesses get null (handled later)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add business_category_id to businesses table
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('business_category_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('business_categories')
                ->nullOnDelete();

            $table->index('business_category_id');
        });

        // Copy category from workspace to all its businesses
        DB::statement('
            UPDATE businesses
            SET business_category_id = (
                SELECT business_category_id 
                FROM workspaces 
                WHERE workspaces.id = businesses.workspace_id
            )
            WHERE workspace_id IN (
                SELECT id FROM workspaces WHERE business_category_id IS NOT NULL
            )
        ');

        // Remove business_category_id from workspaces table
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_category_id');
        });
    }

    public function down(): void
    {
        // Add business_category_id back to workspaces
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('business_category_id')
                ->nullable()
                ->after('slug')
                ->constrained('business_categories')
                ->nullOnDelete();
        });

        // Copy category from first business back to workspace
        DB::statement('
            UPDATE workspaces
            SET business_category_id = (
                SELECT business_category_id 
                FROM businesses 
                WHERE businesses.workspace_id = workspaces.id 
                AND businesses.business_category_id IS NOT NULL
                LIMIT 1
            )
        ');

        // Remove business_category_id from businesses
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_category_id');
        });
    }
};
