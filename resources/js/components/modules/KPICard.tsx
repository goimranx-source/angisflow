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
    /** The figure's recent shape, drawn along the card's lower edge. */
    spark?: number[] | null;
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
    spark,
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
            spark={spark}
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
    const block = { borderRadius: 'var(--shell-radius-sm)' };

    return (
        <div className="card overflow-hidden px-4 pb-0 pt-4">
            <div className="flex items-start gap-3">
                {/* A disc, because the card's is a disc. A rounded square here
                    would change shape the moment the data landed. */}
                <div className="size-9 shrink-0 animate-pulse rounded-full bg-[var(--shell-muted)]" />

                <div className="min-w-0 flex-1">
                    {/*
                      Measured off the real card rather than eyeballed.

                      The label's line box is 20px, the figure's is 30, and the
                      gap between them 2 — approximations put the skeleton 11px
                      short, which is a skeleton that causes the jump it exists
                      to prevent. The blocks are drawn narrower than the text
                      they stand in for, since a full-width grey bar reads as
                      content rather than as its absence.
                    */}
                    <div className="h-5 w-24 animate-pulse bg-[var(--shell-muted)]" style={block} />
                    <div
                        className="mt-0.5 h-[30px] w-28 animate-pulse bg-[var(--shell-muted)]"
                        style={block}
                    />
                </div>
            </div>

            {/*
              The line's space is held even though no line is drawn.

              Two of the four cards carry one, and a skeleton that leaves it out
              is a skeleton the wrong height for those two — the row would grow
              by twenty-eight pixels the moment the figures arrived, which is
              precisely the jump a skeleton exists to prevent.
            */}
            <div className="mt-3 h-7" aria-hidden="true" />
        </div>
    );
}
