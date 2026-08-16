import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type StatsCardProps = {
    /** Card label/title */
    label: string;
    /** Main value to display */
    value: string | number;
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
    icon,
    iconElement,
    delta,
    direction = 'flat',
    riseIsGood = true,
    trendLabel,
    className,
    onClick,
}: StatsCardProps) {
    // Determine if trend is positive based on direction and whether rise is good
    const isPositiveTrend =
        direction === 'flat' ? null : (direction === 'up') === riseIsGood;

    const Component = onClick ? 'button' : 'div';

    return (
        <Component
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'card p-5',
                onClick && 'transition-all hover:shadow-md',
                onClick && 'cursor-pointer',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="text-[0.8125rem] font-medium text-[var(--color-text-muted)]">
                    {label}
                </span>
                <span className="grid size-8 place-items-center rounded-[10px] bg-[var(--color-brand-subtle)] text-[var(--color-ink-soft)]">
                    {iconElement ? (
                        iconElement
                    ) : icon ? (
                        <Icon name={icon} size={16} />
                    ) : (
                        <Icon name="chart-bar" size={16} />
                    )}
                </span>
            </div>

            <p className="mt-3 font-[family-name:var(--font-heading)] text-2xl font-bold text-[var(--color-text-main)]">
                {value}
            </p>

            {delta !== null && delta !== undefined && (
                <div className="mt-1 flex items-center gap-1">
                    <p
                        className="flex items-center gap-1 text-xs font-medium"
                        style={{
                            color:
                                isPositiveTrend === null
                                    ? 'var(--color-text-muted)'
                                    : isPositiveTrend
                                      ? 'var(--color-success)'
                                      : 'var(--color-danger-text)',
                        }}
                    >
                        <Icon
                            name={direction === 'down' ? 'trend-down' : 'trend-up'}
                            size={13}
                            weight="bold"
                        />
                        {Math.abs(delta)}%
                    </p>
                    {trendLabel && (
                        <span className="text-xs text-[var(--color-text-muted)]">
                            {trendLabel}
                        </span>
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
