import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { InfoHint } from '@/components/ui/InfoHint';
import { cn } from '@/lib/utils';

type StatsCardProps = {
    /** Card label/title */
    label: string;
    /** Main value to display. Keep it short — see `valueTitle`. */
    value: string | number;
    /**
     * The unabbreviated figure, revealed on hover.
     *
     * A headline is compacted to "RM 28.9K" so a row of cards stays scannable
     * and a figure that grows into the millions does not reflow the layout.
     * The exact amount is still the thing somebody occasionally needs, so it
     * is a hover away rather than gone.
     */
    valueTitle?: string;
    /** Icon name (Phosphor icon) */
    icon?: string;
    /** Custom icon element */
    iconElement?: ReactNode;
    /** Delta/change percentage */
    delta?: number | null;
    /** Direction of change */
    direction?: 'up' | 'down' | 'flat';
    /** Whether increase is good (for coloring) */
    riseIsGood?: boolean;
    /** Trend label/description */
    trendLabel?: string;
    /**
     * What the delta was measured against — "Compared with 1 Jul – 31 Jul
     * 2026." Behind an info icon beside the title rather than printed under
     * every card: a reader who already knows what a month-over-month
     * comparison means does not need it stated four times on one screen, and
     * the one who is unsure can still ask.
     */
    comparisonHint?: ReactNode;
    /**
     * The colour of the icon tile.
     *
     * The semantic five rather than a decorative palette, so a card's colour
     * says something: revenue is brand, money owed is a warning, money overdue
     * is a danger. A row of five is then scannable by shape as well as by
     * reading every label.
     */
    accent?: 'brand' | 'success' | 'warning' | 'danger' | 'info';
    /**
     * The figure's recent shape, drawn along the bottom of the card.
     *
     * ── Why a shape and not another number ───────────────────────────────
     *
     * A headline says where a business is; it says nothing about how it got
     * there, and the two readings can be opposite. Revenue of £75,000 is good
     * news climbing and bad news falling, and a percentage delta compresses the
     * whole month into one figure that hides which.
     *
     * Deliberately unlabelled — no axes, no ticks, no numbers. It is not a
     * chart to read values off; it is the difference between rising and
     * falling, at a glance, which is the one question a headline cannot answer.
     *
     * Nothing is drawn when there is no series or fewer than two points, since
     * a line through one point is a claim about a trend nobody has.
     */
    spark?: number[] | null;
    /** Additional class */
    className?: string;
    /** Click handler */
    onClick?: () => void;
};

/**
 * A figure's recent shape, as a filled area under a smooth line.
 *
 * ── Why it is drawn here rather than with a charting library ─────────────────
 *
 * Because it is twenty numbers and no axes. A chart library brings a
 * coordinate system, a legend, a tooltip layer and a resize observer, all of
 * which exist to answer questions this deliberately refuses to answer — it has
 * no scale to read against and is not meant to.
 *
 * ── The curve ────────────────────────────────────────────────────────────────
 *
 * Points are joined with a cubic through the midpoints between them, which
 * gives a smooth line that cannot overshoot: a monotone series stays monotone,
 * so a figure that only rose is never drawn dipping. Catmull-Rom would be
 * smoother and does overshoot, and inventing a dip in someone's revenue to
 * make a curve prettier is not a trade worth making.
 *
 * ── The scale ────────────────────────────────────────────────────────────────
 *
 * Fitted to the series rather than to zero. Twenty days of revenue between
 * £74,000 and £76,000 drawn from zero is a flat line, which is true of the
 * absolute figures and useless as a picture of the month.
 */
/**
 * The series under the figure, as bars.
 *
 * ── Why bars rather than the line it was ─────────────────────────────────────
 *
 * A line says "this value moved from here to there", and it is the right
 * picture for something continuous. What these cards carry is not continuous:
 * it is a count per day. There is no value between Tuesday and Wednesday, and a
 * curve drawn through them invents one — smoothed, it invents a Tuesday
 * afternoon peak that never happened.
 *
 * Bars say the true thing: fourteen days, each its own height, nothing claimed
 * about the gaps. They also read at this size, where a 1.5px line across 28px
 * of card is closer to a texture than a chart.
 *
 * ── The floor ────────────────────────────────────────────────────────────────
 *
 * Bars are measured from zero, not from the smallest value in the series.
 * Starting at the minimum is what makes a sparkline flatter a quiet week into a
 * dramatic one — the shortest bar vanishes and the tallest fills the card,
 * whatever the actual difference was. A day with two orders and a day with
 * three should look nearly the same, because they are.
 */
