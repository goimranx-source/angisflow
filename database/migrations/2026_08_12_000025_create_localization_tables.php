<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 30: Localization Packs
 *
 * Creates comprehensive localization support beyond basic country/currency data.
 * While Countries.php provides static reference data (country names, currencies, 
 * timezones), this migration creates the infrastructure for dynamic, configurable
 * localization packs that can be customized per business.
 *
 * ── Why Tables Instead of Static Data ───────────────────────────────────────
 *
 * Unlike Countries.php which is reference data that never changes per tenant,
 * localization packs are business configuration. Different businesses in the
 * same country may have different:
 * - Tax calculation rules (VAT vs sales tax, exemptions, etc.)
 * - Address formatting preferences (suite vs apartment, postal code position)
 * - Date/number formats (MM/DD vs DD/MM, comma vs period decimals)
 * - Holiday calendars (religious, cultural, industry-specific)
 * - Language preferences for customer-facing content
 *
 * This system allows businesses to:
 * 1. Start with country defaults
 * 2. Customize specific aspects to their needs
 * 3. Support multi-regional operations with different local requirements
 *
 * ── Design Principles ───────────────────────────────────────────────────────
 *
 * 1. **Inheritance Model**: Business settings inherit from country defaults
 * 2. **Override Pattern**: Businesses can override specific settings without 
 *    affecting others
 * 3. **Pack System**: Related settings grouped into logical packs (tax, 
 *    address, formats, etc.)
 * 4. **Versioning**: Settings can be versioned for compliance and auditing
 * 5. **Multi-Regional**: A business can have different settings per region/
 *    location
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Localization Packs ──────────────────────────────────────────────
        
        Schema::create('localization_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Pack Identity
            $table->string('pack_code')->unique(); // e.g., 'us-standard', 'uk-vat', 'de-manufacturing'
            $table->string('name'); // "United States Standard"
            $table->text('description')->nullable();
            $table->string('country_code', 2); // ISO 3166-1 alpha-2
            $table->string('region_code')->nullable(); // State/province for country subdivisions
            
            // Versioning and Status
            $table->string('version')->default('1.0.0');
            $table->string('status')->default('active'); // active, deprecated, archived
            $table->boolean('is_system_pack')->default(false); // System vs custom packs
            $table->boolean('is_default_for_country')->default(false);
            
            // Metadata
            $table->json('supported_languages')->nullable(); // ['en', 'es'] for multi-language packs
            $table->string('created_by')->nullable(); // System, user ID, or external source
            $table->date('effective_from')->nullable(); // When this pack becomes active
            $table->date('deprecated_at')->nullable(); // When this pack is deprecated
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['country_code', 'status']);
            $table->index(['account_id', 'status']);
            $table->index(['is_system_pack', 'status']);
        });
        
        // ── Tax Configuration ───────────────────────────────────────────────
        
        Schema::create('tax_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('localization_pack_id')->constrained()->cascadeOnDelete();
            
            // Tax System Type
            $table->string('tax_system'); // vat, sales_tax, gst, no_tax, custom
            $table->boolean('tax_inclusive_pricing')->default(false); // Prices include tax
            $table->boolean('compound_taxes')->default(false); // Tax on tax calculation
            
            // Default Rates (can be overridden at product/transaction level)
            $table->decimal('default_tax_rate', 8, 4)->default(0); // 12.5000% = 0.1250
            $table->string('tax_number_label')->default('Tax ID'); // "VAT Number", "TIN", etc.
            $table->string('tax_number_format')->nullable(); // Regex for validation
            
            // Calculation Rules
            $table->json('rounding_rules'); // How to round tax calculations
            $table->json('exemption_rules')->nullable(); // Criteria for tax exemptions
            $table->json('reverse_charge_rules')->nullable(); // B2B reverse charge scenarios
            
            // Reporting Requirements
            $table->string('reporting_frequency')->default('monthly'); // monthly, quarterly, annual
            $table->boolean('requires_tax_registration')->default(true);
            $table->json('required_fields')->nullable(); // Additional fields for tax compliance
            
            $table->timestamps();
        });
        
        // ── Address Formats ─────────────────────────────────────────────────
        
        Schema::create('address_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('localization_pack_id')->constrained()->cascadeOnDelete();
            
            // Format Configuration
            $table->json('field_order'); // Order of address fields: ['name', 'line1', 'line2', 'city', ...]
            $table->json('required_fields'); // Which fields are mandatory: ['line1', 'city', 'postal_code']
            $table->json('field_labels'); // Localized labels: {'line1': 'Street Address', 'postal_code': 'ZIP Code'}
            
            // Postal Code Configuration
            $table->string('postal_code_format')->nullable(); // Regex pattern for validation
            $table->string('postal_code_label')->default('Postal Code'); // "ZIP Code", "Post Code", etc.
            $table->boolean('postal_code_before_city')->default(false);
            
            // Regional Configuration
            $table->string('state_label')->default('State'); // "Province", "Region", "County", etc.
            $table->boolean('show_state_field')->default(true);
            $table->json('state_options')->nullable(); // Predefined state/province list
            
            // Display Format Template
            $table->text('display_template'); // Template for rendering addresses
            $table->text('envelope_template')->nullable(); // Template for envelope printing
            
            $table->timestamps();
        });
        
        // ── Date and Number Formats ─────────────────────────────────────────
        
        Schema::create('format_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('localization_pack_id')->constrained()->cascadeOnDelete();
            
            // Date Formats
            $table->string('date_format')->default('Y-m-d'); // PHP date format
            $table->string('datetime_format')->default('Y-m-d H:i:s');
            $table->string('time_format')->default('H:i:s');
            $table->string('display_date_format')->default('M j, Y'); // For display to users
            $table->string('display_datetime_format')->default('M j, Y g:i A');
            
            // Number Formats
            $table->string('decimal_separator')->default('.'); // . or ,
            $table->string('thousand_separator')->default(','); // , or . or space
            $table->integer('decimal_places')->default(2); // Default precision for amounts
            $table->string('currency_position')->default('before'); // before, after
            $table->string('currency_format'); // How to display currency: "{symbol}{amount}", "{amount} {code}"
            
            // Measurement Units
            $table->string('weight_unit')->default('kg'); // kg, lb, g, oz
            $table->string('length_unit')->default('cm'); // cm, in, mm, ft
            $table->string('volume_unit')->default('l'); // l, ml, gal, fl oz
            $table->string('temperature_unit')->default('c'); // c, f, k
            
            // First Day of Week (0 = Sunday, 1 = Monday)
            $table->integer('week_start')->default(1);
            
            $table->timestamps();
        });
        
        // ── Holiday Calendars ───────────────────────────────────────────────
        
        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('localization_pack_id')->constrained()->cascadeOnDelete();
            
            // Calendar Identity
            $table->string('calendar_name'); // "US Federal Holidays", "UK Bank Holidays"
            $table->string('calendar_type')->default('national'); // national, religious, industry, custom
            $table->text('description')->nullable();
            
            // Configuration
            $table->boolean('is_default_calendar')->default(false);
            $table->boolean('affects_business_days')->default(true); // Does this calendar affect working days?
            $table->json('observance_rules')->nullable(); // Rules for holiday observance (substitute days, etc.)
            
            $table->timestamps();
        });
        
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holiday_calendar_id')->constrained()->cascadeOnDelete();
            
            // Holiday Details
            $table->string('name'); // "New Year's Day", "Christmas"
            $table->date('date'); // Specific date for this year
            $table->integer('year'); // Year this holiday instance applies to
            $table->string('recurrence_type')->default('annual'); // annual, none, custom
            $table->json('recurrence_rules')->nullable(); // Rules for calculating recurring holidays
            
            // Business Impact
            $table->boolean('is_business_day')->default(false); // Is this a working day?
            $table->boolean('is_half_day')->default(false); // Half-day holiday
            $table->string('observance_type')->default('exact'); // exact, nearest_weekday, monday_shift
            $table->date('observed_date')->nullable(); // If different from actual date
            
            // Metadata
            $table->string('holiday_type')->nullable(); // national, religious, cultural, bank
            $table->text('description')->nullable();
            $table->boolean('is_regional')->default(false); // Regional vs national holiday
            
            $table->timestamps();
            
            $table->index(['holiday_calendar_id', 'year']);
            $table->index(['date', 'is_business_day']);
        });
        
        // ── Business Localization Settings ──────────────────────────────────
        
        Schema::create('business_localization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Primary Localization Pack
            $table->foreignId('primary_localization_pack_id')->constrained('localization_packs');
            
            // Override Settings
            $table->string('primary_language', 5)->default('en'); // ISO 639-1 + optional country
            $table->string('fallback_language', 5)->default('en');
            $table->json('additional_languages')->nullable(); // For multi-language businesses
            
            // Regional Overrides
            $table->json('location_overrides')->nullable(); // Different packs per location
            
            // Customer-Facing Preferences
            $table->boolean('allow_customer_language_choice')->default(true);
            $table->boolean('auto_detect_customer_locale')->default(true);
            
            // System Preferences
            $table->string('admin_timezone')->nullable(); // Override business timezone for admin
            $table->string('reporting_locale')->nullable(); // Locale for financial reports
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id']);
        });
        
        // ── Language Translations ───────────────────────────────────────────
        
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            
            // Translation Key
            $table->string('translation_key'); // e.g., 'invoice.due_date', 'product.name'
            $table->string('language_code', 5); // ISO 639-1 + optional country: 'en', 'en_US'
            $table->text('translation_value');
            
            // Context
            $table->string('context_type')->nullable(); // business, system, module
            $table->string('context_id')->nullable(); // business_id, module_id, etc.
            $table->string('translation_group')->nullable(); // Group related translations
            
            // Metadata
            $table->boolean('is_system_translation')->default(false); // System vs business translations
            $table->boolean('requires_approval')->default(false); // For workflow-based translation
            $table->string('status')->default('active'); // active, draft, approved, deprecated
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['translation_key', 'language_code', 'context_type', 'context_id']);
            $table->index(['account_id', 'language_code', 'status']);
            $table->index(['translation_group', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::dropIfExists('business_localization_settings');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_calendars');
        Schema::dropIfExists('format_configurations');
        Schema::dropIfExists('address_formats');
        Schema::dropIfExists('tax_configurations');
        Schema::dropIfExists('localization_packs');
    }
};