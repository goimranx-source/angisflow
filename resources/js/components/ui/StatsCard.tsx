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
    /** Additional class */
    className?: string;
    /** Click handler */
    onClick?: () => void;
};

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
                'card p-4 text-left',
                onClick && 'cursor-pointer transition-colors hover:bg-[var(--shell-hover)]',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-1">
                        <p className="truncate text-[0.8125rem] font-medium text-[var(--color-text-muted)]">
                            {label}
                        </p>
                        {comparisonHint && (
                            <InfoHint label={`How ${label} is compared`}>{comparisonHint}</InfoHint>
                        )}
                    </div>

                    <p
                        className="mt-1.5 truncate font-[family-name:var(--font-heading)] text-[1.75rem] leading-tight font-bold text-[var(--color-text-main)] [font-variant-numeric:tabular-nums]"
                        title={valueTitle}
                    >
                        {value}
                    </p>
                </div>

                <span className={cn('stat-tile', accent !== 'brand' && `is-${accent}`)}>
                    {iconElement ?? <Icon name={icon ?? 'chart-bar'} size={17} />}
                </span>
            </div>

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
