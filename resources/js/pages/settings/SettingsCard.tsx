import type { ReactNode } from 'react';

/**
 * One block of a settings panel.
 *
 * A settings page is a long list of unrelated decisions, and the only thing
 * that makes it readable is grouping them and saying what each group is for.
 * Extracted so every panel gets the same heading weight and the same spacing —
 * the moment two panels invent their own, the page reads as three pages.
 */
export function SettingsCard({
    title,
    blurb,
    actions,
    children,
}: {
    title: string;
    blurb?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="card overflow-hidden">
            <div className="flex flex-wrap items-center gap-3 border-b border-[var(--color-border-light)] px-5 py-3">
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">{title}</h3>
                    {blurb && <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">{blurb}</p>}
                </div>
                {actions && <div className="flex flex-none items-center gap-2">{actions}</div>}
            </div>

            <div className="p-5">{children}</div>
        </section>
    );
}
