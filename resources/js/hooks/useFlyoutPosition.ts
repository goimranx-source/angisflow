import { useCallback, useLayoutEffect, useState, type RefObject } from 'react';

/** How far a panel keeps from the edge of the window. */
const MARGIN = 8;

/** The gap between a trigger and the panel that belongs to it. */
const GAP = 6;

export type FlyoutPlacement = {
    top: number;
    left: number;

    /** Set when neither side of the trigger had room for the whole panel. */
    maxHeight?: number;

    /** Which way it ended up going. Useful for the arrival animation's origin. */
    side: 'below' | 'above';
};

/**
 * Where a flyout goes, given the window it has to fit inside.
 *
 * ── The problem this replaces ────────────────────────────────────────────────
 *
 * Every panel in the app placed itself the same way: directly below its
 * trigger, aligned to one edge, and that was the whole calculation. It is right
 * almost always, and wrong in the cases that matter — the last row of a long
 * table, a filter button near the bottom of a short window, a calendar opened
 * from the right-hand end of a toolbar. The panel goes off the screen and the
 * one thing somebody wanted to read is the part they cannot.
 *
 * Some of them clamped horizontally with a `Math.max(8, …)`, which stops a menu
 * running off the left edge and does nothing at all about the bottom, where the
 * problem actually is.
 *
 * ── What it does ─────────────────────────────────────────────────────────────
 *
 * Below the trigger if the panel fits there. Above it if it does not and there
 * is more room above — a menu flipping up from a bottom row is the behaviour
 * every desktop menu has, and reads as deliberate rather than as a panel that
 * has slipped.
 *
 * If neither side fits, it takes the roomier one and is told how tall it may
 * be, so it scrolls inside itself rather than off the screen. A panel you can
 * scroll is usable; a panel below the fold is not there.
 *
 * Sideways it aligns to the requested edge of the trigger and is then clamped
 * into the window, which is the same guard the old `Math.max` was, applied on
 * both sides rather than one.
 *
 * ── Why it measures the panel rather than being told its size ────────────────
 *
 * Because the panels differ by a factor of five — a four-item row menu and a
 * two-month calendar — and any number written here would be wrong for one of
 * them the moment somebody adds a menu item. The measurement is cheap and it is
 * never stale.
 *
 * The panel has to be in the DOM to be measured, so it is rendered at the
 * top-left corner for one frame first. `useLayoutEffect` runs before the
 * browser paints, so nothing is ever seen there.
 */
export function useFlyoutPosition({
    open,
    trigger,
    panel,
    align = 'end',
}: {
    open: boolean;
    trigger: RefObject<HTMLElement | null>;
    panel: RefObject<HTMLElement | null>;

    /** Which edge of the panel meets which edge of the trigger. */
    align?: 'start' | 'end';
}): FlyoutPlacement | null {
    const [at, setAt] = useState<FlyoutPlacement | null>(null);

    const place = useCallback(() => {
        const anchor = trigger.current;
        const box = panel.current;

        if (!anchor || !box) {
            return;
        }

        const rect = anchor.getBoundingClientRect();
        const { offsetWidth: width, offsetHeight: height } = box;
        const { innerWidth: vw, innerHeight: vh } = window;

        const below = vh - rect.bottom - GAP - MARGIN;
        const above = rect.top - GAP - MARGIN;

        // Below unless it will not fit and there is more room the other way.
        const side: 'below' | 'above' = height <= below || below >= above ? 'below' : 'above';

        const room = side === 'below' ? below : above;
        const capped = height > room ? room : undefined;
        const tall = capped ?? height;

        const top = side === 'below' ? rect.bottom + GAP : rect.top - GAP - tall;

        // Aligned to the trigger, then pulled back inside the window. Both
        // edges, because a menu on a narrow window can overshoot either.
        const wanted = align === 'end' ? rect.right - width : rect.left;
        const left = Math.min(Math.max(MARGIN, wanted), Math.max(MARGIN, vw - width - MARGIN));

        setAt({ top, left, maxHeight: capped, side });
    }, [align, panel, trigger]);

    useLayoutEffect(() => {
        if (!open) {
            setAt(null);

            return;
        }

        place();

        /*
         * Re-placed while it is open, because the thing it is anchored to moves.
         *
         * A table scrolls under a row menu; the window is resized; a banner
         * above collapses. Capture is on so a scroll inside any pane counts,
         * not only the document's own — most of these live inside a scrolling
         * card, and the document never scrolls at all.
         */
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open, place]);

    return at;
}
