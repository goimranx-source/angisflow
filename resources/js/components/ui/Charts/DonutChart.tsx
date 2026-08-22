import { useState } from 'react';

import { cn } from '@/lib/utils';

export type DonutSlice = {
    label: string;
    value: number;
    color: string;
};

type DonutChartProps = {
    data: DonutSlice[];
    size?: number;
    /** Ring thickness as a fraction of the radius. */
    thickness?: number;
    /** The big figure in the middle. Omitted, the largest slice's share is used.
     *  Keep it short — the hole is about 70% of `size` across, and a full
     *  "RM 15,372.81" runs past the ring and out of the panel. Hand the exact
     *  figure to `centerTitle` instead. */
    centerValue?: string;
    /** The unabbreviated figure, revealed on hover over the centre. */
    centerTitle?: string;
    centerLabel?: string;
    /** 'below' stacks the legend under the ring; 'side' puts it to the right. */
    legend?: 'below' | 'side' | 'none';
    valueFormatter?: (value: number) => string;
    className?: string;
};

/**
 * A proportional breakdown — where the money went, what the mix is.
 *
 * ── A ring rather than a pie ─────────────────────────────────────────────────
 *
 * The hole is not decoration. It gives the headline figure somewhere to live, so
 * the chart answers "how much of the total is the biggest slice" without the
 * reader estimating an angle — which people are famously bad at. The segments
 * then carry the comparison and the centre carries the number.
 *
 * ── Gaps between segments ────────────────────────────────────────────────────
 *
 * Drawn with a small stroke gap rather than butted together. Two adjacent
 * segments of similar lightness read as one segment without it, which is the
 * one failure mode a proportional chart cannot survive.
 */
export function DonutChart({
    data,
    size = 180,
    thickness = 0.28,
    centerValue,
    centerTitle,
    centerLabel,
    legend = 'below',
    valueFormatter = (v) => String(v),
    className,
}: DonutChartProps) {
    const [hover, setHover] = useState<number | null>(null);

    const total = data.reduce((sum, slice) => sum + slice.value, 0);

    // Geometry in a 100-unit box, so `size` scales it without touching the maths.
    const radius = 50 - (50 * thickness) / 2;
    const circumference = 2 * Math.PI * radius;
    const strokeWidth = 50 * thickness;
    // Under about two degrees a gap eats the segment it is separating.
    const gap = data.length > 1 ? Math.min(circumference * 0.006, 1.4) : 0;

    const biggest = data.reduce(
        (best, slice) => (slice.value > best.value ? slice : best),
        data[0] ?? { label: '', value: 0, color: '' },
    );

    const share = total > 0 ? Math.round((biggest.value / total) * 100) : 0;

    let offset = 0;

    return (
        <div
            className={cn(
                'flex items-center gap-5',
                legend === 'below' ? 'flex-col' : 'flex-row',
                className,
            )}
        >
            <div className="relative flex-none" style={{ width: size, height: size }}>
                <svg viewBox="0 0 100 100" className="-rotate-90" width={size} height={size}>
                    {total === 0 ? (
                        <circle
                            cx="50"
                            cy="50"
                            r={radius}
                            fill="none"
                            stroke="var(--shell-tint)"
                            strokeWidth={strokeWidth}
                        />
                    ) : (
                        data.map((slice, i) => {
                            const length = (slice.value / total) * circumference;
                            const dash = Math.max(0, length - gap);
                            const element = (
                                <circle
                                    key={slice.label}
                                    cx="50"
                                    cy="50"
                                    r={radius}
                                    fill="none"
                                    stroke={slice.color}
                                    strokeWidth={strokeWidth}
                                    strokeDasharray={`${dash} ${circumference - dash}`}
                                    strokeDashoffset={-offset}
                                    strokeLinecap="butt"
                                    opacity={hover === null || hover === i ? 1 : 0.35}
                                    style={{ transition: 'opacity 0.12s ease' }}
                                    onMouseEnter={() => setHover(i)}
                                    onMouseLeave={() => setHover(null)}
                                >
                                    <title>
                                        {slice.label}: {valueFormatter(slice.value)}
                                    </title>
                                </circle>
                            );

                            offset += length;

                            return element;
                        })
                    )}
                </svg>

                {/* Constrained to the hole rather than the ring's full box —
                    the centre of a donut is only about 70% of its width
                    across, and text laid out against the outer box runs
                    straight over the segments and out of the panel. */}
                <div
                    className="absolute inset-0 grid place-content-center px-2 text-center"
                    style={{ maxWidth: size, pointerEvents: 'none' }}
                >
                    <span
                        className="truncate font-[family-name:var(--font-heading)] text-lg font-bold text-[var(--color-text-main)]"
                        style={{ maxWidth: size * 0.66, pointerEvents: 'auto' }}
                        title={centerTitle}
                    >
                        {centerValue ?? (total > 0 ? `${share}%` : '—')}
                    </span>
                    {(centerLabel ?? (total > 0 ? biggest.label : undefined)) && (
                        <span
                            className="mt-0.5 truncate text-[0.625rem] text-[var(--color-text-muted)]"
                            style={{ maxWidth: size * 0.66 }}
                        >
                            {centerLabel ?? biggest.label}
                        </span>
                    )}
                </div>
            </div>

            {legend !== 'none' && (
                <ul
                    className={cn(
                        'min-w-0',
                        legend === 'below'
                            ? 'flex flex-wrap justify-center gap-x-4 gap-y-1.5'
                            : 'flex-1 space-y-2',
                    )}
                >
                    {data.map((slice, i) => (
                        <li
                            key={slice.label}
                            className={cn(
                                'flex items-center gap-2',
                                legend === 'side' && 'justify-between',
                            )}
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        >
                            <span className="flex min-w-0 items-center gap-1.5">
                                <span
                                    className="size-2 flex-none rounded-full"
                                    style={{ background: slice.color }}
                                    aria-hidden
                                />
                                <span className="truncate text-[0.6875rem] font-medium text-[var(--color-text-muted)]">
                                    {slice.label}
                                </span>
                            </span>

                            {legend === 'side' && (
                                <span className="flex-none text-[0.6875rem] font-semibold text-[var(--color-text-main)] [font-variant-numeric:tabular-nums]">
                                    {total > 0 ? `${Math.round((slice.value / total) * 100)}%` : '—'}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
