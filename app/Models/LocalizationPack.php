<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A localization pack containing country/region-specific configurations
 *
 * Localization packs group related locale settings (tax rules, address formats,
 * holidays, etc.) that businesses can adopt and customize. System packs provide
 * country defaults, while custom packs allow business-specific modifications.
 */
class LocalizationPack extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'public_id',
        'pack_code',
        'name',
        'description',
        'country_code',
        'region_code',
        'version',
        'status',
        'is_system_pack',
        'is_default_for_country',
        'supported_languages',
        'created_by',
        'effective_from',
        'deprecated_at',
    ];

    protected $casts = [
        'is_system_pack' => 'boolean',
        'is_default_for_country' => 'boolean',
        'supported_languages' => 'array',
        'effective_from' => 'date',
        'deprecated_at' => 'date',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'deprecated' => 'Deprecated',
        'archived' => 'Archived',
    ];

    public function taxConfiguration(): HasOne
    {
        return $this->hasOne(TaxConfiguration::class);
    }

    public function addressFormat(): HasOne
    {
        return $this->hasOne(AddressFormat::class);
    }

    public function formatConfiguration(): HasOne
    {
        return $this->hasOne(FormatConfiguration::class);
    }

    public function holidayCalendars(): HasMany
    {
        return $this->hasMany(HolidayCalendar::class);
    }

    public function businessSettings(): HasMany
    {
        return $this->hasMany(BusinessLocalizationSettings::class, 'primary_localization_pack_id');
    }

    /**
     * Generate a unique pack code
     */
    public static function generatePackCode(string $countryCode, string $suffix = 'standard'): string
    {
        $base = strtolower($countryCode) . '-' . $suffix;
        $counter = 1;
        $code = $base;

        while (self::where('pack_code', $code)->exists()) {
            $code = $base . '-' . $counter;
            $counter++;
        }

        return $code;
    }

    /**
     * Get the default pack for a country
     */
    public static function getDefaultForCountry(string $countryCode): ?self
    {
        return self::where('country_code', $countryCode)
            ->where('is_default_for_country', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Check if this pack is currently active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' &&
               ($this->effective_from === null || $this->effective_from->isPast()) &&
               ($this->deprecated_at === null || $this->deprecated_at->isFuture());
    }

    /**
     * Get country information from Countries class
     */
    public function getCountryInfo(): ?array
    {
        return \App\Domain\Localization\Countries::ALL[$this->country_code] ?? null;
    }

    /**
     * Get supported language codes
     */
    public function getSupportedLanguages(): array
    {
        return $this->supported_languages ?? ['en'];
    }

    /**
     * Check if a language is supported
     */
    public function supportsLanguage(string $languageCode): bool
    {
        return in_array($languageCode, $this->getSupportedLanguages(), true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}