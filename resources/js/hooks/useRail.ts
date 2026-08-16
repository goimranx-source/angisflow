import { useCallback, useEffect, useRef, useState } from 'react';

import { getCookie, setCookie } from '@/lib/utils';

const COOKIE = 'prism_rail';
const COLLAPSED = 'collapsed';

/**
 * How long the rail stays peeked after the pointer leaves it.
 *
 * Only long enough to absorb the pointer clipping the rail's edge for a frame
 * on its way past — not long enough to be felt. At 160ms it read as the rail
 * thinking about it: the pointer left, nothing happened, and *then* the labels
 * went and the rail shrank, which makes two things that should look like one
 * movement look like two events. Below about 80ms the pause stops registering
 * and the collapse reads as the answer to leaving rather than as something
 * that follows it.
 *
 * It can be this short because the case that needed a long grace — the pointer
 * crossing into the business switcher's panel, which was portaled onto the body
 * and therefore counted as leaving the rail — no longer exists: that picker is
 * in the header now, and nothing else inside the rail escapes it.
 */
const PEEK_GRACE = 60;

/**
 * Whether the sidebar is collapsed to a rail.
 *
 * ── Why a cookie and why the class is on <html> ──────────────────────────────
 *
 * The initial value is read from the document element, not from storage — and
 * the class is already there, put on by an inline script in <head> before any
 * CSS was parsed (see app.blade.php). That ordering is the entire point: read
 * it in React instead and the rail is painted open, the bundle loads, and the
 * rail snaps shut. A visible jump on every cold load for every user who
 * collapsed it.
 *
 * localStorage cannot help, because it is not readable until scripts run, which
 * is the moment that is already too late. A cookie is in the document from the
 * first byte.
 *
 * The width itself is a CSS variable keyed off that class, so the sidebar and
 * the page beside it can never disagree about how wide it is.
 *
 * ── Peeking ──────────────────────────────────────────────────────────────────
 *
 * A collapsed rail widens under the pointer and shrinks again when it leaves,
 * as an overlay — the page keeps its narrow gutter and the rail comes over the
 * top of it. That replaces the floating panel a collapsed department used to
 * open beside itself, which had to reimplement the menu a second time in a
 * second place with its own positioning, its own hover grace and its own set
 * of styles to keep in step.
 *
 * Two states rather than one, and the distinction is the point:
 *
 *   collapsed  what the user chose. Persisted, and what the control toggles.
 *   narrow     whether it is *drawn* narrow right now — collapsed and not
 *              being peeked. Everything to do with appearance reads this one.
 *
 * ── Why closing is not its own phase ─────────────────────────────────────────
 *
 * It was, briefly: the wide contents were held in place while the width shrank
 * out from under them, so the rail's edge would wipe the labels away. It looked
 * right in theory and shook in practice, because the two layouts do not line
 * up. A wide row is `padding: 7px 5px`, so its 40px icon sits five pixels
 * inside the row; a narrow row is `width: 40px; padding: 0` and the icon sits
 * flush. Holding the wide layout through the animation therefore ended with
 * every icon jumping 5px sideways at the exact moment the movement stopped —
 * and a small late snap, after everything has come to rest, is the most
 * visible kind there is.
 *
 * So the contents change at once and the width animates underneath them, which
 * is what the toggle button has always done and why that path never shook. The
 * geometry settles in the first frame and only the edge moves after that.
 */
export function useRail() {
    const [collapsed, setCollapsed] = useState<boolean>(() => {
        if (typeof document === 'undefined') {
            return false;
        }

        return document.documentElement.classList.contains('rail-collapsed');
    });

    const [peeking, setPeeking] = useState(false);

    const rail = useRef<HTMLElement | null>(null);
    const leaveTimer = useRef<number | null>(null);

    const narrow = collapsed && !peeking;

    // Kept in step for the case the value changed in another tab.
    useEffect(() => {
        const stored = getCookie(COOKIE) === COLLAPSED;

        if (stored !== collapsed) {
            document.documentElement.classList.toggle('rail-collapsed', collapsed);
        }
    }, [collapsed]);

    // On <html> beside rail-collapsed rather than on the element, because the
    // rules it drives are written against the same root the width is.
    useEffect(() => {
        document.documentElement.classList.toggle('rail-peek', collapsed && peeking);
    }, [collapsed, peeking]);

    useEffect(
        () => () => {
            if (leaveTimer.current !== null) {
                window.clearTimeout(leaveTimer.current);
            }

            document.documentElement.classList.remove('rail-peek');
        },
        [],
    );

    /** Stop a close that has been scheduled but has not fired yet. */
    const cancelClose = useCallback(() => {
        if (leaveTimer.current !== null) {
            window.clearTimeout(leaveTimer.current);
            leaveTimer.current = null;
        }
    }, []);

    const openPeek = useCallback(() => {
        // A phone has no pointer to hover with, and the rail there is already
        // a full-width overlay driven by the menu button.
        if (window.innerWidth < 880) {
            return;
        }

        // Turning back before the grace period is up leaves the rail exactly
        // where it was — and turning back after it has started closing picks
        // the width up from its current computed value, because it is a
        // transition rather than a keyframed animation.
        cancelClose();
        setPeeking(true);
    }, [cancelClose]);

    /**
     * A short grace period, then close.
     *
     * The grace is because the pointer crosses the rail's edge for a frame on
     * the way to something inside it, and a rail that snapped shut on that
     * would be unusable. After that the contents change and the width animates
     * underneath them — see the note at the top about why the reverse order
     * shook.
     */
    const closePeek = useCallback(() => {
        if (leaveTimer.current !== null) {
            window.clearTimeout(leaveTimer.current);
        }

        leaveTimer.current = window.setTimeout(() => setPeeking(false), PEEK_GRACE);
    }, []);

    const toggle = useCallback(() => {
        setCollapsed((was) => {
            const next = !was;

            document.documentElement.classList.toggle('rail-collapsed', next);
            setCookie(COOKIE, next ? COLLAPSED : 'open');

            return next;
        });

        cancelClose();
        setPeeking(false);
    }, [cancelClose]);

    return { collapsed, narrow, toggle, rail, openPeek, closePeek };
}

/**
 * The departments somebody pinned to the top of the menu.
 *
 * A tool with forty pages has perhaps five that one person opens all day, and
 * which five is not something the menu can know. Kept in localStorage rather
 * than on the server: it is a per-device preference, it changes often, and
 * round-tripping it would mean a write to the users table every time somebody
 * pins something.
 */
const PINS_KEY = 'prism.nav.pins';

export function usePins() {
    const [pins, setPins] = useState<string[]>(() => {
        if (typeof window === 'undefined') {
            return [];
        }

        try {
            const raw = window.localStorage.getItem(PINS_KEY);
            const parsed: unknown = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.filter((v): v is string => typeof v === 'string') : [];
        } catch {
            return [];
        }
    });

    const toggle = useCallback((key: string) => {
        setPins((current) => {
            const next = current.includes(key)
                ? current.filter((k) => k !== key)
                : [...current, key];

            try {
                window.localStorage.setItem(PINS_KEY, JSON.stringify(next));
            } catch {
                // Private browsing, or storage full. Pinning silently not
                // persisting is a far better outcome than the sidebar throwing.
            }

            return next;
        });
    }, []);

    return { pins, toggle };
}
