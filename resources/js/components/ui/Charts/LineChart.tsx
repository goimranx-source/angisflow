import { useMemo, useRef, useState } from 'react';

import { gridValuesFor, scaleFor } from '@/components/ui/Charts/scale';
import { axisLabelIndexes, useElementWidth } from '@/components/ui/Charts/useAxisLabels';
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
    /** Show a dot at every point, not only the hovered one */
    showPoints?: boolean;
    /** Tooltip-only formatter, for a series whose unit differs from the rest */
    formatter?: (value: number) => string;
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
    /** Roughly how many y-axis lines to draw */
    ticks?: number;
    /** Curve the line (smooth) */
    curved?: boolean;
    /** Fill area under line */
    filled?: boolean;
    /** Additional class */
    className?: string;
};

const DEFAULT_COLORS = [
    'var(--color-brand)',
    'var(--color-danger)',
    'var(--color-success)',
    'var(--color-warning)',
    'var(--color-info)',
];

/** The dark chip the tooltip and the pinned axis label both wear. */
const CHIP_BG = '#1a1f2b';

/**
 * A smooth line that actually goes through its own data points.
 *
 * ── Why not the quadratic curve this replaces ────────────────────────────────
 *
 * The previous smoothing put each data point in as a Bézier *control* point
 * and drew the curve through the midpoints between them. Control points are
 * not on the curve, so the line missed every marker it was supposed to join:
 * on a flat series the midpoints coincide with the points and it looked
 * right, and on a spiky one the line ran visibly below a dot sitting at
 * 4.5K — which is what was reported.
 *
 * ── Monotone cubic, not Catmull-Rom ──────────────────────────────────────────
 *
 * Both interpolate through the points. Catmull-Rom overshoots around a spike,
 * which on a money chart means the line dipping below zero between two
 * takings — drawing a debt nobody incurred. The Fritsch–Carlson tangents
 * below are chosen precisely so the curve cannot overshoot: it is flat at
 * every local extreme and monotone between points, so the drawn line never
 * claims a value the data does not contain.
 */
function monotonePath(points: Array<{ x: number; y: number }>): string {
    const n = points.length;
    const first = points[0];

    if (n < 2 || !first) {
        return '';
    }

    const dx: number[] = [];
    const slope: number[] = [];

    for (let i = 0; i < n - 1; i++) {
        const a = points[i] as { x: number; y: number };
        const b = points[i + 1] as { x: number; y: number };
        const run = b.x - a.x;

        dx[i] = run;
        slope[i] = run === 0 ? 0 : (b.y - a.y) / run;
    }

    // The tangent at each point. Zero wherever the series turns, which is
    // what pins the curve to the data at every peak and trough.
    const tangent: number[] = new Array(n);
    tangent[0] = slope[0] ?? 0;
    tangent[n - 1] = slope[n - 2] ?? 0;

    for (let i = 1; i < n - 1; i++) {
        const prev = slope[i - 1] as number;
        const next = slope[i] as number;

        if (prev * next <= 0) {
            tangent[i] = 0;
            continue;
        }

        const w1 = 2 * (dx[i] as number) + (dx[i - 1] as number);
        const w2 = (dx[i] as number) + 2 * (dx[i - 1] as number);

        tangent[i] = (w1 + w2) / (w1 / prev + w2 / next);
    }

    let path = `M ${first.x} ${first.y}`;

    for (let i = 0; i < n - 1; i++) {
        const a = points[i] as { x: number; y: number };
        const b = points[i + 1] as { x: number; y: number };
        const run = (dx[i] as number) / 3;

        path +=
            ` C ${a.x + run} ${a.y + (tangent[i] as number) * run},` +
            ` ${b.x - run} ${b.y - (tangent[i + 1] as number) * run},` +
            ` ${b.x} ${b.y}`;
    }

    return path;
}

