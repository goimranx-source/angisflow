<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Date, time, number, and measurement formatting rules
 *
 * Defines how dates, numbers, currencies, and measurements are formatted
 * and displayed within a specific locale. Provides consistent formatting
 * methods for user interfaces and reports.
 */
class FormatConfiguration extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'localization_pack_id',
        'date_format',
        'datetime_format',
        'time_format',
        'display_date_format',
        'display_datetime_format',
        'decimal_separator',
        'thousand_separator',
        'decimal_places',
        'currency_position',
        'currency_format',
        'weight_unit',
        'length_unit',
        'volume_unit',
        'temperature_unit',
        'week_start',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'week_start' => 'integer',
    ];

    public const CURRENCY_POSITIONS = [
        'before' => 'Before amount ($100.00)',
        'after' => 'After amount (100.00 USD)',
        'before_no_space' => 'Before, no space ($100.00)',
        'after_no_space' => 'After, no space (100.00$)',
    ];

    public const WEIGHT_UNITS = [
        'kg' => 'Kilograms',
        'g' => 'Grams',
        'lb' => 'Pounds',
        'oz' => 'Ounces',
        't' => 'Metric Tons',
    ];

    public const LENGTH_UNITS = [
        'mm' => 'Millimeters',
        'cm' => 'Centimeters',
        'm' => 'Meters',
        'km' => 'Kilometers',
        'in' => 'Inches',
        'ft' => 'Feet',
        'yd' => 'Yards',
        'mi' => 'Miles',
    ];

    public const VOLUME_UNITS = [
        'ml' => 'Milliliters',
        'l' => 'Liters',
        'gal' => 'Gallons (US)',
        'qt' => 'Quarts',
        'pt' => 'Pints',
        'fl_oz' => 'Fluid Ounces',
    ];

    public const TEMPERATURE_UNITS = [
        'c' => 'Celsius',
        'f' => 'Fahrenheit',
        'k' => 'Kelvin',
    ];

    public function localizationPack(): BelongsTo
    {
        return $this->belongsTo(LocalizationPack::class);
    }

    /**
     * Format a date for display
     */
    public function formatDate(Carbon|string $date): string
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        return $date->format($this->display_date_format);
    }

    /**
     * Format a datetime for display
     */
    public function formatDateTime(Carbon|string $datetime): string
    {
        if (is_string($datetime)) {
            $datetime = Carbon::parse($datetime);
        }
        
        return $datetime->format($this->display_datetime_format);
    }

    /**
     * Format a time for display
     */
    public function formatTime(Carbon|string $time): string
    {
        if (is_string($time)) {
            $time = Carbon::parse($time);
        }
        
        return $time->format($this->time_format);
    }

    /**
     * Format a number with locale-specific separators
     */
    public function formatNumber(float|int $number, ?int $decimals = null): string
    {
        $decimals = $decimals ?? $this->decimal_places;
        
        return number_format(
            (float) $number,
            $decimals,
            $this->decimal_separator,
            $this->thousand_separator
        );
    }

    /**
     * Format currency amount
     */
    public function formatCurrency(float|int $amount, string $currencyCode, ?int $decimals = null): string
    {
        $formattedAmount = $this->formatNumber($amount, $decimals);
        
        return match ($this->currency_position) {
            'before' => $currencyCode . ' ' . $formattedAmount,
            'after' => $formattedAmount . ' ' . $currencyCode,
            'before_no_space' => $currencyCode . $formattedAmount,
            'after_no_space' => $formattedAmount . $currencyCode,
            default => str_replace(
                ['{symbol}', '{code}', '{amount}'],
                [$currencyCode, $currencyCode, $formattedAmount],
                $this->currency_format
            ),
        };
    }

    /**
     * Parse a number from locale-formatted string
     */
    public function parseNumber(string $formattedNumber): float
    {
        // Remove thousand separators and replace decimal separator with period
        $cleaned = str_replace($this->thousand_separator, '', $formattedNumber);
        $cleaned = str_replace($this->decimal_separator, '.', $cleaned);
        
        return (float) $cleaned;
    }

    /**
     * Format weight with unit
     */
    public function formatWeight(float $weight, ?string $unit = null): string
    {
        $unit = $unit ?? $this->weight_unit;
        $formatted = $this->formatNumber($weight);
        
        return $formatted . ' ' . $unit;
    }

    /**
     * Format length with unit
     */
    public function formatLength(float $length, ?string $unit = null): string
    {
        $unit = $unit ?? $this->length_unit;
        $formatted = $this->formatNumber($length);
        
        return $formatted . ' ' . $unit;
    }

    /**
     * Format volume with unit
     */
    public function formatVolume(float $volume, ?string $unit = null): string
    {
        $unit = $unit ?? $this->volume_unit;
        $formatted = $this->formatNumber($volume);
        
        return $formatted . ' ' . $unit;
    }

    /**
     * Format temperature with unit
     */
    public function formatTemperature(float $temperature, ?string $unit = null): string
    {
        $unit = $unit ?? $this->temperature_unit;
        $formatted = $this->formatNumber($temperature, 1);
        
        return $formatted . '°' . strtoupper($unit);
    }

    /**
     * Get first day of week for calendar display
     */
    public function getWeekStart(): int
    {
        return $this->week_start; // 0 = Sunday, 1 = Monday
    }

    /**
     * Check if week starts on Monday
     */
    public function weekStartsOnMonday(): bool
    {
        return $this->week_start === 1;
    }

    /**
     * Get date input format for HTML forms
     */
    public function getDateInputFormat(): string
    {
        // Convert PHP date format to HTML5 date format hints
        return match ($this->date_format) {
            'Y-m-d' => 'YYYY-MM-DD',
            'd/m/Y' => 'DD/MM/YYYY',
            'm/d/Y' => 'MM/DD/YYYY',
            'd.m.Y' => 'DD.MM.YYYY',
            default => 'YYYY-MM-DD',
        };
    }

    /**
     * Get JS date format for frontend libraries
     */
    public function getJSDateFormat(): string
    {
        // Convert PHP date format to common JS library formats
        $map = [
            'Y-m-d' => 'YYYY-MM-DD',
            'd/m/Y' => 'DD/MM/YYYY',
            'm/d/Y' => 'MM/DD/YYYY',
            'd.m.Y' => 'DD.MM.YYYY',
            'j M Y' => 'D MMM YYYY',
            'F j, Y' => 'MMMM D, YYYY',
        ];
        
        return $map[$this->display_date_format] ?? 'YYYY-MM-DD';
    }

    /**
     * Get default US format configuration
     */
    public static function getUSDefaults(): array
    {
        return [
            'date_format' => 'Y-m-d',
            'datetime_format' => 'Y-m-d H:i:s',
            'time_format' => 'g:i A',
            'display_date_format' => 'M j, Y',
            'display_datetime_format' => 'M j, Y g:i A',
            'decimal_separator' => '.',
            'thousand_separator' => ',',
            'decimal_places' => 2,
            'currency_position' => 'before',
            'currency_format' => '{symbol}{amount}',
            'weight_unit' => 'lb',
            'length_unit' => 'in',
            'volume_unit' => 'gal',
            'temperature_unit' => 'f',
            'week_start' => 0, // Sunday
        ];
    }

    /**
     * Get default European format configuration
     */
    public static function getEuropeanDefaults(): array
    {
        return [
            'date_format' => 'Y-m-d',
            'datetime_format' => 'Y-m-d H:i:s',
            'time_format' => 'H:i',
            'display_date_format' => 'j M Y',
            'display_datetime_format' => 'j M Y H:i',
            'decimal_separator' => ',',
            'thousand_separator' => '.',
            'decimal_places' => 2,
            'currency_position' => 'after',
            'currency_format' => '{amount} {code}',
            'weight_unit' => 'kg',
            'length_unit' => 'cm',
            'volume_unit' => 'l',
            'temperature_unit' => 'c',
            'week_start' => 1, // Monday
        ];
    }
}