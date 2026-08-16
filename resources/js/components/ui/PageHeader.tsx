import type { CSSProperties, ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';

type PageHeaderProps = {
    title: string;
    description?: ReactNode;
    /** A small line above the title — the department a screen belongs to. */
    eyebrow?: string;
    icon?: string;
    actions?: ReactNode;
};

export function PageHeader({ title, description, eyebrow, icon, actions }: PageHeaderProps) {
    return (
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div className="flex min-w-0 gap-3">
                {icon && (
                    <span className="page-header-icon">
                        <Icon name={icon} size={18} weight="duotone" />
                    </span>
                )}

                <div className="min-w-0">
                    {eyebrow && (
                        <p className="text-[0.6875rem] font-semibold tracking-[0.12em] text-[var(--color-text-muted)] uppercase">
                            {eyebrow}
                        </p>
                    )}
                    <h1 className="text-xl font-bold tracking-[-0.02em] sm:text-2xl">{title}</h1>
                    {description && (
                        <p className="mt-0.5 text-[0.8125rem] text-[var(--color-text-muted)] sm:text-sm">
                            {description}
                        </p>
                    )}
                </div>
            </div>

            {actions && <div className="flex flex-none items-center gap-2">{actions}</div>}
        </div>
    );
}

/**
 * A placeholder with the same dimensions as the thing it stands in for.
 *
 * Matching the real height matters more than it looks: a skeleton that is
 * shorter than its content makes the page jump when the data arrives, which is
 * the exact "it reloaded" impression the whole architecture is avoiding. A
 * skeleton is only worth having if nothing moves when it is replaced.
 */
export function Skeleton({ className, style }: { className?: string; style?: CSSProperties }) {
    return (
        <div
            className={`animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-tint)] ${className ?? ''}`}
            style={style}
            aria-hidden
        />
    );
}
