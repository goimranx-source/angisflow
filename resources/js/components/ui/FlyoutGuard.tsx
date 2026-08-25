import { useEffect, useRef, type RefObject } from 'react';

/**
 * Keeps one flyout open at a time, and closes the ones that cannot close
 * themselves. Renders nothing.
 *
 * ── What this was, twice, and why it is neither now ──────────────────────────
 *
 * It began as a full-screen sheet: a fixed div over the page carrying
 * `cursor: default`, so nothing behind an open menu would go on showing a hand.
 *
 * A sheet is the topmost element at every point on the screen, so it is the
 * sheet that gets hit-tested rather than the button under it. Nothing hovered,
 * nothing took a click, text boxes could not be typed into. Each got its own
 * patch — forward the click, focus rather than click for a field, work out
 * which presses belong to a trigger — and hover could not be patched at all,
 * because there is nothing to forward a hover to.
 *
 * So the sheet went, and the cursor became two rules in the stylesheet: an
 * arrow on the page, a hand inside the panel. That left the page working and
 * still said the wrong thing. The arrow was only ever true while the sheet was
 * swallowing clicks. Without it every button behind a flyout works on the first
 * press — so an arrow over a working button is a lie about it, and a whole page
 * of them reads as an app that has seized up.
 *
 * What is left is the part that was doing real work.
 *
 * ── One at a time ────────────────────────────────────────────────────────────
 *
 * Every flyout closes on a press that lands outside itself, and a second
 * flyout's trigger is outside the first. That should have been enough, and was
 * not: the listener below has to ignore presses on triggers, or a toggle closes
 * on `pointerdown` and reopens on `click` and looks stuck. The skip cannot tell
 * a flyout's own trigger from somebody else's, so pressing a second trigger
 * left the first panel sitting there.
 *
 * Registering is the fix that covers every pair rather than the ones that
 * happened to work. Two menus open at once is not a state anybody asks for.
 *
 * ── Dismissal ────────────────────────────────────────────────────────────────
 *
 * Eleven of the fourteen already close on their own document listener and pass
 * `dismissOnOutsidePress={false}`; they are here for the exclusivity alone. The
 * other three were relying on the sheet to catch the click, and get it here: a
 * press outside any `[data-flyout-panel]` closes them.
 */

type Registration = { close: () => void; depth: number };

/**
 * What is open, outermost first.
 *
 * ── Why a stack and not a single flyout ──────────────────────────────────────
 *
 * It was one, and "whatever was open has been left behind" is true right up
 * until a flyout contains another one. A picker inside the filter panel is not
 * the filter panel's replacement, it is part of using it — and closing the
 * filter to show the picker's list took the list's own trigger off the screen
 * with it.
 *
 * So the rule is by depth rather than by recency. A flyout closes everything at
 * its own level and everything nested inside that, and leaves the ones it is
 * nested within alone.
 */
let stack: Registration[] = [];

/**
 * How many flyouts a control is inside.
 *
 * Read from the DOM at the moment of opening rather than passed down, because
 * nothing here knows what it will be rendered inside — the same filter select
 * appears on a toolbar and inside a flyout panel, and the answer differs.
 *
 * The anchor is the trigger rather than the panel, deliberately: panels are
 * rendered into the body to escape the things that would clip them, so a
 * panel's position in the markup says nothing about what it belongs to. Its
 * trigger has stayed where it was.
 */
function depthOf(anchor: HTMLElement | null): number {
    let depth = 0;

    for (
        let node = anchor?.parentElement ?? null;
        node !== null;
        node = node.parentElement
    ) {
        if (node.hasAttribute('data-flyout-panel')) {
            depth += 1;
        }
    }

    return depth;
}

function claimExclusive(close: () => void, anchor: HTMLElement | null): () => void {
    const me: Registration = { close, depth: depthOf(anchor) };

    /*
     * Peers and anything they contain, closed. Ancestors kept.
     *
     * Two menus open side by side is not a state anybody asks for; a menu open
     * inside the panel that offered it is the only way to use that panel.
     */
    for (const other of stack.filter((one) => one.depth >= me.depth)) {
        other.close();
    }

    stack = [...stack.filter((one) => one.depth < me.depth), me];

    return () => {
        // Only ourselves. When one flyout replaces another, React can run the
        // outgoing effect's cleanup after the incoming one's setup — clearing
        // more than our own entry would forget the flyout on screen.
        stack = stack.filter((one) => one !== me);
    };
}

export function FlyoutGuard({
    onClose,
    dismissOnOutsidePress = true,
    anchor,
}: {
    onClose: () => void;

    /**
     * The control that opened this, when it might itself be inside a flyout.
     *
     * Without it a flyout counts as outermost, which is right for the fourteen
     * that are and wrong for a picker in a filter panel — see depthOf.
     */
    anchor?: RefObject<HTMLElement | null>;

    /**
     * Leave this off when the flyout already closes on its own listener.
     * Two listeners both calling onClose is harmless, but one of them not
     * knowing where the panel is would close it on its own contents.
     */
    dismissOnOutsidePress?: boolean;
}) {
    // Kept in a ref so claiming exclusivity does not re-run every time the
    // parent re-renders and hands down a fresh onClose — which would close the
    // flyout that is currently open, namely this one.
    const close = useRef(onClose);
    close.current = onClose;

    useEffect(
        () => claimExclusive(() => close.current(), anchor?.current ?? null),
        // Once, when it opens. The anchor does not move while it is open, and
        // re-claiming on every render would close the flyout that is open —
        // this one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    useEffect(() => {
        if (!dismissOnOutsidePress) {
            return;
        }

        const onPress = (event: PointerEvent) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            // Inside the panel, or on the control that opened it.
            if (
                target.closest('[data-flyout-panel]') ||
                target.closest('[aria-expanded], [aria-haspopup]')
            ) {
                return;
            }

            close.current();
        };

        // The next tick, so the press that opened this does not immediately
        // close it — that press is still travelling when this effect runs.
        const armed = window.setTimeout(
            () => document.addEventListener('pointerdown', onPress),
            0,
        );

        return () => {
            window.clearTimeout(armed);
            document.removeEventListener('pointerdown', onPress);
        };
    }, [dismissOnOutsidePress]);

    return null;
}
