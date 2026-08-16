import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useModules } from '@/hooks/useModules';
import type { PlannedModule } from '@/types';

/**
 * Everything mapped out but not yet built.
 *
 * Derived from the module catalogue rather than written separately, so it
 * cannot claim something is coming that the menu does not offer, or go stale
 * the week a department ships.
 *
 * The catalogue is fetched at a versioned URL, so it is cached for the life of
 * the deploy and re-read the moment one ships. See useModules.
 */
export default function Roadmap() {
    const { data, isPending } = useModules();

    useDocumentTitle('Roadmap');

    const modules = data?.data ?? [];

    const byGroup = modules.reduce<Record<string, PlannedModule[]>>((groups, module) => {
        const key = module.group_label ?? 'Other';
        (groups[key] ??= []).push(module);

        return groups;
    }, {});

    return (
        <div className="mx-auto max-w-5xl">
            <PageHeader
                title="What is coming"
                description="Every department this tool intends to cover, and what each one will do."
            />

            {isPending && <p className="mt-6 text-sm text-[var(--color-text-muted)]">Loading…</p>}

            <div className="mt-6 space-y-8">
                {Object.entries(byGroup).map(([group, items]) => (
                    <section key={group}>
                        <h2 className="text-xs font-semibold tracking-[0.12em] text-[var(--color-text-muted)] uppercase">
                            {group}
                        </h2>

                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {items.map((module) => (
                                <Link
                                    key={module.key}
                                    to={`/soon/${module.key}`}
                                    className="card flex gap-3 p-4 transition-colors hover:border-[var(--color-border-strong)]"
                                >
                                    <span className="grid size-9 flex-none place-items-center rounded-xl bg-[var(--color-brand-subtle)] text-[var(--color-ink-soft)]">
                                        <Icon name={module.icon} size={18} />
                                    </span>

                                    <span className="min-w-0">
                                        <span className="block font-semibold text-[var(--color-text-main)]">
                                            {module.label}
                                        </span>
                                        <span className="mt-0.5 block text-sm text-[var(--color-text-muted)]">
                                            {module.summary}
                                        </span>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </div>
    );
}
