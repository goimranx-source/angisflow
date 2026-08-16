<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Catalogue\Models\WorkspaceModule;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Switching modules on and off for a workspace.
 *
 * ── Presets are a starting point, not a rule ─────────────────────────────────
 *
 * provision() runs once, when the workspace is created, and copies the chosen
 * category's defaults into rows the workspace then owns outright. It is never
 * run again. Re-deriving from the preset later would mean every edit to our
 * defaults silently overruled subscribers who had deliberately disagreed with
 * them — the one behaviour guaranteed to make people stop trusting the
 * settings screen.
 *
 * A workspace created without a category falls back to the "Something else"
 * preset rather than to core alone. That category exists precisely for people
 * who do not recognise themselves in the list, and its broad set is a far
 * better first run than a rail of six items — which reads as a broken account
 * rather than a deliberate blank slate.
 */
final class ModuleProvisioner
{
    /**
     * Seed a new workspace from its category's presets.
     *
     * Safe to call on a workspace that already has rows — existing ones are
     * left exactly as they are, so this can be used to backfill modules added
     * to the catalogue after the workspace was created without disturbing
     * anything the subscriber has since decided.
     */
    public function provision(Workspace $workspace, ?BusinessCategory $category = null): void
    {
        $category ??= $workspace->businessCategory
            ?? BusinessCategory::where('key', 'other')->first();

        $source = $category?->presetSource();

        $defaults = $source
            ? $source->modules()->pluck('enabled_by_default', 'modules.id')->all()
            : [];

        DB::transaction(function () use ($workspace, $defaults): void {
            $existing = WorkspaceModule::where('workspace_id', $workspace->id)
                ->pluck('module_id')
                ->all();

            $rows = [];
            $now = now();

            foreach (Module::all(['id', 'is_core']) as $module) {
                if (in_array($module->id, $existing, true)) {
                    continue;
                }

                $rows[] = [
                    'workspace_id' => $workspace->id,
                    'module_id' => $module->id,
                    // Core is on whatever the preset says — it cannot be off.
                    'is_enabled' => $module->is_core
                        ? true
                        : (bool) ($defaults[$module->id] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                WorkspaceModule::insert($rows);
            }
        });

        ModuleAccess::forgetWorkspace($workspace->id);
    }

    public function enable(Workspace $workspace, Module $module, ?User $actor = null): void
    {
        DB::transaction(function () use ($workspace, $module, $actor): void {
            // Turning something on pulls its dependencies up with it. The
            // alternative — refusing until the subscriber works out that
            // Payroll needs Employees — is a puzzle, not a safeguard.
            foreach ($this->dependenciesOf($module) as $dependency) {
                $this->write($workspace, $dependency, true, $actor);
            }

            $this->write($workspace, $module, true, $actor);
        });

        ModuleAccess::forgetWorkspace($workspace->id);
    }

    /**
     * @throws RuntimeException if the module is core, or something enabled needs it
     */
    public function disable(Workspace $workspace, Module $module, ?User $actor = null): void
    {
        if ($module->is_core) {
            throw new RuntimeException(
                "{$module->label} is part of how Prism works and cannot be switched off."
            );
        }

        $blockers = $this->enabledDependentsOf($workspace, $module);

        if ($blockers !== []) {
            // Named, not counted. "2 modules depend on this" leaves somebody
            // hunting; the list tells them exactly what to turn off first.
            throw new RuntimeException(sprintf(
                '%s is still needed by %s. Switch those off first.',
                $module->label,
                implode(', ', $blockers),
            ));
        }

        $this->write($workspace, $module, false, $actor);

        ModuleAccess::forgetWorkspace($workspace->id);
    }

    /**
     * Enabled modules in this workspace that name the given one in `requires`.
     *
     * @return list<string>  their labels
     */
    public function enabledDependentsOf(Workspace $workspace, Module $module): array
    {
        $enabled = $workspace->modules()
            ->wherePivot('is_enabled', true)
            ->get(['modules.id', 'modules.key', 'modules.label', 'modules.requires']);

        return $enabled
            ->filter(fn (Module $candidate) => in_array(
                $module->key,
                $candidate->requires ?? [],
                true,
            ))
            ->pluck('label')
            ->values()
            ->all();
    }

    /**
     * Everything a module needs, resolved through the chain.
     *
     * Payroll needs Employees, and if Employees ever grows a requirement of
     * its own that has to come too — so this walks rather than reading one
     * level. The visited set is what stops a catalogue mistake from becoming
     * an infinite loop in production.
     *
     * @return list<Module>
     */
    private function dependenciesOf(Module $module, array &$visited = []): array
    {
        $resolved = [];

        foreach ($module->requires ?? [] as $key) {
            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;
            $dependency = Module::where('key', $key)->first();

            if ($dependency === null) {
                continue;
            }

            foreach ($this->dependenciesOf($dependency, $visited) as $nested) {
                $resolved[] = $nested;
            }

            $resolved[] = $dependency;
        }

        return $resolved;
    }

    private function write(Workspace $workspace, Module $module, bool $enabled, ?User $actor): void
    {
        WorkspaceModule::updateOrCreate(
            ['workspace_id' => $workspace->id, 'module_id' => $module->id],
            ['is_enabled' => $enabled, 'changed_at' => now(), 'changed_by' => $actor?->id],
        );
    }
}
