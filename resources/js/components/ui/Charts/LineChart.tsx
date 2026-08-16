import { useMemo } from 'react';

import { cn } from '@/lib/utils';

export type LineChartDataPoint = {
    /** Label for this data point */
    label: string;
    /** Value for this data point */
    value: number;
};

export type LineChartSeries = {
    /** Series name */
    name: string;
    /** Series data points */
    data: LineChartDataPoint[];
    /** Line color */
    color?: string;
    /** Show data points */
    showPoints?: boolean;
};

export type LineChartProps = {
    /** Chart series (supports multiple lines) */
    series: LineChartSeries[];
    /** Chart height in pixels */
    height?: number;
    /** Show Y-axis labels */
    showYAxis?: boolean;
    /** Show X-axis labels */
    showXAxis?: boolean;
    /** Show grid lines */
    showGrid?: boolean;
    /** Show legend */
    showLegend?: boolean;
    /** Value formatter function */
    valueFormatter?: (value: number) => string;
    /** Curve the line (smooth) */
    curved?: boolean;
    /** Fill area under line */
    filled?: boolean;
    /** Additional class */
    className?: string;
};

/**
 * Line chart component using SVG.
 *
 * Features:
 * - Multiple series support
 * - Responsive width
 * - Configurable height
 * - Optional axes and grid
 * - Optional data points
 * - Smooth curves
 * - Optional area fill
 * - Legend
 * - Accessible
 *
 * @example
 * ```tsx
 * <LineChart
 *   series={[
 *     {
 *       name: 'Revenue',
 *       data: [
 *         { label: 'Jan', value: 4500 },
 *         { label: 'Feb', value: 5200 },
 *         { label: 'Mar', value: 4800 },
 *         { label: 'Apr', value: 6100 }
 *       ],
 *       color: 'var(--color-brand)',
 *       showPoints: true
 *     },
 *     {
 *       name: 'Expenses',
 *       data: [
 *         { label: 'Jan', value: 3200 },
 *         { label: 'Feb', value: 3800 },
 *         { label: 'Mar', value: 3400 },
 *         { label: 'Apr', value: 4100 }
 *       ],
 *       color: '#ef4444',
 *       showPoints: true
 *     }
 *   ]}
 *   height={300}
 *   showYAxis
 *   showLegend
 *   curved
 *   valueFormatter={(v) => `$${(v / 1000).toFixed(1)}k`}
 * />
 * ```
 */
