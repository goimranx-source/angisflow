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
 * The series under the figure: a curve, with the ground beneath it shaded.
 *
 * ── What it is for ───────────────────────────────────────────────────────────
 *
 * A shape, not a set of readings. There is no axis, no scale and no labels, so
 * nothing here can be measured — and nothing here should invite measuring. What
 * it answers is "busy lately, or quiet?", at a glance, without the reader
 * having to decide to look.
 *
 * The fill is most of why it works. A bare line of this weight reads as a
 * border or a divider; filled, it reads as a quantity, and the eye takes the
 * silhouette in without tracing the line.
 *
 * ── The curve cannot overshoot ───────────────────────────────────────────────
 *
 * Each segment is a cubic whose control points sit on the vertical midline
 * between its two ends, at the height of the end it belongs to. That is what
 * keeps the curve inside the range of the values it joins: a spline fitted
 * through the points would bulge past them, inventing a peak above the busiest
 * day and a dip below the quietest, on a picture with no axis to check it
 * against.
 *
 * ── Measured from zero ───────────────────────────────────────────────────────
 *
 * Not from the smallest value in the series. Starting at the minimum is what
 * turns a quiet month into a dramatic one — the lowest point pinned to the
 * floor and the highest to the ceiling, whatever the real difference was. Two
 * orders and three should look nearly the same, because they are.
 */
function Spark({ points, accent }: { points: number[]; accent: string }) {
    // One point is not a trend, and no points is not a picture.
    if (points.length < 2) {
        return null;
    }

    const W = 100;
    const H = 32;

    // Room above for the stroke's own width, so a peak is not shaved off by
    // the edge of the box.
    const TOP = 3;

    /*
     * And room below, for the same reason at the other end.
     *
     * A run of zero days sits the curve exactly on y = H, where half the
     * stroke falls outside the viewBox and is clipped. On a month with orders
     * in the last week and nothing before it, that read as a line that simply
     * began two thirds of the way across -- as though the card only had a
     * week's data rather than three weeks of nothing, which is a different
     * and much less useful statement.
     */
    const FLOOR = 2;

    const max = Math.max(...points, 0);

    const at = (i: number): [number, number] => {
        // Indexed access is checked: the loop never leaves the array, but the
        // compiler cannot see that, and a silent NaN in a path draws nothing
        // at all rather than complaining.
        const value = points[i] ?? 0;
        const height = max === 0 ? 0 : (value / max) * (H - TOP - FLOOR);

        return [(i / (points.length - 1)) * W, H - FLOOR - height];
    };

    let line = '';

    for (let i = 0; i < points.length; i++) {
        const [x, y] = at(i);

        if (i === 0) {
            line += `M ${x} ${y}`;

            continue;
        }

        const [px, py] = at(i - 1);
        const mid = (px + x) / 2;

        line += ` C ${mid} ${py}, ${mid} ${y}, ${x} ${y}`;
    }

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            preserveAspectRatio="none"
            className="mt-3 block h-9 w-full"
            aria-hidden="true"
        >
            <defs>
                {/*
                  Keyed by the accent so two cards of different colours do not
                  share one gradient — SVG ids are global to the document, and
                  the second card to mount would otherwise paint itself with
                  the first one's colour.
                */}
                <linearGradient id={`spark-${accent}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>

            <path d={`${line} L ${W} ${H} L 0 ${H} Z`} fill={`url(#spark-${accent})`} />

            <path
                d={line}
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                /* Kept at 2 real pixels however far the box is stretched.
                   Without this the stroke thins as the card widens, and four
                   cards in a row have four different line weights. */
                vectorEffect="non-scaling-stroke"
            />
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
    /*
     * Which way the arrow points.
     *
     * Taken from the number itself when the caller did not say, because they
     * almost never do -- the default was 'flat', so every card passing a delta
     * and nothing else drew a minus sign beside a figure that had plainly
     * moved. An explicit `direction` still wins, for the cases where the sign
     * of the delta is not the direction of the news.
     */
    const heading: 'up' | 'down' | 'flat' =
        direction !== 'flat' || delta == null || delta === 0
            ? direction
            : delta > 0
              ? 'up'
              : 'down';

    // A rise in expenses is not good news, and colouring it green because the
    // arrow points up is how a dashboard teaches people to stop reading it.
    const isPositiveTrend =
        heading === 'flat' ? null : (heading === 'up') === riseIsGood;

    /*
     * Four characters, whatever happened.
     *
     * A real figure can be 4910.2%, and a badge that wide takes the room the
     * value needs -- "৳75.8K" was truncating to "৳." beside one. Past a
     * thousand percent the exact number has stopped being information anybody
     * acts on; that it multiplied is the whole of the message, and the precise
     * figure is on the hover.
     */
    const deltaShown =
        delta == null ? null : Math.abs(delta) >= 1000 ? '>999' : String(Math.abs(delta));

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

                    {/*
                      The figure and its change on one line.

                      The change used to sit in a bordered row of its own under
                      the sparkline, which put three horizontal bands on a card
                      whose whole job is to be read at a glance -- and separated
                      the number from the one piece of context that makes it
                      mean anything. "22" is a fact; "22, up a fifth" is news.

                      `items-baseline`, so the small percentage sits on the
                      figure's baseline rather than floating at the middle of a
                      24px number.
                    */}
                    {/*
                      Wrapping, so the badge never crushes the figure.

                      With the change held at its natural width and the figure
                      told to truncate, a narrow card gave the badge what it
                      asked for and left "22" as "2…" -- the one thing on the
                      card nobody can do without. Allowed to wrap, the change
                      drops to its own line when the two will not fit and the
                      figure keeps the width.
                    */}
                    <div className="mt-0.5 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <p
                            className="truncate font-[family-name:var(--font-heading)] text-[1.5rem] leading-tight font-bold text-[var(--color-text-main)] [font-variant-numeric:tabular-nums]"
                            title={valueTitle}
                        >
                            {value}
                        </p>

                        {delta !== undefined && delta !== null && (
                            <span
                                className={cn(
                                    'stat-delta shrink-0',
                                    isPositiveTrend === true && 'is-good',
                                    isPositiveTrend === false && 'is-bad',
                                )}
                                title={
                                    trendLabel ??
                                    (delta == null ? undefined : `${delta}% against the week before`)
                                }
                            >
                                <Icon
                                    name={
                                        heading === 'down'
                                            ? 'arrow-down'
                                            : heading === 'up'
                                              ? 'arrow-up'
                                              : 'minus'
                                    }
                                    size={11}
                                    weight="bold"
                                />
                                {deltaShown}%
                            </span>
                        )}
                    </div>
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
                    <Spark points={spark} accent={accent} />
                </div>
            )}

            {/*
              Only when the comparison could not be made.

              `delta={null}` means "compared, and the change cannot be
              expressed" -- a period starting from zero. That is worth a line,
              because a card with no percentage on it otherwise looks like a
              card nobody wired a comparison to.
            */}
            {delta === null && (
                <p className="mt-2 text-xs text-[var(--color-text-muted)]">
                    No change to compare
                </p>
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
