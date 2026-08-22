import { useMemo, useRef, useState } from 'react';

import { axisLabelIndexes, useElementWidth } from '@/components/ui/Charts/useAxisLabels';
import { cn } from '@/lib/utils';

export type ComparisonSeries = {
    name: string;
    color: string;
    data: Array<{ label: string; value: number }>;
    /**
     * Tooltip-only formatter for a series whose unit differs from the
     * chart's own — an order count riding alongside money bars, say. Falls
     * back to the chart's `valueFormatter` when absent, which is correct
     * for every series sharing one unit.
     */
    formatter?: (value: number) => string;
};

type ComparisonChartProps = {
    series: ComparisonSeries[];
    height?: number;
    valueFormatter?: (value: number) => string;
    /** Roughly how many y-axis lines to draw. */
    ticks?: number;
    className?: string;
    /**
     * Scale each series to its own peak rather than sharing one axis.
     *
     * Two series sharing a unit belong on one scale — the gap between the
     * bars is the answer. Money beside an order count does not: at a shared
     * scale the count is a sliver beside every revenue bar, present but
     * unreadable. Independent scaling asks a different question of the
     * pairing — "did these rise and fall together" rather than "which is
     * bigger" — and answers it by giving each series the full height its
     * own peak deserves. The y-axis keeps showing series[0]'s scale, since
     * an axis cannot honestly label two units at once; the other series'
     * real figures still arrive by hovering, through its own formatter.
     */
    independentScale?: boolean;
};

/** A "nice" step — 1, 2 or 5 × a power of ten — so the axis reads 0/20K/40K
 *  rather than 0/17,432/34,864. */
