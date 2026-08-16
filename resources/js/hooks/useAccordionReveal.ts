import { useEffect, useRef } from 'react';

/**
 * Bring a department's pages into view when it unfolds, and put the menu back
 * when it folds again.
 *
 * ── The problem ──────────────────────────────────────────────────────────────
 *
 * A department low in the rail opens downwards into space that is not on
 * screen. The heading stays put, the pages appear below the fold, and the only
 * sign anything happened is a caret rotating — so it reads as a click that did
 * nothing until you think to scroll. The lower the department, the worse it is,
 * and Setup is at the bottom.
 *
 * ── What it does instead ─────────────────────────────────────────────────────
 *
 * Scrolls by the smallest amount that brings the whole group into view, which
 * naturally lifts the heading up the rail rather than jumping it anywhere. A
 * group already fully visible is left alone: scrolling a menu that did not need
 * it is its own kind of wrong.
 *
 * ── Except when the department is taller than the rail ───────────────────────
 *
 * Then there is no scroll position that shows all of it, and the minimum-scroll
 * rule would align its *bottom* — pushing the heading off the top of the rail,
 * so a menu you just opened has lost the thing you opened. So that case aligns
 * the top instead and lets the tail run off the bottom, where a scrollbar is
 * the ordinary way to reach it.
 *
 * ── And the reverse on close ─────────────────────────────────────────────────
 *
 * The position from before the group opened is remembered and restored, so
 * opening something to look at it and closing it again leaves the rail where
 * it started. Without that, browsing three departments in a row walks the menu
 * steadily downwards and nothing ever brings it back.
 *
 * ── Why the height is measured before the row has actually grown ─────────────
 *
 * .nav-children-shell opens on a CSS grid-row transition, not an instant
 * mount, and waiting for that transition to finish before scrolling made the
 * two read as two separate steps — the fold visibly finishes opening, then a
 * second, unconnected jump. They are supposed to read as one movement.
 *
 * The fix is that .nav-children's scrollHeight is *already* its fully-open
 * height, even while the shell around it is still collapsed to 0fr — that is
 * what overflow: hidden plus scrollHeight always reports, regardless of how
 * small an ancestor is currently forcing the box to render. So the target
 * scroll position can be worked out immediately, before the row has moved at
 * all, and the two animations — the fold opening, the rail scrolling — start
 * on the same frame and finish together instead of one after the other.
 *
 * ── Why a still-closing sibling gets subtracted out ───────────────────────────
 *
 * Only one department is open at a time, so opening one always means another
 * is closing in the same tick — and that one is *also* mid-transition, still
 * rendered at some height above zero while it shrinks. Reading this group's
 * position straight off getBoundingClientRect would pick up whatever of that
 * sibling's shrinking height happens to still be there at the exact millisecond
 * this runs — correct once everything has settled, wrong while it hasn't, which
 * is why switching straight from one open department to another (rather than
 * opening one from fully closed) used to land short. Every earlier sibling
 * that is closed-or-closing gets its *current* rendered height subtracted from
 * the naive reading, which is exactly the amount it still has left to lose —
 * projecting this group's final position instead of its transient one.
 *
 * ── Why the scroll itself chases a target instead of just being told it ──────
 *
 * Knowing the right number early is not the same as being able to scroll to
 * it: the pane's own scrollHeight is the *sum* of every group's actual
 * rendered height, and this group's own fold is still 0px tall for the first
 * instant of its transition — .scrollTo({top: next}) asks the browser to land
 * somewhere that is not scrollable territory yet. A browser clamps that to
 * whatever the pane can currently reach and, having decided it arrived, does
 * not notice the floor keep dropping out from under it as the fold opens — it
 * stays clamped even once the real target becomes reachable a moment later.
 * Re-clamping every frame for the length of the fold's own transition instead
 * of once up front means the scroll position keeps pace with how much of the
 * pane actually exists to scroll into, arriving exactly on target the instant
 * the fold finishes rather than stalling wherever it first got clamped.
 */