function Spark({ points }: { points: number[] }) {
    // One point is not a trend, and no points is not a picture.
    if (points.length < 2) {
        return null;
    }

    const W = 100;
    const H = 28;

    const max = Math.max(...points, 0);

    /*
     * Share the width evenly, and spend a fifth of each share on the gap.
     *
     * As a fraction rather than a pixel count, because the viewBox is stretched
     * to the card's width by preserveAspectRatio="none" — a gap set in viewBox
     * units would come out wider on a wide card and thinner on a narrow one,
     * and a row of four cards would have four different bar spacings.
     */
    const slot = W / points.length;
    const bar = slot * 0.62;

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            preserveAspectRatio="none"
            className="mt-3 block h-7 w-full"
            aria-hidden="true"
        >
            {points.map((value, i) => {
                /*
                 * A floor of one unit, so a day with nothing in it is still a
                 * mark on the axis rather than a hole in the row. A gap reads
                 * as missing data; a stub reads as a quiet day, which is what
                 * it is.
                 */
                const height = max === 0 ? 1 : Math.max(1, (value / max) * (H - 1));

                return (
                    <rect
                        key={i}
                        x={i * slot + (slot - bar) / 2}
                        y={H - height}
                        width={bar}
                        height={height}
                        rx="0.6"
                        fill="currentColor"
                        /* The tallest bars carry the colour; the quiet ones
                           recede, so the shape is legible before the values
                           are read. */
                        opacity={max === 0 ? 0.25 : 0.35 + (value / max) * 0.65}
                    />
                );
            })}
        </svg>
    );
}

/**
 * Statistics card component for displaying KPIs.
 *
 * Features:
 * - Main value display with formatting
 * - Icon badge
 * - Delta/change indicator
 * - Smart trend coloring (rise good/bad)
 * - Optional click action
 * - Responsive
 * - Accessible
 *
 * @example
 * ```tsx
 * <StatsCard
 *   label="Total Revenue"
 *   value="$48,500"
 *   icon="currency-dollar"
 *   delta={12.5}
 *   direction="up"
 *   riseIsGood={true}
 *   trendLabel="vs last month"
 * />
 *
 * <StatsCard
 *   label="Active Users"
 *   value={1248}
 *   icon="users"
 *   delta={-3.2}
 *   direction="down"
 *   riseIsGood={true}
 * />
 * ```
 */
