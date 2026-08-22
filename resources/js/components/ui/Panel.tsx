import { type ReactNode } from 'react';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type PanelProps = {
    /** The panel's name, shown at the left of its head. */
    title: string;
    /**
     * The unit every figure in this panel is in — a currency code, usually.
     *
     * Shown once in brackets beside the title rather than repeated on each
     * axis label, where five identical symbols say the same thing five times
     * and take the width the digits actually need.
     */
    unit?: string;
    /** A qualifier sat beside the title — "last 30 days", "all time". */
    note?: string;
    /** Controls for the right of the head: a period selector, a filter. */
    action?: ReactNode;
    /** Where "View all ›" goes. Ignored when `action` is given. */
    href?: string;
    /** Wording for the href link, when "View all" is not what it does. */
    linkLabel?: string;
    /** Drop the body's padding, for a panel whose body is a list or a table
     *  that should run to the panel's own edges. */
    flush?: boolean;
    children: ReactNode;
    className?: string;
};

/**
 * A titled box on a dashboard.
 *
 * ── Why this exists rather than a .card and a heading per panel ──────────────
 *
 * Eight panels written eight times drift: one head is 12px of padding and
 * another is 16px, one title is semibold and the next is bold, one "View all"
 * is a link and another is a button. Individually none of it is wrong and
 * together it reads as a page assembled from parts of other pages.
 *
 * So the head is decided once. A panel says what it is called and what it
 * offers; how that looks is not its business.
 *
 * @example
 * <Panel title="Recent orders" href="/orders">…</Panel>
 * <Panel title="Revenue" action={<PeriodTabs …/>}>…</Panel>
 */
export function Panel({
    title,
    unit,
    note,
    action,
    href,
    linkLabel = 'View all',
    flush = false,
    children,
    className,
}: PanelProps) {
    return (
        <section className={cn('panel', className)}>
            <header className="panel-head">
                <div className="flex min-w-0 items-baseline gap-2">
                    <h2 className="panel-title truncate">
                        {title}
                        {unit && (
                            <span className="ml-1.5 text-xs font-normal text-[var(--color-text-subtle)]">
                                ({unit})
                            </span>
                        )}
                    </h2>
                    {note && <span className="panel-note whitespace-nowrap">{note}</span>}
                </div>

                {action ??
                    (href ? (
                        <Link to={href} className="panel-link">
                            {linkLabel}
                            <Icon name="caret-right" size={12} />
                        </Link>
                    ) : null)}
            </header>

            <div className={cn('panel-body', flush && 'is-flush')}>{children}</div>
        </section>
    );
}

type PanelStateProps = {
    isPending?: boolean;
    isError?: boolean;
    isEmpty?: boolean;
    /** What is missing, in the subscriber's words: "No orders yet". */
    emptyMessage?: string;
    emptyIcon?: string;
    /** What failed, for the error case: "Recent orders could not be loaded." */
    errorMessage?: string;
    onRetry?: () => void;
    /** Skeleton rows to draw while pending. */
    rows?: number;
    children: ReactNode;
};

/**
 * The three states before a panel has anything to show.
 *
 * ── Why empty and failed must not look the same ──────────────────────────────
 *
 * Written per panel these collapse into one another, and the collapse always
 * goes the same way: an empty list gets the error treatment, and somebody who
 * has simply not sold anything yet is told their data failed to load. One is a
 * fault to report and the other is a shop that opened this morning, and the
 * difference is a support call.
 *
 * So empty is stated plainly and without alarm, and only a genuine failure
 * offers a retry.
 */
export function PanelState({
    isPending,
    isError,
    isEmpty,
    emptyMessage = 'Nothing here yet',
    emptyIcon = 'tray',
    errorMessage = 'That could not be loaded.',
    onRetry,
    rows = 4,
    children,
}: PanelStateProps) {
    if (isPending) {
        return (
            <div className="space-y-3 p-4">
                {Array.from({ length: rows }, (_, i) => (
                    <div key={i} className="flex items-center gap-3">
                        <div className="animate-pulse size-9 flex-none rounded-[var(--shell-radius-sm)]" />
                        <div className="flex-1 space-y-1.5">
                            <div className="animate-pulse h-3.5 w-2/5 rounded-[var(--shell-radius-sm)]" />
                            <div className="animate-pulse h-3 w-1/4 rounded-[var(--shell-radius-sm)]" />
                        </div>
                        <div className="animate-pulse h-3.5 w-16 flex-none rounded-[var(--shell-radius-sm)]" />
                    </div>
                ))}
            </div>
        );
    }

    if (isError) {
        return (
            <div className="px-4 py-10 text-center">
                <Icon
                    name="warning-circle"
                    size={24}
                    className="mx-auto text-[var(--color-text-subtle)]"
                />
                <p className="mt-2 text-sm text-[var(--color-text-body)]">{errorMessage}</p>
                {onRetry && (
                    <button type="button" onClick={onRetry} className="btn btn-secondary mt-3 text-xs">
                        Try again
                    </button>
                )}
            </div>
        );
    }

    if (isEmpty) {
        return (
            <div className="px-4 py-10 text-center">
                <Icon name={emptyIcon} size={26} className="mx-auto text-[var(--color-text-subtle)]" />
                <p className="mt-2 text-sm text-[var(--color-text-muted)]">{emptyMessage}</p>
            </div>
        );
    }

    return <>{children}</>;
}

type PanelRowProps = {
    /** The leading mark — an icon name, or initials when no icon suits. */
    icon?: string;
    initials?: string;
    /** The line that identifies the record. */
    title: string;
    /** The quieter line under it — a reference, an email, a date. */
    subtitle?: string;
    /** Up to two labelled figures at the far end. */
    stats?: Array<{ label: string; value: ReactNode }>;
    /** Anything after the figures — usually a StatusBadge. */
    trailing?: ReactNode;
    href?: string;
};

/**
 * One record inside a panel.
 *
 * The figures carry their own labels because a panel is too narrow to give
 * three columns a header row, and a bare "$5,000" beside a name does not say
 * whether that is what they spent, owe, or are worth.
 */
export function PanelRow({
    icon,
    initials,
    title,
    subtitle,
    stats = [],
    trailing,
    href,
}: PanelRowProps) {
    const inner = (
        <>
            {(icon || initials) && (
                <span className="panel-row-mark">
                    {icon ? <Icon name={icon} size={16} /> : initials}
                </span>
            )}

            {/* Titled with their own text. These truncate to fit the row, and
                a name cut to "Beats He…" is unreadable with no way to find
                out what it was — the tooltip is the way out, and it costs an
                attribute. */}
            <div className="min-w-0 flex-1">
                <p
                    className="truncate text-[0.8125rem] font-semibold text-[var(--color-text-main)]"
                    title={title}
                >
                    {title}
                </p>
                {subtitle && (
                    <p className="truncate text-xs text-[var(--color-text-muted)]" title={subtitle}>
                        {subtitle}
                    </p>
                )}
            </div>

            {stats.map((stat) => (
                <div key={stat.label} className="hidden flex-none text-right sm:block">
                    <p className="panel-row-label">{stat.label}</p>
                    <p className="panel-row-value">{stat.value}</p>
                </div>
            ))}

            {trailing && <div className="flex-none">{trailing}</div>}
        </>
    );

    return href ? (
        <Link to={href} className="panel-row">
            {inner}
        </Link>
    ) : (
        <div className="panel-row">{inner}</div>
    );
}