export function useAccordionReveal(open: boolean) {
    const group = useRef<HTMLDivElement | null>(null);
    /** Where the rail was before this group opened. */
    const restoreTo = useRef<number | null>(null);
    const previous = useRef(open);

    useEffect(() => {
        if (open === previous.current) {
            return;
        }

        previous.current = open;

        const element = group.current;
        const pane = element?.closest('.rail-nav') as HTMLElement | null;

        if (!element || !pane) {
            return;
        }

        // Honour the system setting. The scroll still happens — landing
        // somewhere you cannot see is not an accessible alternative — it just
        // does not animate.
        const behavior: ScrollBehavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth';

        if (!open) {
            const target = restoreTo.current;
            restoreTo.current = null;

            // Switching straight from this department to another is not a
            // close — it only looks like one from here, because this hook
            // has no way to know the click that closed this group also
            // opened a different one. Restoring to where the rail was
            // *before this group ever opened* would fight that other
            // group's own reveal-scroll for the same frame, and the two
            // competing scrollTo calls is exactly what used to land
            // somewhere between both intended targets. If some other
            // department is open right now, its own hook owns the scroll —
            // this one steps back.
            const anotherIsOpen = pane.querySelector(':scope > .nav-group.is-open') !== null;

            if (target !== null && !anotherIsOpen) {
                pane.scrollTo({ top: target, behavior });
            }

            return;
        }

        restoreTo.current = pane.scrollTop;

        // Measured against the pane rather than read from offsetTop, which is
        // relative to whichever ancestor happens to be positioned and would
        // quietly start lying the day one of them gains a `position`.
        const paneBox = pane.getBoundingClientRect();
        const naiveTop = element.getBoundingClientRect().top - paneBox.top + pane.scrollTop;

        // Undo whatever an earlier sibling still mid-close is contributing —
        // see "why a still-closing sibling gets subtracted out" above. A
        // sibling that has already settled at 0 contributes nothing, so this
        // is safe to run unconditionally rather than only when a switch is
        // actually in flight.
        let stillClosing = 0;

        for (const sibling of pane.querySelectorAll(':scope > .nav-group')) {
            if (sibling === element) {
                break;
            }

            const shell = sibling.querySelector(':scope > .nav-children-shell');

            if (shell && !shell.classList.contains('is-open')) {
                stillClosing += shell.getBoundingClientRect().height;
            }
        }

        const groupTop = naiveTop - stillClosing;

        const parentHeight = element.querySelector('.nav-parent')?.getBoundingClientRect().height ?? 0;
        const childrenHeight = element.querySelector('.nav-children')?.scrollHeight ?? 0;
        const openHeight = parentHeight + childrenHeight;

        const top = groupTop;
        const bottom = top + openHeight;
        const viewTop = pane.scrollTop;
        const viewBottom = viewTop + pane.clientHeight;

        let next = viewTop;

        if (openHeight > pane.clientHeight) {
            next = top;
        } else if (bottom > viewBottom) {
            next = bottom - pane.clientHeight;
        } else if (top < viewTop) {
            next = top;
        }

        // A sub-pixel correction is not worth an animation.
        if (Math.abs(next - viewTop) <= 1) {
            return undefined;
        }

        if (behavior === 'auto') {
            // Reduced motion means the fold itself does not transition
            // either (see .nav-children-shell), so the pane's scrollable
            // area is already at its final size — nothing to chase.
            pane.scrollTop = Math.min(next, pane.scrollHeight - pane.clientHeight);
            return undefined;
        }

        // Chasing the target across a few frames rather than one
        // .scrollTo call — see "why the scroll itself chases a target"
        // above. Timed a little past the fold's own 0.2s so the last
        // frame always lands after the pane has finished growing, not
        // racing to beat it.
        let raf: number | null = null;
        const deadline = performance.now() + 260;

        const chase = () => {
            const maxScroll = Math.max(0, pane.scrollHeight - pane.clientHeight);
            pane.scrollTop = Math.min(next, maxScroll);

            if (performance.now() < deadline) {
                raf = requestAnimationFrame(chase);
            } else {
                raf = null;
            }
        };

        raf = requestAnimationFrame(chase);

        return () => {
            if (raf !== null) {
                cancelAnimationFrame(raf);
            }
        };
    }, [open]);

    return group;
}