export function LineChart({
    series,
    height = 300,
    showYAxis = true,
    showXAxis = true,
    showGrid = true,
    showLegend = true,
    valueFormatter = (v) => v.toString(),
    curved = false,
    filled = false,
    className,
}: LineChartProps) {
    const { maxValue, minValue, yAxisLabels, allLabels } = useMemo(() => {
        const allValues = series.flatMap((s) => s.data.map((d) => d.value));
        const max = Math.max(...allValues);
        const min = Math.min(...allValues, 0);

        const roundedMax = Math.ceil(max * 1.1);
        const roundedMin = Math.floor(min);

        const range = roundedMax - roundedMin;
        const labels = Array.from({ length: 5 }, (_, i) => {
            const value = roundedMax - (range / 4) * i;
            return Math.round(value);
        });

        // Get all unique labels from all series
        const labelsSet = new Set<string>();
        series.forEach((s) => s.data.forEach((d) => labelsSet.add(d.label)));
        const uniqueLabels = Array.from(labelsSet);

        return {
            maxValue: roundedMax,
            minValue: roundedMin,
            yAxisLabels: labels,
            allLabels: uniqueLabels,
        };
    }, [series]);

    if (series.length === 0 || series.every((s) => s.data.length === 0)) {
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

    const legendHeight = showLegend ? 40 : 0;
    const chartHeight = showXAxis ? height - 30 - legendHeight : height - legendHeight;
    const chartWidth = 100; // Use percentage
    const leftMargin = showYAxis ? 60 : 0;

    const defaultColors = [
        'var(--color-brand)',
        '#ef4444',
        '#10b981',
        '#f59e0b',
        '#8b5cf6',
        '#06b6d4',
    ];

    // Create SVG path for line
    const createPath = (seriesData: LineChartDataPoint[]) => {
        if (seriesData.length === 0) return '';

        const points = seriesData.map((point, index) => {
            const x = (index / (allLabels.length - 1 || 1)) * chartWidth;
            const y =
                ((maxValue - point.value) / (maxValue - minValue || 1)) *
                chartHeight;
            return { x, y };
        });

        if (curved && points.length > 1) {
            // Create smooth curve using quadratic bezier
            const firstPoint = points[0];
            if (!firstPoint) return '';
            
            let path = `M ${firstPoint.x} ${firstPoint.y}`;
            for (let i = 0; i < points.length - 1; i++) {
                const current = points[i];
                const next = points[i + 1];
                if (!current || !next) continue;
                
                const midX = (current.x + next.x) / 2;
                path += ` Q ${current.x} ${current.y} ${midX} ${(current.y + next.y) / 2}`;
                if (i === points.length - 2) {
                    path += ` Q ${next.x} ${next.y} ${next.x} ${next.y}`;
                }
            }
            return path;
        } else {
            // Straight lines
            return points.map((p, i) => (i === 0 ? `M ${p.x} ${p.y}` : `L ${p.x} ${p.y}`)).join(' ');
        }
    };

    return (
        <div className={cn('relative w-full', className)} style={{ height: `${height}px` }}>
            {/* Legend */}
            {showLegend && (
                <div className="mb-4 flex flex-wrap items-center gap-4">
                    {series.map((s, index) => (
                        <div key={s.name} className="flex items-center gap-2">
                            <div
                                className="h-0.5 w-6 rounded"
                                style={{
                                    backgroundColor: s.color || defaultColors[index % defaultColors.length],
                                }}
                            />
                            <span className="text-xs text-[var(--color-text-muted)]">
                                {s.name}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {/* Y-axis */}
            {showYAxis && (
                <div
                    className="absolute left-0 flex flex-col justify-between text-xs text-[var(--color-text-muted)]"
                    style={{
                        top: `${legendHeight}px`,
                        height: `${chartHeight}px`,
                        width: '50px',
                    }}
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
                    marginLeft: `${leftMargin}px`,
                    marginTop: `${legendHeight}px`,
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

                {/* SVG for lines */}
                <svg
                    className="absolute inset-0"
                    viewBox={`0 0 ${chartWidth} ${chartHeight}`}
                    preserveAspectRatio="none"
                >
                    {series.map((s, seriesIndex) => {
                        const color = s.color || defaultColors[seriesIndex % defaultColors.length];
                        const path = createPath(s.data);

                        return (
                            <g key={s.name}>
                                {/* Fill area */}
                                {filled && (
                                    <path
                                        d={`${path} L ${chartWidth} ${chartHeight} L 0 ${chartHeight} Z`}
                                        fill={color}
                                        fillOpacity="0.1"
                                    />
                                )}

                                {/* Line */}
                                <path
                                    d={path}
                                    fill="none"
                                    stroke={color}
                                    strokeWidth="2"
                                    vectorEffect="non-scaling-stroke"
                                />

                                {/* Data points */}
                                {s.showPoints &&
                                    s.data.map((point, index) => {
                                        const x = (index / (allLabels.length - 1 || 1)) * chartWidth;
                                        const y =
                                            ((maxValue - point.value) / (maxValue - minValue || 1)) *
                                            chartHeight;
                                        return (
                                            <circle
                                                key={`${s.name}-${index}`}
                                                cx={x}
                                                cy={y}
                                                r="3"
                                                fill={color}
                                                vectorEffect="non-scaling-stroke"
                                            >
                                                <title>
                                                    {point.label}: {valueFormatter(point.value)}
                                                </title>
                                            </circle>
                                        );
                                    })}
                            </g>
                        );
                    })}
                </svg>

                {/* X-axis labels */}
                {showXAxis && (
                    <div className="absolute -bottom-[30px] left-0 right-0 flex items-center justify-between">
                        {allLabels.map((label, index) => (
                            <div
                                key={label}
                                className="text-center text-xs text-[var(--color-text-muted)]"
                                style={{
                                    position: 'absolute',
                                    left: `${(index / (allLabels.length - 1 || 1)) * 100}%`,
                                    transform: 'translateX(-50%)',
                                }}
                            >
                                {label}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
