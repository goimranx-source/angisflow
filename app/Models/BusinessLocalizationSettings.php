<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-specific localization settings and preferences
 *
 * Links a business to its localization pack and allows overrides for specific
 * requirements. Each business can customize their locale preferences while
 * inheriting defaults from their localization pack.
 */
class BusinessLocalizationSettings extends Model
{
    use HasFactory, BelongsToAccount, BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'business_id',
        'public_id',
        'primary_localization_pack_id',
        'primary_language',
        'fallback_language',
        'additional_languages',
        'location_overrides',
        'allow_customer_language_choice',
        'auto_detect_customer_locale',
        'admin_timezone',
        'reporting_locale',
    ];

    protected $casts = [
        'additional_languages' => 'array',
        'location_overrides' => 'array',
        'allow_customer_language_choice' => 'boolean',
        'auto_detect_customer_locale' => 'boolean',
    ];

    public function primaryLocalizationPack(): BelongsTo
    {
        return $this->belongsTo(LocalizationPack::class, 'primary_localization_pack_id');
    }

    /**
     * Get effective language for a context
     */
    public function getEffectiveLanguage(?string $context = null, ?string $locationId = null): string
    {
        // Check for location-specific overrides first
        if ($locationId && $this->location_overrides) {
            $locationSettings = collect($this->location_overrides)
                ->where('location_id', $locationId)
                ->first();
            
            if ($locationSettings && isset($locationSettings['language'])) {
                return $locationSettings['language'];
            }
        }
        
        // Return primary language
        return $this->primary_language;
    }

    /**
     * Get localization pack for a specific location
     */
    public function getLocalizationPackForLocation(?string $locationId = null): LocalizationPack
    {
        // Check for location-specific pack override
        if ($locationId && $this->location_overrides) {
            $locationSettings = collect($this->location_overrides)
                ->where('location_id', $locationId)
                ->first();
            
            if ($locationSettings && isset($locationSettings['localization_pack_id'])) {
                $overridePack = LocalizationPack::find($locationSettings['localization_pack_id']);
                if ($overridePack) {
                    return $overridePack;
                }
            }
        }
        
        return $this->primaryLocalizationPack;
    }

    /**
     * Get all supported languages
     */
    public function getAllSupportedLanguages(): array
    {
        $languages = [$this->primary_language];
        
        if ($this->fallback_language && $this->fallback_language !== $this->primary_language) {
            $languages[] = $this->fallback_language;
        }
        
        if ($this->additional_languages) {
            $languages = array_merge($languages, $this->additional_languages);
        }
        
        return array_unique($languages);
    }

    /**
     * Check if a language is supported
     */
    public function supportsLanguage(string $languageCode): bool
    {
        return in_array($languageCode, $this->getAllSupportedLanguages(), true);
    }

    /**
     * Get timezone for admin/back-office operations
     */
    public function getAdminTimezone(): string
    {
        return $this->admin_timezone ?? $this->business->timezone ?? 'UTC';
    }

    /**
     * Get locale for financial reporting
     */
    public function getReportingLocale(): string
    {
        return $this->reporting_locale ?? $this->primary_language;
    }

    /**
     * Add location-specific override
     */
    public function addLocationOverride(string $locationId, array $settings): void
    {
        $overrides = $this->location_overrides ?? [];
        
        // Remove existing override for this location
        $overrides = collect($overrides)
            ->reject(fn ($override) => $override['location_id'] === $locationId)
            ->toArray();
        
        // Add new override
        $overrides[] = array_merge(['location_id' => $locationId], $settings);
        
        $this->location_overrides = $overrides;
        $this->save();
    }

    /**
     * Remove location-specific override
     */
    public function removeLocationOverride(string $locationId): void
    {
        if (!$this->location_overrides) {
            return;
        }
        
        $overrides = collect($this->location_overrides)
            ->reject(fn ($override) => $override['location_id'] === $locationId)
            ->values()
            ->toArray();
        
        $this->location_overrides = $overrides ?: null;
        $this->save();
    }

    /**
     * Get location override settings
     */
    public function getLocationOverride(string $locationId): ?array
    {
        if (!$this->location_overrides) {
            return null;
        }
        
        return collect($this->location_overrides)
            ->where('location_id', $locationId)
            ->first();
    }

    /**
     * Check if customer language detection is enabled
     */
    public function shouldAutoDetectCustomerLocale(): bool
    {
        return $this->auto_detect_customer_locale;
    }

    /**
     * Check if customers can choose their language
     */
    public function allowsCustomerLanguageChoice(): bool
    {
        return $this->allow_customer_language_choice;
    }

    /**
     * Get tax configuration from localization pack
     */
    public function getTaxConfiguration(?string $locationId = null): ?TaxConfiguration
    {
        return $this->getLocalizationPackForLocation($locationId)?->taxConfiguration;
    }

    /**
     * Get address format from localization pack
     */
    public function getAddressFormat(?string $locationId = null): ?AddressFormat
    {
        return $this->getLocalizationPackForLocation($locationId)?->addressFormat;
    }

    /**
     * Get format configuration from localization pack
     */
    public function getFormatConfiguration(?string $locationId = null): ?FormatConfiguration
    {
        return $this->getLocalizationPackForLocation($locationId)?->formatConfiguration;
    }

    /**
     * Get holiday calendars from localization pack
     */
    public function getHolidayCalendars(?string $locationId = null): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getLocalizationPackForLocation($locationId)?->holidayCalendars ?? collect();
    }

    /**
     * Create default settings for a business
     */
    public static function createDefaultForBusiness($business, ?string $countryCode = null): self
    {
        // Try to find default localization pack for country
        $pack = null;
        if ($countryCode) {
            $pack = LocalizationPack::getDefaultForCountry($countryCode);
        }
        
        // If no specific pack found, create a basic one or use first available
        if (!$pack) {
            $pack = LocalizationPack::where('account_id', $business->account_id)
                ->where('status', 'active')
                ->first();
        }
        
        return self::create([
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'primary_localization_pack_id' => $pack?->id,
            'primary_language' => 'en',
            'fallback_language' => 'en',
            'allow_customer_language_choice' => true,
            'auto_detect_customer_locale' => true,
        ]);
    }
}