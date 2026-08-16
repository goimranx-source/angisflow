<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tax calculation and compliance rules for a localization pack
 *
 * Defines how taxes are calculated, formatted, and reported within a specific
 * jurisdiction. Supports various tax systems (VAT, sales tax, GST) with
 * configurable rates, exemptions, and compliance requirements.
 */
class TaxConfiguration extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'localization_pack_id',
        'tax_system',
        'tax_inclusive_pricing',
        'compound_taxes',
        'default_tax_rate',
        'tax_number_label',
        'tax_number_format',
        'rounding_rules',
        'exemption_rules',
        'reverse_charge_rules',
        'reporting_frequency',
        'requires_tax_registration',
        'required_fields',
    ];

    protected $casts = [
        'tax_inclusive_pricing' => 'boolean',
        'compound_taxes' => 'boolean',
        'default_tax_rate' => 'decimal:4',
        'rounding_rules' => 'array',
        'exemption_rules' => 'array',
        'reverse_charge_rules' => 'array',
        'requires_tax_registration' => 'boolean',
        'required_fields' => 'array',
    ];

    public const TAX_SYSTEMS = [
        'vat' => 'Value Added Tax (VAT)',
        'sales_tax' => 'Sales Tax',
        'gst' => 'Goods and Services Tax (GST)',
        'no_tax' => 'No Tax',
        'custom' => 'Custom Tax System',
    ];

    public const REPORTING_FREQUENCIES = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
        'real_time' => 'Real-time',
    ];

    public function localizationPack(): BelongsTo
    {
        return $this->belongsTo(LocalizationPack::class);
    }

    /**
     * Tax rate as integer basis points (1 bp = 0.01%; 8.5% = 850 bps).
     *
     * Money's minor-units rule is about amounts, not config values — a tax
     * rate is a percentage, stored once and read rarely, not a running total.
     * This is the one place a float is allowed to touch it, and only to turn
     * the stored decimal into a whole number of basis points before it ever
     * multiplies an amount; every calculation below is integer-only from
     * here on.
     */
    public function taxRateBasisPoints(?int $customRateBasisPoints = null): int
    {
        if ($customRateBasisPoints !== null) {
            return $customRateBasisPoints;
        }

        return (int) round(((float) $this->default_tax_rate) * 10000);
    }

    /**
     * Tax amount in minor units for a base amount in minor units.
     *
     * Never coerces the amount through a float — `$baseAmount * $rate` is
     * exactly the bug this replaces: a large enough invoice drifts by a
     * minor unit purely from binary floating-point rounding, and nobody
     * notices until a reconciliation does not add up. Division truncates
     * toward zero (the same choice Money::allocate makes) rather than
     * rounding half up, so a jurisdiction that wants a different rounding
     * convention configures it at the rate rather than here.
     */
    public function calculateTaxMinor(int $baseAmountMinor, ?int $customRateBasisPoints = null): int
    {
        $basisPoints = $this->taxRateBasisPoints($customRateBasisPoints);

        if ($this->tax_inclusive_pricing) {
            // Tax is already included in the price — back it out. The net
            // price is base * 10000 / (10000 + bps); what is left is tax.
            $netMinor = intdiv($baseAmountMinor * 10000, 10000 + $basisPoints);

            return $baseAmountMinor - $netMinor;
        }

        // Tax is added to the price.
        return intdiv($baseAmountMinor * $basisPoints, 10000);
    }

    /**
     * Base amount plus tax, in minor units.
     */
    public function calculateTotalWithTaxMinor(int $baseAmountMinor, ?int $customRateBasisPoints = null): int
    {
        if ($this->tax_inclusive_pricing) {
            return $baseAmountMinor; // Tax already included
        }

        return $baseAmountMinor + $this->calculateTaxMinor($baseAmountMinor, $customRateBasisPoints);
    }

    /**
     * Check if an entity/transaction qualifies for tax exemption
     */
    public function isExempt(array $criteria): bool
    {
        if (!$this->exemption_rules) {
            return false;
        }

        foreach ($this->exemption_rules as $rule) {
            $matches = true;
            foreach ($rule['conditions'] ?? [] as $field => $expectedValue) {
                if (!isset($criteria[$field]) || $criteria[$field] !== $expectedValue) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if reverse charge applies
     */
    public function shouldApplyReverseCharge(array $criteria): bool
    {
        if (!$this->reverse_charge_rules) {
            return false;
        }

        // B2B transactions within EU for VAT, etc.
        foreach ($this->reverse_charge_rules as $rule) {
            $matches = true;
            foreach ($rule['conditions'] ?? [] as $field => $expectedValue) {
                if (!isset($criteria[$field]) || $criteria[$field] !== $expectedValue) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate tax number format
     */
    public function validateTaxNumber(string $taxNumber): bool
    {
        if (!$this->tax_number_format) {
            return true; // No validation rule defined
        }

        return preg_match($this->tax_number_format, $taxNumber) === 1;
    }

    public function taxSystemLabel(): string
    {
        return self::TAX_SYSTEMS[$this->tax_system] ?? ucfirst($this->tax_system);
    }

    public function reportingFrequencyLabel(): string
    {
        return self::REPORTING_FREQUENCIES[$this->reporting_frequency] ?? ucfirst($this->reporting_frequency);
    }

    /**
     * Get default rounding rules
     */
    public static function getDefaultRoundingRules(): array
    {
        return [
            'method' => 'round',
            'precision' => 2,
            'per_line' => true, // Round per line vs total
        ];
    }
}