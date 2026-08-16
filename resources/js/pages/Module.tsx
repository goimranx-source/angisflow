import { Link, useParams } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useModules } from '@/hooks/useModules';

/**
 * A department that is mapped out but not yet built.
 *
 * These sit in the menu on purpose. Deciding where something belongs before
 * building it is what stops it being bolted on sideways later, and a menu that
 * rearranges itself as things ship is a menu nobody trusts.
 *
 * Each page says what the department is for and what it will do, in enough
 * detail that somebody opening it in a year knows what was meant — including
 * whoever ends up building it.
 */
export default function Module() {
    const { key } = useParams<{ key: string }>();

    const { data, isPending } = useModules();

    const module = data?.data.find((candidate) => candidate.key === key);

    useDocumentTitle(module?.label ?? null);

    if (isPending) {
        return <p className="text-sm text-[var(--color-text-muted)]">Loading…</p>;
    }

    if (!module) {
        return (
            <div className="mx-auto max-w-2xl py-16 text-center">
                <h1 className="text-xl font-bold">No such department</h1>
                <Link to="/roadmap" className="btn btn-secondary mt-5">
                    See what is planned
                </Link>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader
                eyebrow={module.group_label ?? undefined}
                title={module.label}
                description={module.summary}
                icon={module.icon}
            />

            <div className="mt-6 space-y-5">
                {module.why && (
                    <section className="card p-6">
                        <h2 className="text-sm font-semibold tracking-wide text-[var(--color-text-muted)] uppercase">
                            Why it matters
                        </h2>
                        <p className="mt-2 text-[var(--color-text-body)]">{module.why}</p>
                    </section>
                )}

                {module.does && module.does.length > 0 && (
                    <section className="card p-6">
                        <h2 className="text-sm font-semibold tracking-wide text-[var(--color-text-muted)] uppercase">
                            What it will do
                        </h2>
                        <ul className="mt-3 space-y-2">
                            {module.does.map((line) => (
                                <li key={line} className="flex gap-2.5 text-[var(--color-text-body)]">
                                    <Icon
                                        name="check"
                                        size={15}
                                        weight="bold"
                                        className="mt-1 flex-none text-[var(--color-brand-active)]"
                                    />
                                    <span>{line}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <Link to="/roadmap" className="btn btn-ghost mt-6">
                <Icon name="arrow-left" size={15} />
                Everything that is planned
            </Link>
        </div>
    );
}
