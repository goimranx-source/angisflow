import { StatsCard } from '@/components/ui/StatsCard';

/**
 * The figures above a module's table.
 *
 * ── Why this is a translation and not a card ─────────────────────────────────
 *
 * There was a second card component here, drawn from scratch, and it did not
 * match the one the dashboard uses. Not wildly — a little more padding, a
 * slightly larger figure, the icon on the label's row instead of beside the
 * value — but enough that moving from the dashboard to a module page felt like
 * moving between two applications. Nobody could have told you which measurement
 * was different; everybody could see that something was.
 *
 * Two components drawing the same thing will always drift, because a change is
 * only ever made to the one somebody happened to open. So this one keeps its
 * name and its props, and draws nothing — StatsCard is the card, everywhere.
 *
 * The props stay as they were on purpose: a dozen module pages call this, and
 * their call sites are not what was wrong.
 */
type KPICardProps = {
    /** What the figure is. */
    label: string;
    /** The figure itself, already formatted. */
    value: string | number;
    icon: string;
    /** Change against the previous period, as a percentage. */
    delta?: number;
    /** Whether a rise is good news — falling costs are good, falling sales are not. */
    deltaIsGood?: boolean;
    /** What the change is measured against. */
    comparisonText?: string;
    trend?: 'up' | 'down' | 'flat';
    /** A sentence under the figure, for anything that needs explaining. */
    info?: string;
    onClick?: () => void;
    variant?: 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';
};

export function KPICard({
    label,
    value,
    icon,
    delta,
    deltaIsGood,
    comparisonText,
    trend,
    info,
    onClick,
    variant = 'brand',
}: KPICardProps) {
    return (
        <StatsCard
            label={label}
            value={value}
            icon={icon}
            // StatsCard has no neutral accent, and a neutral tile reads as
            // disabled rather than as plain. Brand is the honest default.
            accent={variant === 'neutral' ? 'brand' : variant}
            delta={delta}
            direction={trend}
            riseIsGood={deltaIsGood}
            trendLabel={comparisonText}
            comparisonHint={info}
            onClick={onClick}
        />
    );
}

/**
 * The same shape while it loads.
 *
 * Matched to StatsCard's own measurements — p-4, a 13px label, a 1.75rem figure
 * — so the page does not resize the moment the data lands. A skeleton that is
 * the wrong height is worse than none: it promises a layout and then breaks it.
 */
export function KPICardSkeleton() {
    return (
        <div className="card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div
                        className="h-[0.8125rem] w-24 animate-pulse bg-[var(--shell-muted)]"
                        style={{ borderRadius: 'var(--shell-radius-sm)' }}
                    />
                    <div
                        className="mt-1.5 h-7 w-32 animate-pulse bg-[var(--shell-muted)]"
                        style={{ borderRadius: 'var(--shell-radius-sm)' }}
                    />
                </div>

                <div
                    className="size-9 shrink-0 animate-pulse bg-[var(--shell-muted)]"
                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                />
            </div>
        </div>
    );
}