function niceStep(rough: number): number {
    if (rough <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const normalised = rough / magnitude;

    return (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) * magnitude;
}

/** @return the {max, step} a peak value rounds up to. */
function scaleFor(peak: number, ticks: number): { max: number; step: number } {
    const rough = niceStep(peak / Math.max(1, ticks));
    const top = Math.max(rough, Math.ceil(peak / rough) * rough);

    return { max: top, step: rough };
}

/**
 * Two or more series compared, side by side, per period.
 *
 * ── Why grouped bars rather than overlaid lines ──────────────────────────────
 *
 * The question these answer is "which was bigger, and by how much" — money in
 * against money out, revenue against expense. Two lines cross and the eye has to
 * track which is which; two bars sharing a baseline are one comparison per
 * column, and the gap between them is the answer read directly.
 *
 * ── Why the bars are HTML rather than SVG ────────────────────────────────────
 *
 * The plot is drawn as positioned divs, not <rect>s in a stretched viewBox. A
 * `viewBox="0 0 100 100"` with `preserveAspectRatio="none"` maps one user unit
 * to a different number of pixels horizontally than vertically, so a corner
 * radius given in user units comes out as an ellipse — visibly wider than it is
 * tall on every bar. CSS `border-radius` is in real pixels and needs no
 * correction, which is the whole reason the bars moved out of the SVG.
 *
 * ── Labels thin themselves ───────────────────────────────────────────────────
 *
 * Thirty days at panel width is thirty labels in about four hundred pixels, so
 * every one drawn collapses into unreadable stubs. The stride is worked out from
 * how many will actually fit, and the first and last are anchored to their own
 * edges rather than centred — centred, half of each would hang off the side of
 * the plot and be clipped.
 */
export function ComparisonChart({
    series,
    height = 260,
    valueFormatter = (v) => String(v),
    ticks = 4,
    className,
    independentScale = false,
}: ComparisonChartProps) {
    const plotRef = useRef<HTMLDivElement>(null);
    const plotWidth = useElementWidth(plotRef);
    const [hover, setHover] = useState<number | null>(null);

    const labels = series[0]?.data.map((point) => point.label) ?? [];
    const columns = labels.length;

    // Nothing has happened yet — no orders, no entries. Worth knowing
    // before the axis is built, because a scale drawn from a peak of zero
    // prints "0" five times over and reads as a broken chart rather than a
    // quiet month.
    const isEmpty = useMemo(
        () => series.every((s) => s.data.every((point) => point.value === 0)),
        [series],
    );

    // The axis's own scale — series[0] only when independent, since an axis
    // cannot honestly carry two units.
    const { max, step } = useMemo(() => {
        const scopeSeries = independentScale ? series.slice(0, 1) : series;
        const peak = Math.max(0, ...scopeSeries.flatMap((s) => s.data.map((point) => point.value)));

        return scaleFor(peak, ticks);
    }, [series, ticks, independentScale]);

    // What each series' own bars are actually measured against — the shared
    // axis above, or its own peak when scaled independently.
    const seriesScales = useMemo(
        () =>
            series.map((s) => {
                if (!independentScale) {
                    return { max, step };
                }

                const peak = Math.max(0, ...s.data.map((point) => point.value));

                return scaleFor(peak, ticks);
            }),
        [series, ticks, independentScale, max, step],
    );

    const gridValues = useMemo(() => {
        // One line at the baseline when there is nothing to scale. Five
        // gridlines all labelled "0" is not an axis, it is the same fact
        // repeated until it looks like a fault.
        if (isEmpty) {
            return [0];
        }

        const out: number[] = [];

        for (let v = 0; v <= max; v += step) {
            out.push(v);
        }

        return out;
    }, [max, step, isEmpty]);

    // Every series in a column shares the column's width, so a group of two
    // takes the same room as a group of five.
    const columnWidth = 100 / Math.max(1, columns);
    const groupInset = 0.18;
    const barWidth = (columnWidth * (1 - groupInset * 2)) / Math.max(1, series.length);

    const shownLabelIndexes = axisLabelIndexes(columns, plotWidth);

    // Where the tooltip sits, worked out from the hovered column's own
    // position rather than always centred above it — centred, a tooltip
    // over the first or last column spills half its width past the panel's
    // own edge. Right is the preferred side; it flips to the left once the
    // column is far enough into the right of the chart that a right-hand
    // tooltip would be the one to spill. A chart too narrow for either side
    // to have room (a handful of wide columns) drops the tooltip below the
    // bars instead, centred under whichever series peaks highest.
    const tooltip = useMemo(() => {
        if (hover === null) {
            return null;
        }

        const peakValue = Math.max(...series.map((s) => s.data[hover]?.value ?? 0));
        const peakIndex = series.findIndex((s) => (s.data[hover]?.value ?? 0) === peakValue);
        const peakMax = seriesScales[peakIndex]?.max ?? max;
        const peakY = peakMax === 0 ? 100 : 100 - (peakValue / peakMax) * 100;
        const columnCentre = (hover + 0.5) * columnWidth;

        if (columns <= 3) {
            return {
                left: `${columnCentre}%`,
                top: '100%',
                transform: 'translate(-50%, 0)',
                marginTop: '10px',
            };
        }

        const onRight = columnCentre <= 65;

        return {
            left: onRight ? `${(hover + 1) * columnWidth}%` : `${hover * columnWidth}%`,
            top: `${peakY}%`,
            transform: onRight ? 'translate(0, -50%)' : 'translate(-100%, -50%)',
            marginLeft: onRight ? '8px' : '-8px',
        };
    }, [hover, series, seriesScales, max, columnWidth, columns]);

    return (
        <div className={cn('flex w-full flex-col', className)} style={{ height }}>
            <div className="flex min-h-0 flex-1">
                {/* The y-axis sits outside the plot so its labels cannot be
                    clipped by the plot's own edges. */}
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

                <div
                    ref={plotRef}
                    className="relative flex-1"
                    role="img"
                    aria-label={series.map((s) => s.name).join(' compared with ')}
                >
                    {/* Gridlines */}
                    {gridValues.map((value) => (
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

                    {/* Hover columns, and the bars they carry. */}
                    {labels.map((_, column) => (
                        <div
                            key={column}
                            className="absolute inset-y-0"
                            style={{
                                left: `${column * columnWidth}%`,
                                width: `${columnWidth}%`,
                                background: hover === column ? 'var(--shell-tint)' : 'transparent',
                                transition: 'background-color 0.12s ease',
                            }}
                            onMouseEnter={() => setHover(column)}
                            onMouseLeave={() => setHover(null)}
                        />
                    ))}

                    {series.map((s, seriesIndex) =>
                        s.data.map((point, column) => {
                            const seriesMax = seriesScales[seriesIndex]?.max ?? max;
                            const heightPct = seriesMax === 0 ? 0 : (point.value / seriesMax) * 100;

                            if (heightPct <= 0) {
                                return null;
                            }

                            return (
                                <div
                                    key={`${s.name}-${column}`}
                                    className="pointer-events-none absolute bottom-0"
                                    style={{
                                        left: `${
                                            column * columnWidth +
                                            columnWidth * groupInset +
                                            seriesIndex * barWidth
                                        }%`,
                                        width: `${barWidth}%`,
                                        // A floor of 2px, so a real but tiny
                                        // figure is a visible sliver rather
                                        // than nothing at all — "we sold one"
                                        // and "we sold none" must not look
                                        // identical.
                                        height: `max(2px, ${heightPct}%)`,
                                        background: s.color,
                                        // Both ends, in real pixels — the
                                        // whole reason these are divs.
                                        borderRadius: '3px',
                                        opacity: hover === null || hover === column ? 1 : 0.45,
                                        transition: 'opacity 0.12s ease',
                                    }}
                                />
                            );
                        }),
                    )}

                    {/* A styled floating tooltip rather than the browser's own
                        <title> — that one is slow to appear, cannot be
                        themed, and shows one value at a time even when a
                        column carries two series. */}
                    {hover !== null && tooltip && (
                        <div
                            className="pointer-events-none absolute z-10 whitespace-nowrap rounded-[var(--shell-radius-sm)] border border-[var(--shell-border)] bg-[var(--shell-bg)] px-2.5 py-1.5 text-xs shadow-[var(--shadow-md)]"
                            style={{
                                left: tooltip.left,
                                top: tooltip.top,
                                transform: tooltip.transform,
                                marginLeft: tooltip.marginLeft,
                                marginTop: tooltip.marginTop,
                            }}
                        >
                            <p className="font-semibold text-[var(--color-text-main)]">{labels[hover]}</p>
                            {series.map((s) => (
                                <p
                                    key={s.name}
                                    className="mt-0.5 flex items-center gap-1.5 text-[var(--color-text-muted)]"
                                >
                                    <span
                                        className="size-1.5 flex-none rounded-full"
                                        style={{ background: s.color }}
                                        aria-hidden
                                    />
                                    {s.name}
                                    <span className="ml-auto pl-2 font-semibold text-[var(--color-text-main)]">
                                        {(s.formatter ?? valueFormatter)(s.data[hover]?.value ?? 0)}
                                    </span>
                                </p>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* The x-axis. First and last labels are anchored to their own
                edges rather than centred on their column: centred, half of
                each hangs off the side of the plot and gets clipped. */}
            <div className="flex flex-none">
                <div className="w-12 flex-none" aria-hidden />
                <div className="relative h-6 flex-1">
                    {shownLabelIndexes.map((i, position) => {
                        const isFirst = position === 0;
                        const isLast = position === shownLabelIndexes.length - 1;

                        return (
                            <span
                                key={`${labels[i]}-${i}`}
                                className="absolute top-1 text-[0.625rem] whitespace-nowrap text-[var(--color-text-muted)]"
                                style={
                                    isFirst
                                        ? { left: 0 }
                                        : isLast
                                          ? { right: 0 }
                                          : {
                                                left: `${(i + 0.5) * columnWidth}%`,
                                                transform: 'translateX(-50%)',
                                            }
                                }
                            >
                                {labels[i]}
                            </span>
                        );
                    })}
                </div>
            </div>

            <div className="mt-5 flex flex-none flex-wrap items-center justify-center gap-4">
                {series.map((s) => (
                    <span key={s.name} className="flex items-center gap-1.5">
                        <span
                            className="size-2 rounded-full"
                            style={{ background: s.color }}
                            aria-hidden
                        />
                        <span className="text-[0.6875rem] font-medium text-[var(--color-text-muted)]">
                            {s.name}
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}
