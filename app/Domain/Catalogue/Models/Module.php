<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing Prism can offer.
 *
 * ── What this row does and does not decide ───────────────────────────────────
 *
 * It describes the module and states two rules about it: which capability a
 * user needs to see it (`capability`), and whether it may be switched off at
 * all (`is_core`). It says nothing about whether a given subscriber has it —
 * that answer is assembled per workspace from entitlement, enablement and
 * authorisation together, and lives in the resolver rather than here.
 *
 * ── Addressed by key ────────────────────────────────────────────────────────
 *
 * Seeds, presets, permission checks and `requires` all name modules by their
 * string key. Ids are an implementation detail that changes between
 * environments; `revenue.orders` means the same thing in every database.
 */
class Module extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'pillar_id',
        'key',
        'label',
        'icon',
        'summary',
        'path',
        'matches',
        'capability',
        'is_core',
        'is_built',
        'requires',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_built' => 'boolean',
            'requires' => 'array',
            'matches' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Where the sidebar sends this.
     *
     * An unbuilt module goes to its own "coming soon" page rather than being
     * hidden. Somebody looking for Payroll should find out that it is coming,
     * not conclude the product does not do payroll.
     */
    public function href(): string
    {
        return $this->is_built && $this->path !== null
            ? $this->path
            : '/soon/'.$this->key;
    }

    /**
     * URL prefixes this item should light up for.
     *
     * Longest first, so a more specific match wins where two share a stem:
     * /catalogue/stock must beat /catalogue.
     *
     * @return list<string>
     */
    public function matchPrefixes(): array
    {
        if (! $this->is_built || $this->path === null) {
            return ['/soon/'.$this->key];
        }

        $prefixes = array_unique([$this->path, ...($this->matches ?? [])]);

        usort($prefixes, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return array_values($prefixes);
    }

    public function pillar(): BelongsTo
    {
        return $this->belongsTo(ModulePillar::class, 'pillar_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_modules')
            ->withPivot(['is_enabled', 'changed_at', 'changed_by'])
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(BusinessCategory::class, 'category_module_presets')
            ->withPivot('enabled_by_default')
            ->withTimestamps();
    }

    /**
     * The categories this module is on for by default.
     *
     * ── Why this exists beside categories() ──────────────────────────────────
     *
     * `category_module_presets` holds a row for *every* module against *every*
     * category — currently 774 of them — because a preset records a decision
     * either way, and "off for this trade" is as much a decision as "on". So
     * `categories()` answers "which categories have an opinion about this
     * module", which is every one of them.
     *
     * Anything asking "does this business's trade actually want this module"
     * must use this relation instead. Filtering on the unscoped one is a
     * silent no-op: the intersection always matches, every module passes, and
     * the sidebar shows the whole catalogue to everybody — which is exactly
     * what it did before this was added.
     */
    public function defaultCategories(): BelongsToMany
    {
        return $this->categories()->wherePivot('enabled_by_default', true);
    }

    /** Only what actually exists — what the nav is allowed to offer. */
    public function scopeBuilt(Builder $query): Builder
    {
        return $query->where('is_built', true);
    }

    /**
     * Modules nobody may switch off.
     *
     * Two kinds qualify, and it is worth being clear which: those the platform
     * is structurally built on — the ledger every module posts into, the
     * customer record everything hangs off — and those we have simply decided
     * every subscriber gets whatever they sell, conversations and live chat
     * among them. The first kind would break the product; the second is a
     * commercial promise. Both are honoured the same way.
     */
    public function scopeCore(Builder $query): Builder
    {
        return $query->where('is_core', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'summary' => $this->summary,
            'is_core' => $this->is_core,
            'built' => $this->is_built,
        ];
    }
}