/**
 * A line chart, with a crosshair.
 *
 * ── Why the lines are SVG and everything else is not ─────────────────────────
 *
 * The paths are drawn in a `viewBox="0 0 100 100"` with
 * `preserveAspectRatio="none"`, which lets a point be placed as a percentage on
 * both axes without measuring anything. The cost is that one user unit is a
 * different number of pixels horizontally than vertically — harmless for a
 * stroked path (`vectorEffect="non-scaling-stroke"` keeps the width honest) and
 * fatal for anything meant to be round. A `<circle r="4">` in that space renders
 * as an ellipse tens of pixels wide, which is exactly what the hover dots were
 * doing. So the dots, the crosshair, the tooltip and the labels are all HTML
 * positioned in percentages, where a circle is a circle.
 *
 * ── The layout is flow, not offsets ──────────────────────────────────────────
 *
 * Axis labels used to hang off the plot on a negative offset, which put them
 * outside the box the chart claimed to occupy: they collided with the legend,
 * spilled past the panel's edge and were clipped at the sides. The chart is a
 * flex column now — plot, then axis, then legend — so every part is inside the
 * height it was given, and the hover area is exactly the plot rather than
 * whatever happened to sit under the cursor further down the card.
 */
export function LineChart({
    series,
    height = 300,
    showYAxis = true,
    showXAxis = true,
    showGrid = true,
    showLegend = true,
    valueFormatter = (v) => v.toString(),
    ticks = 4,
    curved = false,
    filled = false,
    className,
}: LineChartProps) {
    const plotRef = useRef<HTMLDivElement>(null);
    const plotWidth = useElementWidth(plotRef);
    const [hoverIndex, setHoverIndex] = useState<number | null>(null);

    const allLabels = useMemo(() => {
        const seen = new Set<string>();
        const out: string[] = [];

        series.forEach((s) =>
            s.data.forEach((d) => {
                if (!seen.has(d.label)) {
                    seen.add(d.label);
                    out.push(d.label);
                }
            }),
        );

        return out;
    }, [series]);

    // Nothing has happened yet. Worth knowing before the scale is built: a
    // peak of zero makes every gridline "0", and — worse — makes the
    // value-to-height arithmetic divide by nothing and put a flat line of
    // zeroes across the top of the chart, which reads as a full month of
    // trading rather than an empty one.
    const isEmpty = useMemo(
        () => series.every((s) => s.data.every((d) => d.value === 0)),
        [series],
    );

    const { max, step } = useMemo(() => {
        if (isEmpty) {
            return { max: 1, step: 1 };
        }

        const peak = Math.max(0, ...series.flatMap((s) => s.data.map((d) => d.value)));

        return scaleFor(peak, ticks);
    }, [series, ticks, isEmpty]);

    const gridValues = useMemo(() => gridValuesFor(max, step, isEmpty), [max, step, isEmpty]);

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

    /** Percent across the plot, 0–100. */
    const pointX = (index: number) => (index / (allLabels.length - 1 || 1)) * 100;
    /** Percent down the plot, 0 at the top — the direction SVG and CSS agree on. */
    const pointY = (value: number) => (max === 0 ? 100 : 100 - (value / max) * 100);

    const createPath = (seriesData: LineChartDataPoint[]) => {
        if (seriesData.length === 0) return '';

        const points = seriesData.map((point, index) => ({
            x: pointX(index),
            y: pointY(point.value),
        }));

        if (curved && points.length > 1) {
            return monotonePath(points);
        }

        return points.map((p, i) => (i === 0 ? `M ${p.x} ${p.y}` : `L ${p.x} ${p.y}`)).join(' ');
    };

    // Nearest column to the pointer, from its position across the plot
    // rather than a hitbox per point — sixty points would otherwise be sixty
    // targets to keep in step, and the gaps between them dead.
    const handlePointerMove = (event: React.MouseEvent<HTMLDivElement>) => {
        const bounds = plotRef.current?.getBoundingClientRect();

        if (!bounds || bounds.width === 0 || allLabels.length === 0) {
            return;
        }

        const ratio = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));
        setHoverIndex(Math.round(ratio * (allLabels.length - 1)));
    };

    const shownLabelIndexes = axisLabelIndexes(allLabels.length, plotWidth);
    // How far a strided label has to be from the hovered one before the two
    // can both be drawn without touching.
    const stride = Math.max(1, Math.ceil(allLabels.length / Math.max(1, shownLabelIndexes.length)));

    const hoverX = hoverIndex !== null ? pointX(hoverIndex) : null;

    // Level with whichever series is highest at that column, so the tooltip
    // never floats over empty space or gets crossed by a line above it —
    // then clamped away from both edges of the plot. Unclamped, a column
    // whose values all sit near zero puts the tooltip on the axis, over the
    // date chip and the legend below it.
    const hoverTopY =
        hoverIndex !== null
            ? Math.min(
                  72,
                  Math.max(8, Math.min(...series.map((s) => pointY(s.data[hoverIndex]?.value ?? 0)))),
              )
            : null;

    // Right of the crosshair by preference, flipping left once the column is
    // far enough right that a right-hand tooltip would spill past the plot.
    const tooltipOnRight = hoverX !== null && hoverX <= 62;

    return (
        <div className={cn('flex w-full flex-col', className)} style={{ height: `${height}px` }}>
            <div className="flex min-h-0 flex-1">
                {showYAxis && (
                    <div className="flex w-12 flex-none flex-col-reverse justify-between pr-2 text-right">
                        {gridValues.map((value) => (
                            <span
                                key={value}
                                className="text-[0.625rem] leading-none text-[var(--color-text-muted)]"
                            >
                                {valueFormatter(value)}
                            </span>
                        ))}
                    </div>
                )}

                <div
                    ref={plotRef}
                    className="relative flex-1"
                    // Entering counts as moving. Without this the crosshair
                    // waits for the first mousemove, which never arrives if
                    // the pointer lands and stops.
                    onMouseEnter={handlePointerMove}
                    onMouseMove={handlePointerMove}
                    onMouseLeave={() => setHoverIndex(null)}
                    role="img"
                    aria-label={series.map((s) => s.name).join(' and ')}
                >
                    {showGrid &&
                        gridValues.map((value) => (
                            <div
                                key={value}
                                className="absolute inset-x-0 border-t"
                                style={{
                                    bottom: `${max === 0 ? 0 : (value / max) * 100}%`,
                                    borderColor: 'var(--shell-border)',
                                    borderTopStyle: value === 0 ? 'solid' : 'dashed',
                                }}
                            />
                        ))}

                    {/* The crosshair — a dashed guide the full height of the
                        plot, running down to the pinned label on the axis. */}
                    {hoverX !== null && (
                        <div
                            className="pointer-events-none absolute inset-y-0 border-l border-dashed"
                            style={{ left: `${hoverX}%`, borderColor: 'var(--color-text-subtle)' }}
                        />
                    )}

                    {/* Lines only. Everything round is drawn as HTML below. */}
                    <svg
                        className="absolute inset-0 h-full w-full"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        aria-hidden
                    >
                        {series.map((s, seriesIndex) => {
                            const color = s.color || DEFAULT_COLORS[seriesIndex % DEFAULT_COLORS.length];
                            const path = createPath(s.data);

                            return (
                                <g key={s.name}>
                                    {filled && (
                                        <path
                                            d={`${path} L 100 100 L 0 100 Z`}
                                            fill={color}
                                            fillOpacity="0.1"
                                        />
                                    )}
                                    <path
                                        d={path}
                                        fill="none"
                                        stroke={color}
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        vectorEffect="non-scaling-stroke"
                                    />
                                </g>
                            );
                        })}
                    </svg>

                    {/* Every point, when asked for — real circles, because
                        these are HTML rather than SVG in a stretched box. */}
                    {series.map((s, seriesIndex) =>
                        s.showPoints
                            ? s.data.map((point, index) => (
                                  <div
                                      key={`${s.name}-dot-${index}`}
                                      className="pointer-events-none absolute size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full"
                                      style={{
                                          left: `${pointX(index)}%`,
                                          top: `${pointY(point.value)}%`,
                                          background:
                                              s.color || DEFAULT_COLORS[seriesIndex % DEFAULT_COLORS.length],
                                      }}
                                  />
                              ))
                            : null,
                    )}

                    {/* One marker per series at the hovered column, each in
                        its own colour with a ring in the panel's background
                        so two that land close together stay countable. */}
                    {hoverIndex !== null &&
                        series.map((s, seriesIndex) => (
                            <div
                                key={`${s.name}-marker`}
                                className="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full"
                                style={{
                                    left: `${pointX(hoverIndex)}%`,
                                    top: `${pointY(s.data[hoverIndex]?.value ?? 0)}%`,
                                    background:
                                        s.color || DEFAULT_COLORS[seriesIndex % DEFAULT_COLORS.length],
                                    boxShadow: '0 0 0 2px var(--shell-bg)',
                                }}
                            />
                        ))}

                    {/* The tooltip. Dark whatever the shell's theme, on
                        purpose: it has to read instantly against whatever is
                        under the cursor, and a chip that inverts with the
                        page is one more thing to get wrong. */}
                    {hoverIndex !== null && hoverX !== null && hoverTopY !== null && (
                        <div
                            className="pointer-events-none absolute z-10 whitespace-nowrap rounded-lg px-3 py-2 text-xs shadow-[var(--shadow-lg)]"
                            style={{
                                left: `${hoverX}%`,
                                top: `${hoverTopY}%`,
                                transform: tooltipOnRight
                                    ? 'translate(0, -50%)'
                                    : 'translate(-100%, -50%)',
                                marginLeft: tooltipOnRight ? '12px' : '-12px',
                                background: CHIP_BG,
                                color: '#f3f4f6',
                            }}
                        >
                            <p className="font-semibold">{allLabels[hoverIndex]}</p>
                            <div className="mt-1.5 space-y-1">
                                {series.map((s, seriesIndex) => (
                                    <p key={s.name} className="flex items-center gap-2">
                                        <span
                                            className="size-2 flex-none rounded-full"
                                            style={{
                                                background:
                                                    s.color ||
                                                    DEFAULT_COLORS[seriesIndex % DEFAULT_COLORS.length],
                                            }}
                                            aria-hidden
                                        />
                                        <span className="text-[#c3ccd9]">{s.name}:</span>
                                        <span className="ml-auto pl-2 font-bold">
                                            {(s.formatter ?? valueFormatter)(
                                                s.data[hoverIndex]?.value ?? 0,
                                            )}
                                        </span>
                                    </p>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* The x-axis. First and last are anchored to their own edges
                rather than centred, so neither hangs off the plot; and a
                strided label close enough to collide with the pinned one
                stands down while hovering. */}
            {showXAxis && (
                <div className="flex flex-none">
                    {showYAxis && <div className="w-12 flex-none" aria-hidden />}
                    <div className="relative h-6 flex-1">
                        {shownLabelIndexes.map((i, position) => {
                            const isFirst = position === 0;
                            const isLast = position === shownLabelIndexes.length - 1;
                            const collides =
                                hoverIndex !== null && Math.abs(i - hoverIndex) < stride;

                            if (collides) {
                                return null;
                            }

                            return (
                                <span
                                    key={`${allLabels[i]}-${i}`}
                                    className="absolute top-1 text-[0.625rem] whitespace-nowrap text-[var(--color-text-muted)]"
                                    style={
                                        isFirst
                                            ? { left: 0 }
                                            : isLast
                                              ? { right: 0 }
                                              : { left: `${pointX(i)}%`, transform: 'translateX(-50%)' }
                                    }
                                >
                                    {allLabels[i]}
                                </span>
                            );
                        })}

                        {/* The hovered column's own label, as the same dark
                            chip the tooltip wears — the dashed guide simply
                            connects the two. */}
                        {hoverIndex !== null && hoverX !== null && (
                            <span
                                className="pointer-events-none absolute top-0 rounded-[var(--shell-radius-sm)] px-1.5 py-0.5 text-[0.625rem] font-semibold whitespace-nowrap"
                                style={{
                                    left: `${hoverX}%`,
                                    transform:
                                        hoverX <= 6
                                            ? 'translateX(0)'
                                            : hoverX >= 94
                                              ? 'translateX(-100%)'
                                              : 'translateX(-50%)',
                                    background: CHIP_BG,
                                    color: '#f3f4f6',
                                }}
                            >
                                {allLabels[hoverIndex]}
                            </span>
                        )}
                    </div>
                </div>
            )}

            {showLegend && (
                <div className="mt-5 flex flex-none flex-wrap items-center justify-center gap-4">
                    {series.map((s, index) => (
                        <span key={s.name} className="flex items-center gap-1.5">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: s.color || DEFAULT_COLORS[index % DEFAULT_COLORS.length] }}
                                aria-hidden
                            />
                            <span className="text-[0.6875rem] font-medium text-[var(--color-text-muted)]">
                                {s.name}
                            </span>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
