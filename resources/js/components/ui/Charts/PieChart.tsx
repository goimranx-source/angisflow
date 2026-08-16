import { useMemo, useState } from 'react';

import { cn } from '@/lib/utils';

export type PieChartDataPoint = {
    /** Label for this segment */
    label: string;
    /** Value for this segment */
    value: number;
    /** Custom color */
    color?: string;
};

export type PieChartProps = {
    /** Chart data */
    data: PieChartDataPoint[];
    /** Chart size in pixels (width and height) */
    size?: number;
    /** Show legend */
    showLegend?: boolean;
    /** Show values in legend */
    showValues?: boolean;
    /** Show percentages in legend */
    showPercentages?: boolean;
    /** Value formatter function */
    valueFormatter?: (value: number) => string;
    /** Inner radius (0 for pie, >0 for donut) */
    innerRadius?: number;
    /** Show center label (for donut charts) */
    centerLabel?: string;
    /** Center value */
    centerValue?: string;
    /** Additional class */
    className?: string;
    /** Click handler */
    onSegmentClick?: (dataPoint: PieChartDataPoint, index: number) => void;
};

/**
 * Pie/Donut chart component using SVG.
 *
 * Features:
 * - Pie or donut style
 * - Responsive
 * - Legend with values/percentages
 * - Custom colors
 * - Value formatting
 * - Click handling
 * - Hover effects
 * - Center label (donut mode)
 * - Accessible
 *
 * @example
 * ```tsx
 * // Pie chart
 * <PieChart
 *   data={[
 *     { label: 'Electronics', value: 45000, color: '#3b82f6' },
 *     { label: 'Clothing', value: 32000, color: '#10b981' },
 *     { label: 'Food', value: 28000, color: '#f59e0b' },
 *     { label: 'Other', value: 15000, color: '#8b5cf6' }
 *   ]}
 *   size={200}
 *   showLegend
 *   showPercentages
 * />
 *
 * // Donut chart with center label
 * <PieChart
 *   data={[...]}
 *   size={200}
 *   innerRadius={60}
 *   centerLabel="Total Sales"
 *   centerValue="$120K"
 *   showLegend
 * />
 * ```
 */
