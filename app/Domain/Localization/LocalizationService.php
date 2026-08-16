<?php

declare(strict_types=1);

namespace App\Domain\Localization;

use App\Domain\Money\Currencies;
use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessLocalizationSettings;
use App\Models\LocalizationPack;
use App\Models\TaxConfiguration;
use App\Models\AddressFormat;
use App\Models\FormatConfiguration;
use App\Models\HolidayCalendar;
use App\Models\Holiday;
use App\Models\Translation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Core localization service for managing locale-specific configurations
 *
 * Provides a unified interface for accessing tax rules, address formats, 
 * date/number formatting, holiday calendars, and translations. Handles
 * business-specific overrides and location-based variations.
 */
class LocalizationService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    /**
     * Create a new localization pack
     */
    public function createLocalizationPack(array $data): LocalizationPack
    {
        $data['account_id'] = $this->tenantContext->account()->id;
        $data['pack_code'] = $data['pack_code'] ?? LocalizationPack::generatePackCode(
            $data['country_code'],
            $data['name'] ?? 'standard'
        );

        $pack = LocalizationPack::create($data);

        // Create default configurations if this is a system pack
        if ($pack->is_system_pack) {
            $this->createDefaultConfigurations($pack);
        }

        return $pack;
    }

    /**
     * Setup business localization settings
     */
    public function setupBusinessLocalization(
        int $businessId,
        string $countryCode,
        ?array $preferences = null
    ): BusinessLocalizationSettings {
        $business = $this->tenantContext->business();
        
        // Find or create appropriate localization pack
        $pack = LocalizationPack::getDefaultForCountry($countryCode) 
            ?? $this->createDefaultPackForCountry($countryCode);

        $settings = BusinessLocalizationSettings::create([
            'account_id' => $this->tenantContext->account()->id,
            'business_id' => $businessId,
            'primary_localization_pack_id' => $pack->id,
            'primary_language' => $preferences['language'] ?? 'en',
            'fallback_language' => $preferences['fallback_language'] ?? 'en',
            'additional_languages' => $preferences['additional_languages'] ?? null,
            'allow_customer_language_choice' => $preferences['allow_customer_choice'] ?? true,
            'auto_detect_customer_locale' => $preferences['auto_detect'] ?? true,
            'admin_timezone' => $preferences['admin_timezone'] ?? null,
        ]);

        return $settings;
    }

    /**
     * Get business localization settings
     */
    public function getBusinessSettings(?int $businessId = null): ?BusinessLocalizationSettings
    {
        $businessId = $businessId ?? $this->tenantContext->business()->id;
        
        return BusinessLocalizationSettings::where('business_id', $businessId)->first();
    }

    /**
     * Calculate tax for an amount, in integer minor units, using business
     * locale settings.
     *
     * Takes and returns minor units throughout — never a float major-unit
     * amount — so a base price never drifts through binary floating-point
     * rounding on its way to a tax figure. See
     * TaxConfiguration::calculateTaxMinor() for the integer scheme.
     */
    public function calculateTax(
        int $baseAmountMinor,
        ?array $taxCriteria = null,
        ?string $locationId = null
    ): array {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return ['tax_amount_minor' => 0, 'total_amount_minor' => $baseAmountMinor, 'tax_rate_basis_points' => 0];
        }

        $taxConfig = $settings->getTaxConfiguration($locationId);
        if (!$taxConfig) {
            return ['tax_amount_minor' => 0, 'total_amount_minor' => $baseAmountMinor, 'tax_rate_basis_points' => 0];
        }

        // Check for exemptions
        if ($taxCriteria && $taxConfig->isExempt($taxCriteria)) {
            return ['tax_amount_minor' => 0, 'total_amount_minor' => $baseAmountMinor, 'tax_rate_basis_points' => 0, 'exempt' => true];
        }

        // Check for reverse charge
        $reverseCharge = $taxCriteria && $taxConfig->shouldApplyReverseCharge($taxCriteria);

        $rateBasisPoints = $taxConfig->taxRateBasisPoints();
        $taxAmountMinor = $taxConfig->calculateTaxMinor($baseAmountMinor);
        $totalAmountMinor = $taxConfig->calculateTotalWithTaxMinor($baseAmountMinor);

        return [
            'tax_amount_minor' => $taxAmountMinor,
            'total_amount_minor' => $totalAmountMinor,
            'tax_rate_basis_points' => $rateBasisPoints,
            'tax_system' => $taxConfig->tax_system,
            'tax_inclusive' => $taxConfig->tax_inclusive_pricing,
            'reverse_charge' => $reverseCharge,
        ];
    }

    /**
     * Format address according to business locale
     */
    public function formatAddress(array $addressData, ?string $locationId = null): string
    {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return $this->formatBasicAddress($addressData);
        }

        $addressFormat = $settings->getAddressFormat($locationId);
        if (!$addressFormat) {
            return $this->formatBasicAddress($addressData);
        }

        return $addressFormat->formatAddress($addressData);
    }

    /**
     * Validate address according to business locale
     */
    public function validateAddress(array $addressData, ?string $locationId = null): array
    {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return []; // No validation errors if no format defined
        }

        $addressFormat = $settings->getAddressFormat($locationId);
        if (!$addressFormat) {
            return [];
        }

        return $addressFormat->validateAddress($addressData);
    }

    /**
     * Format number according to business locale
     */
    public function formatNumber(float $number, ?int $decimals = null, ?string $locationId = null): string
    {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return number_format($number, $decimals ?? 2);
        }

        $formatConfig = $settings->getFormatConfiguration($locationId);
        if (!$formatConfig) {
            return number_format($number, $decimals ?? 2);
        }

        return $formatConfig->formatNumber($number, $decimals);
    }

    /**
     * Format currency according to business locale
     *
     * Symbol and decimal-place fallbacks come from App\Domain\Money\Currencies
     * — the one place this app already knows that JPY has no minor unit and
     * that BDT's symbol is "৳" — rather than hand-rolling "CODE amount" and a
     * hardcoded two decimals here, which is wrong for zero- and
     * three-decimal currencies alike.
     */
    public function formatCurrency(
        float $amount,
        ?string $currencyCode = null,
        ?int $decimals = null,
        ?string $locationId = null
    ): string {
        $settings = $this->getBusinessSettings();
        $currencyCode = $currencyCode ?? $this->tenantContext->business()->base_currency;
        $decimals = $decimals ?? Currencies::scale($currencyCode);
        $symbol = Currencies::symbol($currencyCode);

        if (!$settings) {
            return $symbol . number_format($amount, $decimals);
        }

        $formatConfig = $settings->getFormatConfiguration($locationId);
        if (!$formatConfig) {
            return $symbol . number_format($amount, $decimals);
        }

        return $formatConfig->formatCurrency($amount, $currencyCode, $decimals);
    }

    /**
     * Format date according to business locale
     */
    public function formatDate(Carbon|string $date, ?string $locationId = null): string
    {
        $settings = $this->getBusinessSettings();
        
        if (!$settings) {
            $carbon = is_string($date) ? Carbon::parse($date) : $date;
            return $carbon->format('M j, Y');
        }

        $formatConfig = $settings->getFormatConfiguration($locationId);
        if (!$formatConfig) {
            $carbon = is_string($date) ? Carbon::parse($date) : $date;
            return $carbon->format('M j, Y');
        }

        return $formatConfig->formatDate($date);
    }

    /**
     * Check if a date is a business day
     */
    public function isBusinessDay(Carbon|string $date, ?string $locationId = null): bool
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        // Weekend check
        if ($date->isWeekend()) {
            return false;
        }

        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return true; // Assume business day if no holiday calendar
        }

        $calendars = $settings->getHolidayCalendars($locationId);
        foreach ($calendars as $calendar) {
            if (!$calendar->isBusinessDay($date)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get next business day
     */
    public function getNextBusinessDay(Carbon|string $date, ?string $locationId = null): Carbon
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return $date->copy()->addWeekdays(1);
        }

        $calendars = $settings->getHolidayCalendars($locationId);
        $defaultCalendar = $calendars->where('is_default_calendar', true)->first() ?? $calendars->first();
        
        if ($defaultCalendar) {
            return $defaultCalendar->getNextBusinessDay($date);
        }

        return $date->copy()->addWeekdays(1);
    }

    /**
     * Get holidays for a date range
     */
    public function getHolidays(
        Carbon $startDate,
        Carbon $endDate,
        ?string $locationId = null
    ): Collection {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return collect();
        }

        $calendars = $settings->getHolidayCalendars($locationId);
        $holidays = collect();

        foreach ($calendars as $calendar) {
            $calendarHolidays = $calendar->holidays()
                ->inDateRange($startDate, $endDate)
                ->get();
            
            $holidays = $holidays->merge($calendarHolidays);
        }

        return $holidays->sortBy('date');
    }

    /**
     * Translate text
     */
    public function translate(
        string $key,
        ?string $languageCode = null,
        ?string $context = null,
        ?array $parameters = null
    ): string {
        $settings = $this->getBusinessSettings();
        $languageCode = $languageCode ?? $settings?->primary_language ?? 'en';
        $fallbackLanguage = $settings?->fallback_language ?? 'en';

        $contextType = $context ? 'business' : 'system';
        $contextId = $context ? $this->tenantContext->business()->id : null;

        $translation = Translation::getTranslation(
            $key,
            $languageCode,
            $contextType,
            (string) $contextId,
            $fallbackLanguage
        );

        // Replace parameters if provided
        if ($parameters && $translation) {
            foreach ($parameters as $param => $value) {
                $translation = str_replace("{{$param}}", (string) $value, $translation);
            }
        }

        return $translation ?? $key;
    }

    /**
     * Set custom translation
     */
    public function setTranslation(
        string $key,
        string $value,
        string $languageCode,
        ?string $group = null
    ): Translation {
        return Translation::setTranslation(
            $key,
            $languageCode,
            $value,
            'business',
            (string) $this->tenantContext->business()->id,
            $group
        );
    }

    /**
     * Generate holidays for a year
     */
    public function generateHolidaysForYear(int $year, ?string $locationId = null): int
    {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return 0;
        }

        $calendars = $settings->getHolidayCalendars($locationId);
        $generated = 0;

        foreach ($calendars as $calendar) {
            $holidays = $calendar->generateHolidaysForYear($year);
            
            foreach ($holidays as $holidayData) {
                Holiday::create(array_merge($holidayData, [
                    'account_id' => $this->tenantContext->account()->id,
                    'holiday_calendar_id' => $calendar->id,
                ]));
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Get supported currencies for business country
     */
    public function getSupportedCurrencies(): array
    {
        $settings = $this->getBusinessSettings();
        if (!$settings) {
            return ['USD' => 'US Dollar'];
        }

        $pack = $settings->primaryLocalizationPack;
        $countryInfo = $pack->getCountryInfo();
        
        if ($countryInfo) {
            return [$countryInfo[1] => $countryInfo[0] . ' (' . $countryInfo[1] . ')'];
        }

        return ['USD' => 'US Dollar'];
    }

    /**
     * Create default configurations for a localization pack
     */
    private function createDefaultConfigurations(LocalizationPack $pack): void
    {
        // Create tax configuration
        $taxDefaults = match ($pack->country_code) {
            'US' => [
                'tax_system' => 'sales_tax',
                'default_tax_rate' => 0.0875, // Average US sales tax
                'tax_number_label' => 'Tax ID',
                'reporting_frequency' => 'monthly',
            ],
            'GB' => [
                'tax_system' => 'vat',
                'default_tax_rate' => 0.20, // UK VAT
                'tax_number_label' => 'VAT Number',
                'tax_number_format' => '/^GB[0-9]{9}$/',
                'reporting_frequency' => 'quarterly',
            ],
            'DE' => [
                'tax_system' => 'vat',
                'default_tax_rate' => 0.19, // German VAT
                'tax_number_label' => 'USt-IdNr.',
                'reporting_frequency' => 'monthly',
            ],
            default => [
                'tax_system' => 'no_tax',
                'default_tax_rate' => 0.0,
                'tax_number_label' => 'Tax Number',
                'reporting_frequency' => 'annual',
            ],
        };

        TaxConfiguration::create(array_merge([
            'account_id' => $pack->account_id,
            'localization_pack_id' => $pack->id,
            'rounding_rules' => TaxConfiguration::getDefaultRoundingRules(),
        ], $taxDefaults));

        // Create address format
        $addressDefaults = match ($pack->country_code) {
            'US' => AddressFormat::getUSDefaults(),
            'GB' => AddressFormat::getUKDefaults(),
            default => AddressFormat::getUSDefaults(),
        };

        AddressFormat::create(array_merge([
            'account_id' => $pack->account_id,
            'localization_pack_id' => $pack->id,
        ], $addressDefaults));

        // Create format configuration
        $formatDefaults = match ($pack->country_code) {
            'US' => FormatConfiguration::getUSDefaults(),
            default => FormatConfiguration::getEuropeanDefaults(),
        };

        FormatConfiguration::create(array_merge([
            'account_id' => $pack->account_id,
            'localization_pack_id' => $pack->id,
        ], $formatDefaults));

        // Create default holiday calendar
        $calendar = HolidayCalendar::create([
            'account_id' => $pack->account_id,
            'localization_pack_id' => $pack->id,
            'calendar_name' => $pack->name . ' - National Holidays',
            'calendar_type' => 'national',
            'is_default_calendar' => true,
            'affects_business_days' => true,
        ]);

        // Add common holidays for US
        if ($pack->country_code === 'US') {
            Holiday::createCommonUSHolidays($calendar, Carbon::now()->year);
        }
    }

    /**
     * Create default localization pack for a country
     */
    private function createDefaultPackForCountry(string $countryCode): LocalizationPack
    {
        $countryInfo = Countries::ALL[$countryCode] ?? null;
        $countryName = $countryInfo[0] ?? $countryCode;

        return $this->createLocalizationPack([
            'pack_code' => LocalizationPack::generatePackCode($countryCode, 'standard'),
            'name' => $countryName . ' Standard',
            'description' => 'Default localization pack for ' . $countryName,
            'country_code' => $countryCode,
            'version' => '1.0.0',
            'status' => 'active',
            'is_system_pack' => true,
            'is_default_for_country' => true,
        ]);
    }

    /**
     * Basic address formatting fallback
     */
    private function formatBasicAddress(array $addressData): string
    {
        $lines = array_filter([
            $addressData['name'] ?? '',
            $addressData['company'] ?? '',
            $addressData['line1'] ?? '',
            $addressData['line2'] ?? '',
            trim(($addressData['city'] ?? '') . ', ' . ($addressData['state'] ?? '') . ' ' . ($addressData['postal_code'] ?? '')),
            $addressData['country'] ?? '',
        ]);

        return implode("\n", $lines);
    }
}