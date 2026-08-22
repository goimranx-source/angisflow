/**
 * Working out an axis a person can read.
 *
 * Shared by the line and bar charts so the two never disagree about what a
 * sensible scale looks like — a dashboard showing 0/20K/40K in one panel and
 * 0/17,432/34,864 in the next reads as two products.
 */

/** A "nice" step — 1, 2 or 5 × a power of ten — so the axis reads 0/20K/40K
 *  rather than 0/17,432/34,864. */
export function niceStep(rough: number): number {
    if (rough <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const normalised = rough / magnitude;

    return (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) * magnitude;
}

/**
 * The {max, step} a peak rounds up to.
 *
 * The top is rounded up to a whole step above the peak rather than sitting
 * exactly on it: a chart whose tallest bar touches the frame looks clipped.
 */
export function scaleFor(peak: number, ticks: number): { max: number; step: number } {
    const rough = niceStep(peak / Math.max(1, ticks));
    const top = Math.max(rough, Math.ceil(peak / rough) * rough);

    return { max: top, step: rough };
}

/**
 * The values an axis draws a line at, given a scale.
 *
 * Empty data gets a single line at the baseline rather than a full set all
 * labelled "0" — five gridlines saying zero is not an axis, it is the same
 * fact repeated until it looks like a fault.
 */
export function gridValuesFor(max: number, step: number, isEmpty: boolean): number[] {
    if (isEmpty || step <= 0) {
        return [0];
    }

    const out: number[] = [];

    for (let v = 0; v <= max; v += step) {
        out.push(v);
    }

    return out;
}
