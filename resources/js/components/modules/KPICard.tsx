import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type KPICardProps = {
    /** Metric label */
    label: string;
    /** Primary value */
    value: string | number;
    /** Icon name */
    icon: string;
    /** Change percentage (optional) */
    delta?: number;
    /** Whether delta direction is good (up = good) */
    deltaIsGood?: boolean;
    /** Comparison text */
    comparisonText?: string;
    /** Trend direction */
    trend?: 'up' | 'down' | 'flat';
    /** Additional info text */
    info?: string;
    /** Click handler */
    onClick?: () => void;
    /** Color variant for icon */
    variant?: 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';
};

/**
 * KPI card component for displaying key metrics.
 * 
 * Features:
 * - Icon with colored background
 * - Large value display
 * - Optional delta with trend indicator
 * - Comparison text
 * - Optional click action
 * 
 * Example:
 * ```tsx
 * <KPICard
 *   label="Total Revenue"
 *   value="$45,231.89"
 *   icon="currency-dollar"
 *   delta={20.1}
 *   deltaIsGood={true}
 *   comparisonText="vs last month"
 *   variant="success"
 * />
 * ```
 */
export function KPICard({
    label,
    value,
    icon,
    delta,
    deltaIsGood = true,
    comparisonText,
    trend,
    info,
    onClick,
    variant = 'brand',
}: KPICardProps) {
    const variantClasses = {
        brand: 'bg-[var(--color-brand)]/10 text-[var(--color-brand)]',
        success: 'bg-[var(--color-success)]/10 text-[var(--color-success)]',
        warning: 'bg-[var(--color-warning)]/10 text-[var(--color-warning)]',
        danger: 'bg-[var(--color-danger)]/10 text-[var(--color-danger)]',
        info: 'bg-blue-500/10 text-blue-600',
        neutral: 'bg-gray-500/10 text-gray-600',
    };

    // Determine if delta is good or bad
    const determinedTrend = trend ?? (delta === undefined ? 'flat' : delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat');
    const isGood = determinedTrend === 'flat' ? null : (determinedTrend === 'up') === deltaIsGood;

    return (
        <div
            className={cn(
                'card p-6 transition-shadow',
                onClick && 'cursor-pointer hover:shadow-md',
            )}
            onClick={onClick}
        >
            {/* Label and Icon */}
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-[var(--color-text-muted)]">
                    {label}
                </span>
                <div
                    className={cn(
                        'flex size-10 items-center justify-center',
                        variantClasses[variant],
                    )}
                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                >
                    <Icon name={icon} size={20} />
                </div>
            </div>

            {/* Large Value */}
            <div className="mt-4">
                <p className="font-[family-name:var(--font-heading)] text-3xl font-bold text-[var(--color-text-main)]">
                    {value}
                </p>

                {/* Delta and Comparison */}
                {(delta !== undefined || comparisonText) && (
                    <div className="mt-2 flex items-center gap-2">
                        {delta !== undefined && (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-0.5 px-1.5 py-0.5 text-xs font-semibold',
                                    isGood === null && 'bg-gray-100 text-gray-700',
                                    isGood === true && 'bg-[var(--color-success-subtle)] text-[var(--color-success)]',
                                    isGood === false && 'bg-[var(--color-danger-subtle)] text-[var(--color-danger)]',
                                )}
                                style={{ borderRadius: 'var(--shell-radius-sm)' }}
                            >
                                {determinedTrend !== 'flat' && (
                                    <Icon
                                        name={determinedTrend === 'down' ? 'arrow-down' : 'arrow-up'}
                                        size={12}
                                        weight="bold"
                                    />
                                )}
                                {Math.abs(delta)}%
                            </span>
                        )}
                        {comparisonText && (
                            <span className="text-xs text-[var(--color-text-muted)]">
                                {comparisonText}
                            </span>
                        )}
                    </div>
                )}

                {/* Additional Info */}
                {info && (
                    <p className="mt-2 text-xs text-[var(--color-text-muted)]">
                        {info}
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * Skeleton loader for KPI card.
 */
export function KPICardSkeleton() {
    return (
        <div className="card p-6">
            <div className="flex items-center justify-between">
                <div className="h-4 w-24 animate-pulse bg-gray-200" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                <div className="size-10 animate-pulse bg-gray-200" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
            </div>
            <div className="mt-4">
                <div className="h-9 w-32 animate-pulse bg-gray-200" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                <div className="mt-2 h-4 w-20 animate-pulse bg-gray-200" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
            </div>
        </div>
    );
}