export function PieChart({
    data,
    size = 200,
    showLegend = true,
    showValues = true,
    showPercentages = true,
    valueFormatter = (v) => v.toString(),
    innerRadius = 0,
    centerLabel,
    centerValue,
    className,
    onSegmentClick,
}: PieChartProps) {
    const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);

    const defaultColors = [
        '#3b82f6', // blue
        '#10b981', // green
        '#f59e0b', // amber
        '#8b5cf6', // purple
        '#ef4444', // red
        '#06b6d4', // cyan
        '#ec4899', // pink
        '#64748b', // slate
    ];

    const { segments } = useMemo(() => {
        const totalValue = data.reduce((sum, d) => sum + d.value, 0);

        let currentAngle = -90; // Start from top

        const segmentsData = data.map((point, index) => {
            const percentage = totalValue > 0 ? (point.value / totalValue) * 100 : 0;
            const angle = (percentage / 100) * 360;
            const startAngle = currentAngle;
            const endAngle = currentAngle + angle;

            currentAngle = endAngle;

            return {
                ...point,
                percentage,
                startAngle,
                endAngle,
                color: point.color || defaultColors[index % defaultColors.length],
            };
        });

        return { total: totalValue, segments: segmentsData };
    }, [data, defaultColors]);

    if (data.length === 0) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center text-sm text-[var(--color-text-muted)]',
                    className,
                )}
                style={{ height: `${size}px` }}
            >
                No data available
            </div>
        );
    }

    const radius = size / 2;
    const strokeWidth = radius - innerRadius;
    const normalizedRadius = radius - strokeWidth / 2;

    // Create path for donut segment
    const createArcPath = (startAngle: number, endAngle: number) => {
        const start = polarToCartesian(radius, radius, normalizedRadius, endAngle);
        const end = polarToCartesian(radius, radius, normalizedRadius, startAngle);
        const largeArcFlag = endAngle - startAngle <= 180 ? '0' : '1';

        if (innerRadius === 0) {
            // Pie chart
            return [
                `M ${radius} ${radius}`,
                `L ${start.x} ${start.y}`,
                `A ${normalizedRadius} ${normalizedRadius} 0 ${largeArcFlag} 0 ${end.x} ${end.y}`,
                'Z',
            ].join(' ');
        } else {
            // Donut chart
            const innerStart = polarToCartesian(radius, radius, innerRadius, endAngle);
            const innerEnd = polarToCartesian(radius, radius, innerRadius, startAngle);

            return [
                `M ${start.x} ${start.y}`,
                `A ${normalizedRadius} ${normalizedRadius} 0 ${largeArcFlag} 0 ${end.x} ${end.y}`,
                `L ${innerEnd.x} ${innerEnd.y}`,
                `A ${innerRadius} ${innerRadius} 0 ${largeArcFlag} 1 ${innerStart.x} ${innerStart.y}`,
                'Z',
            ].join(' ');
        }
    };

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            {/* Chart */}
            <div className="relative" style={{ width: `${size}px`, height: `${size}px` }}>
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    {segments.map((segment, index) => {
                        const isHovered = hoveredIndex === index;
                        const path = createArcPath(segment.startAngle, segment.endAngle);

                        return (
                            <path
                                key={segment.label}
                                d={path}
                                fill={segment.color}
                                className={cn(
                                    'transition-all',
                                    onSegmentClick && 'cursor-pointer',
                                )}
                                style={{
                                    opacity: isHovered ? 0.8 : 1,
                                    filter: isHovered ? 'brightness(1.1)' : 'none',
                                }}
                                onMouseEnter={() => setHoveredIndex(index)}
                                onMouseLeave={() => setHoveredIndex(null)}
                                onClick={() => onSegmentClick?.(segment, index)}
                                role={onSegmentClick ? 'button' : undefined}
                                tabIndex={onSegmentClick ? 0 : undefined}
                                onKeyDown={(e) => {
                                    if (onSegmentClick && (e.key === 'Enter' || e.key === ' ')) {
                                        e.preventDefault();
                                        onSegmentClick(segment, index);
                                    }
                                }}
                                aria-label={`${segment.label}: ${valueFormatter(segment.value)} (${segment.percentage.toFixed(1)}%)`}
                            >
                                <title>
                                    {segment.label}: {valueFormatter(segment.value)} (
                                    {segment.percentage.toFixed(1)}%)
                                </title>
                            </path>
                        );
                    })}
                </svg>

                {/* Center label for donut charts */}
                {innerRadius > 0 && (centerLabel || centerValue) && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                        {centerLabel && (
                            <div className="text-xs text-[var(--color-text-muted)]">
                                {centerLabel}
                            </div>
                        )}
                        {centerValue && (
                            <div className="mt-1 text-lg font-bold text-[var(--color-text-main)]">
                                {centerValue}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Legend */}
            {showLegend && (
                <div className="space-y-2">
                    {segments.map((segment, index) => {
                        const isHovered = hoveredIndex === index;

                        return (
                            <div
                                key={segment.label}
                                className={cn(
                                    'flex items-center justify-between gap-2 rounded px-2 py-1 transition-colors',
                                    isHovered && 'bg-[var(--color-surface)]',
                                    onSegmentClick && 'cursor-pointer',
                                )}
                                onMouseEnter={() => setHoveredIndex(index)}
                                onMouseLeave={() => setHoveredIndex(null)}
                                onClick={() => onSegmentClick?.(segment, index)}
                                role={onSegmentClick ? 'button' : undefined}
                                tabIndex={onSegmentClick ? 0 : undefined}
                                onKeyDown={(e) => {
                                    if (onSegmentClick && (e.key === 'Enter' || e.key === ' ')) {
                                        e.preventDefault();
                                        onSegmentClick(segment, index);
                                    }
                                }}
                            >
                                <div className="flex items-center gap-2 min-w-0">
                                    <div
                                        className="h-3 w-3 flex-shrink-0 rounded-sm"
                                        style={{ backgroundColor: segment.color }}
                                    />
                                    <span className="text-sm text-[var(--color-text-main)] truncate">
                                        {segment.label}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 flex-shrink-0">
                                    {showValues && (
                                        <span className="text-sm font-medium text-[var(--color-text-main)]">
                                            {valueFormatter(segment.value)}
                                        </span>
                                    )}
                                    {showPercentages && (
                                        <span className="text-sm text-[var(--color-text-muted)]">
                                            {segment.percentage.toFixed(1)}%
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

// Helper function to convert polar coordinates to cartesian
function polarToCartesian(
    centerX: number,
    centerY: number,
    radius: number,
    angleInDegrees: number,
) {
    const angleInRadians = (angleInDegrees * Math.PI) / 180;
    return {
        x: centerX + radius * Math.cos(angleInRadians),
        y: centerY + radius * Math.sin(angleInRadians),
    };
}
