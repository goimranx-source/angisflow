import { useMemo } from 'react';

import { cn } from '@/lib/utils';

export type BarChartDataPoint = {
    /** Label for this data point */
    label: string;
    /** Value for this data point */
    value: number;
    /** Optional color override */
    color?: string;
};

export type BarChartProps = {
    /** Chart data */
    data: BarChartDataPoint[];
    /** Chart height in pixels */
    height?: number;
    /** Show values on bars */
    showValues?: boolean;
    /** Show Y-axis labels */
    showYAxis?: boolean;
    /** Show X-axis labels */
    showXAxis?: boolean;
    /** Show grid lines */
    showGrid?: boolean;
    /** Value formatter function */
    valueFormatter?: (value: number) => string;
    /** Bar color (CSS variable or color) */
    barColor?: string;
    /** Additional class */
    className?: string;
    /** Click handler */
    onBarClick?: (dataPoint: BarChartDataPoint, index: number) => void;
};

/**
 * Simple bar chart component using CSS and SVG.
 *
 * Features:
 * - Responsive width
 * - Configurable height
 * - Optional value labels
 * - Optional axes
 * - Optional grid lines
 * - Custom value formatting
 * - Click handling
 * - Accessible
 *
 * @example
 * ```tsx
 * <BarChart
 *   data={[
 *     { label: 'Jan', value: 4500 },
 *     { label: 'Feb', value: 5200 },
 *     { label: 'Mar', value: 4800 },
 *     { label: 'Apr', value: 6100 },
 *     { label: 'May', value: 5900 },
 *     { label: 'Jun', value: 7200 }
 *   ]}
 *   height={300}
 *   showValues
 *   showYAxis
 *   valueFormatter={(v) => `$${(v / 1000).toFixed(1)}k`}
 * />
 * ```
 */
export function BarChart({
    data,
    height = 300,
    showValues = false,
    showYAxis = true,
    showXAxis = true,
    showGrid = true,
    valueFormatter = (v) => v.toString(),
    barColor = 'var(--color-brand)',
    className,
    onBarClick,
}: BarChartProps) {
    const { maxValue, yAxisLabels } = useMemo(() => {
        const max = Math.max(...data.map((d) => d.value));
        const roundedMax = Math.ceil(max * 1.1); // Add 10% headroom

        // Generate 5 evenly spaced Y-axis labels
        const labels = Array.from({ length: 5 }, (_, i) => {
            const value = roundedMax - (roundedMax / 4) * i;
            return Math.round(value);
        });

        return { maxValue: roundedMax, yAxisLabels: labels };
    }, [data]);

    if (data.length === 0) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center text-sm text-[var(--color-text-muted)]',
                    className,
                )}
                style={{ height: `${height}px` }}
            >
                No data available
            </div>
        );
    }

    const barWidth = showYAxis ? 'calc((100% - 60px) / ' + data.length + ')' : `calc(100% / ${data.length})`;
    const chartHeight = showXAxis ? height - 30 : height;

    return (
        <div className={cn('relative w-full', className)} style={{ height: `${height}px` }}>
            {/* Y-axis */}
            {showYAxis && (
                <div
                    className="absolute left-0 top-0 flex flex-col justify-between text-xs text-[var(--color-text-muted)]"
                    style={{ height: `${chartHeight}px`, width: '50px' }}
                >
                    {yAxisLabels.map((label, i) => (
                        <div key={i} className="text-right pr-2">
                            {valueFormatter(label)}
                        </div>
                    ))}
                </div>
            )}

            {/* Chart area */}
            <div
                className="relative"
                style={{
                    marginLeft: showYAxis ? '60px' : '0',
                    height: `${chartHeight}px`,
                }}
            >
                {/* Grid lines */}
                {showGrid && (
                    <div className="absolute inset-0 flex flex-col justify-between">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <div
                                key={i}
                                className="border-t border-[var(--color-border-light)]"
                            />
                        ))}
                    </div>
                )}

                {/* Bars */}
                <div className="relative flex h-full items-end justify-around gap-2 px-2">
                    {data.map((point, index) => {
                        const barHeight = maxValue > 0 ? (point.value / maxValue) * 100 : 0;
                        const color = point.color || barColor;

                        return (
                            <div
                                key={point.label}
                                className="relative flex flex-col items-center"
                                style={{ width: barWidth }}
                            >
                                {/* Value label */}
                                {showValues && point.value > 0 && (
                                    <div className="mb-1 text-xs font-medium text-[var(--color-text-main)]">
                                        {valueFormatter(point.value)}
                                    </div>
                                )}

                                {/* Bar */}
                                <div
                                    className={cn(
                                        'w-full rounded-t transition-all',
                                        onBarClick && 'cursor-pointer hover:opacity-80',
                                    )}
                                    style={{
                                        height: `${barHeight}%`,
                                        backgroundColor: color,
                                        minHeight: barHeight > 0 ? '2px' : '0',
                                    }}
                                    onClick={() => onBarClick?.(point, index)}
                                    role={onBarClick ? 'button' : undefined}
                                    tabIndex={onBarClick ? 0 : undefined}
                                    onKeyDown={(e) => {
                                        if (onBarClick && (e.key === 'Enter' || e.key === ' ')) {
                                            e.preventDefault();
                                            onBarClick(point, index);
                                        }
                                    }}
                                    aria-label={`${point.label}: ${valueFormatter(point.value)}`}
                                />
                            </div>
                        );
                    })}
                </div>

                {/* X-axis labels */}
                {showXAxis && (
                    <div className="absolute -bottom-[30px] left-0 right-0 flex items-center justify-around px-2">
                        {data.map((point) => (
                            <div
                                key={point.label}
                                className="text-center text-xs text-[var(--color-text-muted)]"
                                style={{ width: barWidth }}
                            >
                                <div className="truncate">{point.label}</div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
