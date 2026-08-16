import { useEffect, useRef } from 'react';

/**
 * The brief flash when the pointer first arrives — long enough to be seen,
 * short enough not to linger. This one is only announcing that the pane
 * scrolls; nobody is reaching for the bar yet.
 */
const HOVER_FLASH = 500;

/**
 * How long the bar stays up after scrolling stops. Longer than the flash on
 * purpose: here somebody *might* be about to grab it, and this is the time
 * they get to move onto it before it goes.
 */
const SCROLL_GRACE = 1200;

/**
 * How far short of the gutter still counts as reaching for the bar. Without
 * some room here the bar would vanish in the last few pixels before the
 * hand arrives, which is the one moment it must not.
 */
const GRAB_ZONE_PADDING = 6;

/**
 * A scrollbar that is only there when it is useful.
 *
 * ── Four moments, and they are not interchangeable ───────────────────────────
 *
 * Arriving over the pane flashes the bar briefly and lets it go, still under
 * the pointer — it is saying "this scrolls", nothing more. A bar that stayed
 * lit for as long as a pointer rests over the rail would be a permanent bar
 * in practice, since that is most of the time anybody is reading the menu.
 *
 * Scrolling shows it again and holds it noticeably longer, because that is
 * the moment somebody might actually reach for it. A wheel gesture ends with
 * the pointer wherever it already was rather than on the bar, so there is no
 * event to react to — the delay is simply the hand's time to arrive.
 *
 * Reaching for the bar pins it up with no timer at all. Nothing about a
 * countdown makes sense once the pointer is on the thing itself: it has to
 * still be there to be grabbed and dragged, for as long as that takes.
 *
 * Leaving is the one signal that is not "still busy, hold it a little
 * longer". It is a real edge with nothing left to be in the middle of, so it
 * hides at once and lets the CSS transition supply the fade.
 *
 * ── Why the pin survives the pointer being over the bar ──────────────────────
 *
 * Browsers stop firing pointermove on an element once the pointer crosses
 * onto its native scrollbar, so there is no event that says "now on the
 * bar" to react to. What there is, is the last move *before* that — which
 * lands inside the gutter's own width plus GRAB_ZONE_PADDING. Pinning on
 * that reading means the bar is already held by the time the events stop,
 * and it stays held until a later move lands back in the content or the
 * pointer leaves the pane outright.
 */
export function useScrollReveal<T extends HTMLElement>() {
    const ref = useRef<T | null>(null);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return undefined;
        }

        let hideTimer: number | null = null;
        /** Pointer is on the scrollbar, or close enough to be going for it. */
        let onBar = false;

        const clearHideTimer = () => {
            if (hideTimer !== null) {
                window.clearTimeout(hideTimer);
                hideTimer = null;
            }
        };

        const hide = () => {
            // Whatever armed this, it does not get to take the bar out from
            // under a hand that is on it — dragging fires scroll events of
            // its own, and each one arms a fresh timer.
            if (onBar) {
                return;
            }

            clearHideTimer();
            el.classList.remove('is-scroll-visible');
        };

        /** Show it, then hide again after `ms` unless something shows it anew. */
        const showFor = (ms: number) => {
            el.classList.add('is-scroll-visible');
            clearHideTimer();
            hideTimer = window.setTimeout(hide, ms);
        };

        /** Show it and leave it, with nothing scheduled to take it away. */
        const pin = () => {
            el.classList.add('is-scroll-visible');
            clearHideTimer();
        };

        const isReachingForBar = (event: PointerEvent) => {
            if (el.scrollHeight <= el.clientHeight) {
                return false;
            }

            // The gutter's real width, whatever the platform makes it —
            // 0 if this is an overlay scrollbar taking no layout space, in
            // which case the padding alone is the zone.
            const gutter = Math.max(0, el.offsetWidth - el.clientWidth);

            return event.clientX >= el.getBoundingClientRect().right - gutter - GRAB_ZONE_PADDING;
        };

        const onEnter = () => showFor(HOVER_FLASH);
        const onScroll = () => showFor(SCROLL_GRACE);

        const onMove = (event: PointerEvent) => {
            const reaching = isReachingForBar(event);

            // Only the crossings matter. Acting on every move would relight
            // the bar continuously while the pointer wanders the content,
            // which is the behaviour the arrival flash exists to avoid.
            if (reaching === onBar) {
                return;
            }

            onBar = reaching;

            if (reaching) {
                pin();
            } else {
                showFor(SCROLL_GRACE);
            }
        };

        const onLeave = () => {
            onBar = false;
            hide();
        };

        // pointer* rather than mouse*: both behave the same for a mouse, but
        // only these also fire for touch and pen input.
        el.addEventListener('scroll', onScroll, { passive: true });
        el.addEventListener('pointerenter', onEnter);
        el.addEventListener('pointermove', onMove, { passive: true });
        el.addEventListener('pointerleave', onLeave);

        return () => {
            el.removeEventListener('scroll', onScroll);
            el.removeEventListener('pointerenter', onEnter);
            el.removeEventListener('pointermove', onMove);
            el.removeEventListener('pointerleave', onLeave);
            clearHideTimer();
        };
    }, []);

    return ref;
}