export function StatsCard({
    label,
    value,
    valueTitle,
    icon,
    iconElement,
    delta,
    direction = 'flat',
    riseIsGood = true,
    trendLabel,
    comparisonHint,
    accent = 'brand',
    spark,
    className,
    onClick,
}: StatsCardProps) {
    // A rise in expenses is not good news, and colouring it green because the
    // arrow points up is how a dashboard teaches people to stop reading it.
    const isPositiveTrend =
        direction === 'flat' ? null : (direction === 'up') === riseIsGood;

    const Component = onClick ? 'button' : 'div';

    return (
        <Component
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'card overflow-hidden px-4 pb-0 pt-4 text-left',
                onClick && 'cursor-pointer transition-colors hover:bg-[var(--shell-hover)]',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                {/*
                  The mark first, then what it counts.

                  Read left to right it says what kind of number this is before
                  saying the number, which is the order somebody scanning four
                  cards actually wants — the colour and the shape identify the
                  card, and the figure is what they stopped for.
                */}
                <span className={cn('stat-tile shrink-0', accent !== 'brand' && `is-${accent}`)}>
                    {iconElement ?? <Icon name={icon ?? 'chart-bar'} size={17} />}
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1">
                        <p className="truncate text-[0.8125rem] font-medium text-[var(--color-text-muted)]">
                            {label}
                        </p>
                        {comparisonHint && (
                            <InfoHint label={`How ${label} is compared`}>{comparisonHint}</InfoHint>
                        )}
                    </div>

                    <p
                        className="mt-0.5 truncate font-[family-name:var(--font-heading)] text-[1.5rem] leading-tight font-bold text-[var(--color-text-main)] [font-variant-numeric:tabular-nums]"
                        title={valueTitle}
                    >
                        {value}
                    </p>
                </div>
            </div>

            {/*
              Flush to the card's lower edge, in the accent's own colour —
              `currentColor` on the wrapper, so the line and its wash both
              follow whatever the tile above is using and nothing has to be
              passed down twice.
            */}
            {spark && spark.length > 1 && (
                <div
                    className={cn(
                        'stat-spark',
                        accent === 'brand' && 'text-[var(--color-brand)]',
                        accent === 'success' && 'text-[var(--color-success)]',
                        accent === 'warning' && 'text-[var(--color-warning)]',
                        accent === 'danger' && 'text-[var(--color-danger)]',
                        accent === 'info' && 'text-[var(--color-info)]',
                    )}
                >
                    <Spark points={spark} />
                </div>
            )}

            {/*
                The trend row is drawn whenever the caller says this figure
                has one — `delta={null}` still means "compared, but the
                change cannot be expressed", which is what a period starting
                from zero gives you. Only `undefined` (the prop left off)
                removes the row.

                Rendering it unconditionally is what keeps a row of cards the
                same height, and what stops the divider appearing on some
                cards and not others depending on whether last month happened
                to be a trading month.
            */}
            {delta !== undefined && (
                <div className="mt-3 flex items-center gap-1.5 border-t border-[var(--shell-border)] pt-2.5">
                    {delta === null ? (
                        <span className="text-xs text-[var(--color-text-muted)]">
                            No change to compare
                        </span>
                    ) : (
                        <>
                            <span
                                className={cn(
                                    'stat-delta',
                                    isPositiveTrend === true && 'is-good',
                                    isPositiveTrend === false && 'is-bad',
                                )}
                            >
                                <Icon
                                    name={
                                        direction === 'down'
                                            ? 'arrow-down'
                                            : direction === 'up'
                                              ? 'arrow-up'
                                              : 'minus'
                                    }
                                    size={11}
                                    weight="bold"
                                />
                                {Math.abs(delta)}%
                            </span>

                            {trendLabel && (
                                <span className="truncate text-xs text-[var(--color-text-muted)]">
                                    {trendLabel}
                                </span>
                            )}
                        </>
                    )}
                </div>
            )}
        </Component>
    );
}

type StatsGridProps = {
    /** Stats cards to display */
    children: ReactNode;
    /** Number of columns on different screen sizes */
    columns?: {
        sm?: 1 | 2;
        md?: 2 | 3 | 4;
        lg?: 2 | 3 | 4;
        xl?: 2 | 3 | 4 | 5 | 6;
    };
    /** Additional class */
    className?: string;
};

/**
 * Grid container for stats cards.
 *
 * @example
 * ```tsx
 * <StatsGrid columns={{ sm: 2, lg: 4 }}>
 *   <StatsCard label="Revenue" value="$48,500" icon="currency-dollar" />
 *   <StatsCard label="Orders" value={248} icon="shopping-cart" />
 *   <StatsCard label="Customers" value={1248} icon="users" />
 *   <StatsCard label="Conversion" value="3.2%" icon="trending-up" />
 * </StatsGrid>
 * ```
 */
export function StatsGrid({
    children,
    columns = { sm: 2, xl: 4 },
    className,
}: StatsGridProps) {
    return (
        <div
            className={cn(
                'grid gap-4',
                columns.sm === 1 && 'grid-cols-1',
                columns.sm === 2 && 'sm:grid-cols-2',
                columns.md === 2 && 'md:grid-cols-2',
                columns.md === 3 && 'md:grid-cols-3',
                columns.md === 4 && 'md:grid-cols-4',
                columns.lg === 2 && 'lg:grid-cols-2',
                columns.lg === 3 && 'lg:grid-cols-3',
                columns.lg === 4 && 'lg:grid-cols-4',
                columns.xl === 2 && 'xl:grid-cols-2',
                columns.xl === 3 && 'xl:grid-cols-3',
                columns.xl === 4 && 'xl:grid-cols-4',
                columns.xl === 5 && 'xl:grid-cols-5',
                columns.xl === 6 && 'xl:grid-cols-6',
                className,
            )}
        >
            {children}
        </div>
    );
}
