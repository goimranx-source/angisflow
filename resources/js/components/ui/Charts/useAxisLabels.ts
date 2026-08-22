import { useEffect, useState, type RefObject } from 'react';

/** Roughly what one axis label occupies, label plus the gap after it. */
const LABEL_FOOTPRINT_PX = 62;

/**
 * How wide an element actually is, kept current as it resizes.
 *
 * Charts here are drawn in percentages and never need to know their own size
 * — except for one thing: how many axis labels will fit. That is a question
 * about pixels and text, and answering it from the number of data points
 * alone is what produced "1 Aug5 Aug" on a narrow panel and a sparse axis on
 * a wide one.
 */
export function useElementWidth(ref: RefObject<HTMLElement | null>): number {
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        setWidth(element.getBoundingClientRect().width);

        const observer = new ResizeObserver((entries) => {
            const entry = entries[0];

            if (entry) {
                setWidth(entry.contentRect.width);
            }
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, [ref]);

    return width;
}

/**
 * Which label positions to draw, given how many there are and how much room.
 *
 * Two rules beyond the obvious stride:
 *
 *   The last is always shown. An axis whose final column is unlabelled looks
 *   truncated — the reader cannot tell where the range actually ends.
 *
 *   The one before it stands down if keeping it would crowd the last. A
 *   stride lands wherever it lands, so the gap between the final strided
 *   label and the end of the series is anything from a full stride to one
 *   column; without this the two overlap and print as "29A31Aug".
 */
export function axisLabelIndexes(count: number, width: number): number[] {
    if (count <= 0) {
        return [];
    }

    // Before the first measurement, assume room for a reasonable few rather
    // than all of them — one crowded frame on mount is still a crowded frame.
    const capacity = width > 0 ? Math.floor(width / LABEL_FOOTPRINT_PX) : 6;
    const maxLabels = Math.max(2, capacity);

    if (count <= maxLabels) {
        return Array.from({ length: count }, (_, i) => i);
    }

    const stride = Math.max(1, Math.ceil(count / maxLabels));
    const last = count - 1;
    const shown: number[] = [];

    for (let i = 0; i < count; i += stride) {
        shown.push(i);
    }

    // Make room for the final label, then add it. The threshold is a
    // fraction of the stride rather than the whole of it: a label needs
    // roughly its own footprint of clearance, not a full step, and popping
    // on the stricter test throws away a perfectly placed label to leave a
    // conspicuous gap before the last one.
    while (shown.length > 0 && last - (shown[shown.length - 1] as number) < stride * 0.6) {
        shown.pop();
    }

    shown.push(last);

    return shown;
}
