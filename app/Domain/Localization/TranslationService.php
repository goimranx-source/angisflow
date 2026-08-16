<?php

declare(strict_types=1);

namespace App\Domain\Localization;

use App\Domain\Tenancy\TenantContext;
use App\Models\Translation;
use App\Models\BusinessLocalizationSettings;
use Illuminate\Support\Collection;

/**
 * Translation management service
 *
 * Handles translation operations including import/export, validation,
 * approval workflows, and multi-language content management.
 */
class TranslationService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    /**
     * Import translations from an array or file
     */
    public function importTranslations(
        array $translations,
        string $languageCode,
        ?string $group = null,
        ?string $contextType = 'business',
        bool $overwrite = false
    ): array {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($translations as $key => $value) {
            if (empty($key) || empty($value)) {
                $skipped++;
                continue;
            }

            $existing = Translation::where('translation_key', $key)
                ->where('language_code', $languageCode)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->first();

            if ($existing && !$overwrite) {
                $skipped++;
                continue;
            }

            if ($existing) {
                $existing->update([
                    'translation_value' => $value,
                    'translation_group' => $group,
                    'status' => 'active',
                ]);
                $updated++;
            } else {
                Translation::create([
                    'account_id' => $this->tenantContext->account()->id,
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'translation_value' => $value,
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'translation_group' => $group,
                    'status' => 'active',
                    'is_system_translation' => false,
                ]);
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($translations),
        ];
    }

    /**
     * Export translations to array
     */
    public function exportTranslations(
        string $languageCode,
        ?string $group = null,
        ?string $contextType = 'business',
        bool $includeSystem = false
    ): array {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        $query = Translation::where('language_code', $languageCode)
            ->where('status', 'active');

        if ($contextType) {
            $query->where('context_type', $contextType);
        }

        if ($contextId) {
            $query->where('context_id', $contextId);
        }

        if ($group) {
            $query->where('translation_group', $group);
        }

        if (!$includeSystem) {
            $query->where('is_system_translation', false);
        }

        return $query->orderBy('translation_key')
            ->pluck('translation_value', 'translation_key')
            ->toArray();
    }

    /**
     * Batch create or update translations
     */
    public function batchSetTranslations(
        array $keyValuePairs,
        string $languageCode,
        ?string $group = null,
        ?string $contextType = 'business'
    ): int {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        $processed = 0;

        foreach ($keyValuePairs as $key => $value) {
            Translation::setTranslation(
                $key,
                $languageCode,
                $value,
                $contextType,
                $contextId,
                $group
            );
            $processed++;
        }

        return $processed;
    }

    /**
     * Find missing translations between languages
     */
    public function findMissingTranslations(
        string $sourceLanguage,
        string $targetLanguage,
        ?string $contextType = 'business'
    ): array {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        $missing = Translation::getMissingTranslations(
            $sourceLanguage,
            $targetLanguage,
            $contextType,
            $contextId
        );

        // Get the source translations for the missing keys
        $sourceTranslations = Translation::whereIn('translation_key', $missing)
            ->where('language_code', $sourceLanguage)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->where('status', 'active')
            ->get();

        return $sourceTranslations->map(function ($translation) use ($targetLanguage) {
            return [
                'key' => $translation->translation_key,
                'source_value' => $translation->translation_value,
                'source_language' => $translation->language_code,
                'target_language' => $targetLanguage,
                'group' => $translation->translation_group,
                'context_type' => $translation->context_type,
                'context_id' => $translation->context_id,
            ];
        })->toArray();
    }

    /**
     * Auto-translate missing translations (placeholder for translation service integration)
     */
    public function autoTranslateMissing(
        string $sourceLanguage,
        string $targetLanguage,
        ?string $translationService = 'placeholder'
    ): array {
        $missing = $this->findMissingTranslations($sourceLanguage, $targetLanguage);
        $translated = 0;

        foreach ($missing as $missingTranslation) {
            // In a real implementation, this would call a translation service
            // For now, we'll create placeholder translations
            $translatedValue = $this->mockTranslateText(
                $missingTranslation['source_value'],
                $sourceLanguage,
                $targetLanguage
            );

            Translation::setTranslation(
                $missingTranslation['key'],
                $targetLanguage,
                $translatedValue,
                $missingTranslation['context_type'],
                $missingTranslation['context_id'],
                $missingTranslation['group'],
                true // Require approval for auto-translated content
            );

            $translated++;
        }

        return [
            'translated' => $translated,
            'total_missing' => count($missing),
        ];
    }

    /**
     * Get translation completion statistics
     */
    public function getTranslationStats(?string $contextType = 'business'): array
    {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        return Translation::getTranslationStats($contextType, $contextId);
    }

    /**
     * Validate translations for completeness
     */
    public function validateTranslations(
        array $languageCodes,
        ?string $contextType = 'business'
    ): array {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        $results = [];

        // Get all unique translation keys
        $allKeys = Translation::where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->where('status', 'active')
            ->distinct()
            ->pluck('translation_key')
            ->toArray();

        foreach ($languageCodes as $language) {
            $existingKeys = Translation::where('language_code', $language)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->where('status', 'active')
                ->pluck('translation_key')
                ->toArray();

            $missing = array_diff($allKeys, $existingKeys);
            $completeness = empty($allKeys) ? 100 : ((count($allKeys) - count($missing)) / count($allKeys)) * 100;

            $results[$language] = [
                'total_keys' => count($allKeys),
                'translated_keys' => count($existingKeys),
                'missing_keys' => count($missing),
                'completeness_percentage' => round($completeness, 2),
                'missing_key_list' => $missing,
            ];
        }

        return $results;
    }

    /**
     * Copy translations from one language to another
     */
    public function copyTranslations(
        string $sourceLanguage,
        string $targetLanguage,
        ?array $specificKeys = null,
        ?string $contextType = 'business',
        bool $overwrite = false
    ): array {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        $query = Translation::where('language_code', $sourceLanguage)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->where('status', 'active');

        if ($specificKeys) {
            $query->whereIn('translation_key', $specificKeys);
        }

        $sourceTranslations = $query->get();
        $copied = 0;
        $skipped = 0;

        foreach ($sourceTranslations as $sourceTranslation) {
            $exists = Translation::where('translation_key', $sourceTranslation->translation_key)
                ->where('language_code', $targetLanguage)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->exists();

            if ($exists && !$overwrite) {
                $skipped++;
                continue;
            }

            Translation::setTranslation(
                $sourceTranslation->translation_key,
                $targetLanguage,
                $sourceTranslation->translation_value, // Copy as-is, may need translation later
                $contextType,
                $contextId,
                $sourceTranslation->translation_group
            );

            $copied++;
        }

        return [
            'copied' => $copied,
            'skipped' => $skipped,
            'total' => $sourceTranslations->count(),
        ];
    }

    /**
     * Get available languages for business
     */
    public function getAvailableLanguages(?string $contextType = 'business'): array
    {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        return Translation::getAvailableLanguages($contextType, $contextId);
    }

    /**
     * Approve pending translations
     */
    public function approveTranslations(array $translationIds, string $approvedBy): int
    {
        $approved = 0;
        
        $translations = Translation::whereIn('id', $translationIds)
            ->where('status', 'draft')
            ->where('requires_approval', true)
            ->get();

        foreach ($translations as $translation) {
            $translation->approve($approvedBy);
            $approved++;
        }

        return $approved;
    }

    /**
     * Get translations that need approval
     */
    public function getPendingApprovals(?string $contextType = 'business'): Collection
    {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        return Translation::where('status', 'draft')
            ->where('requires_approval', true)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Delete translations by key or language
     */
    public function deleteTranslations(
        ?array $keys = null,
        ?string $languageCode = null,
        ?string $contextType = 'business'
    ): int {
        $contextId = $contextType === 'business' ? (string) $this->tenantContext->business()->id : null;
        
        $query = Translation::where('context_type', $contextType)
            ->where('context_id', $contextId);

        if ($keys) {
            $query->whereIn('translation_key', $keys);
        }

        if ($languageCode) {
            $query->where('language_code', $languageCode);
        }

        return $query->delete();
    }

    /**
     * Mock translation function (placeholder for real translation service)
     */
    private function mockTranslateText(string $text, string $fromLang, string $toLang): string
    {
        // This is a placeholder. In production, integrate with:
        // - Google Translate API
        // - Azure Translator
        // - AWS Translate
        // - DeepL API
        
        return "[AUTO-TRANSLATED from {$fromLang}] {$text}";
    }

    /**
     * Create system translations for common business terms
     */
    public function seedCommonBusinessTranslations(string $languageCode = 'en'): int
    {
        $commonTranslations = [
            // Navigation
            'nav.dashboard' => 'Dashboard',
            'nav.customers' => 'Customers',
            'nav.products' => 'Products',
            'nav.orders' => 'Orders',
            'nav.invoices' => 'Invoices',
            'nav.reports' => 'Reports',
            'nav.settings' => 'Settings',
            
            // Actions
            'action.create' => 'Create',
            'action.edit' => 'Edit',
            'action.delete' => 'Delete',
            'action.save' => 'Save',
            'action.cancel' => 'Cancel',
            'action.submit' => 'Submit',
            'action.search' => 'Search',
            'action.filter' => 'Filter',
            'action.export' => 'Export',
            'action.import' => 'Import',
            
            // Status
            'status.active' => 'Active',
            'status.inactive' => 'Inactive',
            'status.pending' => 'Pending',
            'status.completed' => 'Completed',
            'status.cancelled' => 'Cancelled',
            'status.draft' => 'Draft',
            
            // Financial
            'finance.total' => 'Total',
            'finance.subtotal' => 'Subtotal',
            'finance.tax' => 'Tax',
            'finance.discount' => 'Discount',
            'finance.amount' => 'Amount',
            'finance.balance' => 'Balance',
            'finance.due_date' => 'Due Date',
            'finance.invoice_number' => 'Invoice Number',
            
            // Common Fields
            'field.name' => 'Name',
            'field.email' => 'Email',
            'field.phone' => 'Phone',
            'field.address' => 'Address',
            'field.city' => 'City',
            'field.state' => 'State',
            'field.postal_code' => 'Postal Code',
            'field.country' => 'Country',
            'field.date' => 'Date',
            'field.description' => 'Description',
        ];

        return $this->batchSetTranslations(
            $commonTranslations,
            $languageCode,
            'common',
            'system'
        );
    }
}