<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Address formatting and validation rules for a localization pack
 *
 * Defines how addresses should be formatted, validated, and displayed within
 * a specific country or region. Handles field ordering, labels, requirements,
 * and formatting templates for consistent address presentation.
 */
class AddressFormat extends Model
{
    use HasFactory, BelongsToAccount;

    protected $fillable = [
        'account_id',
        'localization_pack_id',
        'field_order',
        'required_fields',
        'field_labels',
        'postal_code_format',
        'postal_code_label',
        'postal_code_before_city',
        'state_label',
        'show_state_field',
        'state_options',
        'display_template',
        'envelope_template',
    ];

    protected $casts = [
        'field_order' => 'array',
        'required_fields' => 'array',
        'field_labels' => 'array',
        'postal_code_before_city' => 'boolean',
        'show_state_field' => 'boolean',
        'state_options' => 'array',
    ];

    public const STANDARD_FIELDS = [
        'name' => 'Full Name',
        'company' => 'Company/Organization',
        'line1' => 'Address Line 1',
        'line2' => 'Address Line 2',
        'line3' => 'Address Line 3',
        'city' => 'City',
        'state' => 'State/Province',
        'postal_code' => 'Postal Code',
        'country' => 'Country',
    ];

    public function localizationPack(): BelongsTo
    {
        return $this->belongsTo(LocalizationPack::class);
    }

    /**
     * Format an address array according to this format configuration
     */
    public function formatAddress(array $addressData): string
    {
        $template = $this->display_template;
        
        // Replace template variables with actual data
        foreach ($addressData as $field => $value) {
            if ($value) {
                $template = str_replace("{{$field}}", $value, $template);
            }
        }
        
        // Clean up empty lines and extra whitespace
        $lines = explode("\n", $template);
        $lines = array_filter($lines, fn($line) => trim(preg_replace('/\{[^}]+\}/', '', $line)) !== '');
        $lines = array_map('trim', $lines);
        
        return implode("\n", $lines);
    }

    /**
     * Format address for envelope printing
     */
    public function formatForEnvelope(array $addressData): string
    {
        if ($this->envelope_template) {
            return $this->formatWithTemplate($addressData, $this->envelope_template);
        }
        
        return $this->formatAddress($addressData);
    }

    /**
     * Validate address data against format requirements
     */
    public function validateAddress(array $addressData): array
    {
        $errors = [];
        
        // Check required fields
        foreach ($this->required_fields as $field) {
            if (empty($addressData[$field])) {
                $label = $this->getFieldLabel($field);
                $errors[$field] = "The {$label} field is required.";
            }
        }
        
        // Validate postal code format
        if (!empty($addressData['postal_code']) && $this->postal_code_format) {
            if (!preg_match($this->postal_code_format, $addressData['postal_code'])) {
                $errors['postal_code'] = "The postal code format is invalid.";
            }
        }
        
        // Validate state options if provided
        if (!empty($addressData['state']) && $this->state_options && $this->show_state_field) {
            $validStates = array_column($this->state_options, 'code');
            if (!in_array($addressData['state'], $validStates, true)) {
                $errors['state'] = "The selected state is invalid.";
            }
        }
        
        return $errors;
    }

    /**
     * Get label for a field
     */
    public function getFieldLabel(string $field): string
    {
        return $this->field_labels[$field] ?? self::STANDARD_FIELDS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Get ordered fields for form rendering
     */
    public function getOrderedFields(): array
    {
        $ordered = [];
        
        foreach ($this->field_order as $field) {
            $ordered[$field] = [
                'label' => $this->getFieldLabel($field),
                'required' => in_array($field, $this->required_fields, true),
                'visible' => $this->isFieldVisible($field),
            ];
        }
        
        return $ordered;
    }

    /**
     * Check if a field should be visible
     */
    public function isFieldVisible(string $field): bool
    {
        if ($field === 'state') {
            return $this->show_state_field;
        }
        
        return true; // Most fields are visible by default
    }

    /**
     * Get state/province options
     */
    public function getStateOptions(): array
    {
        return $this->state_options ?? [];
    }

    /**
     * Format with a specific template
     */
    private function formatWithTemplate(array $addressData, string $template): string
    {
        $formatted = $template;
        
        foreach ($addressData as $field => $value) {
            if ($value) {
                $formatted = str_replace("{{$field}}", $value, $formatted);
            }
        }
        
        // Clean up
        $lines = explode("\n", $formatted);
        $lines = array_filter($lines, fn($line) => trim(preg_replace('/\{[^}]+\}/', '', $line)) !== '');
        $lines = array_map('trim', $lines);
        
        return implode("\n", $lines);
    }

    /**
     * Get default US address format
     */
    public static function getUSDefaults(): array
    {
        return [
            'field_order' => ['name', 'company', 'line1', 'line2', 'city', 'state', 'postal_code'],
            'required_fields' => ['name', 'line1', 'city', 'state', 'postal_code'],
            'field_labels' => [
                'line1' => 'Street Address',
                'line2' => 'Apartment, suite, etc.',
                'postal_code' => 'ZIP Code',
                'state' => 'State',
            ],
            'postal_code_format' => '/^\d{5}(-\d{4})?$/',
            'postal_code_label' => 'ZIP Code',
            'postal_code_before_city' => false,
            'state_label' => 'State',
            'show_state_field' => true,
            'display_template' => "{name}\n{company}\n{line1}\n{line2}\n{city}, {state} {postal_code}",
        ];
    }

    /**
     * Get default UK address format
     */
    public static function getUKDefaults(): array
    {
        return [
            'field_order' => ['name', 'company', 'line1', 'line2', 'line3', 'city', 'state', 'postal_code'],
            'required_fields' => ['name', 'line1', 'city', 'postal_code'],
            'field_labels' => [
                'line1' => 'Address Line 1',
                'line2' => 'Address Line 2',
                'line3' => 'Address Line 3',
                'postal_code' => 'Post Code',
                'state' => 'County',
            ],
            'postal_code_format' => '/^[A-Z]{1,2}[0-9R][0-9A-Z]? [0-9][A-Z]{2}$/',
            'postal_code_label' => 'Post Code',
            'postal_code_before_city' => false,
            'state_label' => 'County',
            'show_state_field' => true,
            'display_template' => "{name}\n{company}\n{line1}\n{line2}\n{line3}\n{city}\n{state}\n{postal_code}",
        ];
    }
}