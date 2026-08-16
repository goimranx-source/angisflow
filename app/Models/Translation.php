<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToAccount;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Translatable text content for multi-language support
 *
 * Stores translations for system messages, business-specific content, and
 * module text. Supports both system-wide translations and business-specific
 * customizations with approval workflows.
 */
class Translation extends Model
{
    use HasFactory, BelongsToAccount, HasPublicId, SoftDeletes;

    protected $fillable = [
        'account_id',
        'public_id',
        'translation_key',
        'language_code',
        'translation_value',
        'context_type',
        'context_id',
        'translation_group',
        'is_system_translation',
        'requires_approval',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'is_system_translation' => 'boolean',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'draft' => 'Draft',
        'approved' => 'Approved',
        'deprecated' => 'Deprecated',
    ];

    public const CONTEXT_TYPES = [
        'system' => 'System-wide',
        'business' => 'Business-specific', 
        'module' => 'Module-specific',
        'custom' => 'Custom content',
    ];

    /**
     * Get translation by key and language
     */
    public static function getTranslation(
        string $key, 
        string $languageCode, 
        ?string $contextType = null,
        ?string $contextId = null,
        ?string $fallbackLanguage = 'en'
    ): ?string {
        // Try exact match first
        $translation = self::where('translation_key', $key)
            ->where('language_code', $languageCode)
            ->where('status', 'active')
            ->when($contextType, fn($q) => $q->where('context_type', $contextType))
            ->when($contextId, fn($q) => $q->where('context_id', $contextId))
            ->orderBy('is_system_translation') // Business translations take precedence
            ->first();
        
        if ($translation) {
            return $translation->translation_value;
        }
        
        // Try fallback language
        if ($fallbackLanguage && $languageCode !== $fallbackLanguage) {
            $translation = self::where('translation_key', $key)
                ->where('language_code', $fallbackLanguage)
                ->where('status', 'active')
                ->when($contextType, fn($q) => $q->where('context_type', $contextType))
                ->when($contextId, fn($q) => $q->where('context_id', $contextId))
                ->orderBy('is_system_translation')
                ->first();
            
            if ($translation) {
                return $translation->translation_value;
            }
        }
        
        // Return key if no translation found
        return $key;
    }

    /**
     * Set translation value
     */
    public static function setTranslation(
        string $key,
        string $languageCode,
        string $value,
        ?string $contextType = null,
        ?string $contextId = null,
        ?string $group = null,
        bool $requiresApproval = false
    ): self {
        return self::updateOrCreate(
            [
                'translation_key' => $key,
                'language_code' => $languageCode,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ],
            [
                'translation_value' => $value,
                'translation_group' => $group,
                'requires_approval' => $requiresApproval,
                'status' => $requiresApproval ? 'draft' : 'active',
                'is_system_translation' => false,
            ]
        );
    }

    /**
     * Get translations for a group
     */
    public static function getTranslationGroup(
        string $group,
        string $languageCode,
        ?string $contextType = null,
        ?string $contextId = null
    ): array {
        $translations = self::where('translation_group', $group)
            ->where('language_code', $languageCode)
            ->where('status', 'active')
            ->when($contextType, fn($q) => $q->where('context_type', $contextType))
            ->when($contextId, fn($q) => $q->where('context_id', $contextId))
            ->get();
        
        return $translations->pluck('translation_value', 'translation_key')->toArray();
    }

    /**
     * Import translations from array
     */
    public static function importTranslations(
        array $translations,
        string $languageCode,
        ?string $group = null,
        ?string $contextType = null,
        ?string $contextId = null
    ): int {
        $imported = 0;
        
        foreach ($translations as $key => $value) {
            self::setTranslation(
                $key,
                $languageCode,
                $value,
                $contextType,
                $contextId,
                $group
            );
            $imported++;
        }
        
        return $imported;
    }

    /**
     * Export translations to array
     */
    public static function exportTranslations(
        string $languageCode,
        ?string $group = null,
        ?string $contextType = null,
        ?string $contextId = null
    ): array {
        $query = self::where('language_code', $languageCode)
            ->where('status', 'active');
        
        if ($group) {
            $query->where('translation_group', $group);
        }
        
        if ($contextType) {
            $query->where('context_type', $contextType);
        }
        
        if ($contextId) {
            $query->where('context_id', $contextId);
        }
        
        return $query->pluck('translation_value', 'translation_key')->toArray();
    }

    /**
     * Get available languages for a context
     */
    public static function getAvailableLanguages(
        ?string $contextType = null,
        ?string $contextId = null
    ): array {
        $query = self::select('language_code')
            ->where('status', 'active')
            ->distinct();
        
        if ($contextType) {
            $query->where('context_type', $contextType);
        }
        
        if ($contextId) {
            $query->where('context_id', $contextId);
        }
        
        return $query->pluck('language_code')->toArray();
    }

    /**
     * Approve translation
     */
    public function approve(string $approvedBy): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);
    }

    /**
     * Check if translation needs approval
     */
    public function needsApproval(): bool
    {
        return $this->requires_approval && $this->status === 'draft';
    }

    /**
     * Check if translation is active
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'approved'], true);
    }

    /**
     * Get missing translations for a language
     */
    public static function getMissingTranslations(
        string $sourceLanguage,
        string $targetLanguage,
        ?string $contextType = null,
        ?string $contextId = null
    ): array {
        $sourceKeys = self::where('language_code', $sourceLanguage)
            ->where('status', 'active')
            ->when($contextType, fn($q) => $q->where('context_type', $contextType))
            ->when($contextId, fn($q) => $q->where('context_id', $contextId))
            ->pluck('translation_key')
            ->toArray();
        
        $targetKeys = self::where('language_code', $targetLanguage)
            ->where('status', 'active')
            ->when($contextType, fn($q) => $q->where('context_type', $contextType))
            ->when($contextId, fn($q) => $q->where('context_id', $contextId))
            ->pluck('translation_key')
            ->toArray();
        
        return array_diff($sourceKeys, $targetKeys);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function contextTypeLabel(): string
    {
        return self::CONTEXT_TYPES[$this->context_type] ?? ucfirst($this->context_type ?? 'None');
    }

    /**
     * Scope for active translations only
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'approved']);
    }

    /**
     * Scope for specific language
     */
    public function scopeForLanguage($query, string $languageCode)
    {
        return $query->where('language_code', $languageCode);
    }

    /**
     * Scope for specific context
     */
    public function scopeForContext($query, string $contextType, ?string $contextId = null)
    {
        $query->where('context_type', $contextType);
        
        if ($contextId) {
            $query->where('context_id', $contextId);
        }
        
        return $query;
    }

    /**
     * Get translation statistics
     */
    public static function getTranslationStats(?string $contextType = null, ?string $contextId = null): array
    {
        $query = self::query();
        
        if ($contextType) {
            $query->where('context_type', $contextType);
        }
        
        if ($contextId) {
            $query->where('context_id', $contextId);
        }
        
        $stats = $query->selectRaw('
            language_code,
            status,
            COUNT(*) as count
        ')
        ->groupBy(['language_code', 'status'])
        ->get()
        ->groupBy('language_code');
        
        $result = [];
        foreach ($stats as $language => $statusCounts) {
            $result[$language] = [
                'total' => $statusCounts->sum('count'),
                'active' => $statusCounts->where('status', 'active')->sum('count'),
                'draft' => $statusCounts->where('status', 'draft')->sum('count'),
                'approved' => $statusCounts->where('status', 'approved')->sum('count'),
                'deprecated' => $statusCounts->where('status', 'deprecated')->sum('count'),
            ];
        }
        
        return $result;
    }
}