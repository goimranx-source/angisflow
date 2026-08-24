import { useEffect } from 'react';

/**
 * Marks that a flyout is open. Draws nothing.
 *
 * ── What this used to be, and why it is not that any more ────────────────────
 *
 * This was a full-screen sheet: a fixed div over the page, carrying
 * `cursor: default` so nothing underneath would go on advertising a press that
 * could not happen while a menu was in front.
 *
 * It worked, and it broke everything else. A sheet is the topmost element at
 * every point on the screen, so it is the sheet that gets hit-tested — not the
 * button under it. Nothing hovered. Nothing took a click. Text boxes could not
 * be typed in. Each of those got its own patch: forward the click to whatever
 * was underneath, then focus rather than click when the thing underneath is a
 * field, then work out which presses must not be forwarded because they belong
 * to the flyout's own trigger. Three workarounds for one element that should
 * not have been there.
 *
 * The tell was that flyouts in this app never had this problem before. They
 * close on a document-level press and leave the page alone, and a page that is
 * left alone behaves — hover, clicks, focus, cursors, all of it native, none of
 * it forwarded by hand.
 *
 * So: no sheet. This sets a class on <html> and the cursor rule lives in CSS,
 * which is a question about how things look and belongs there rather than in
 * the hit-testing.
 *
 * ── The dismissal ────────────────────────────────────────────────────────────
 *
 * Eleven of the fourteen flyouts using this already close on their own
 * document listener. The other three were relying on the sheet to catch the
 * click, so this offers the same thing: a press outside any `data-flyout-panel`
 * closes it. Presses on a trigger are left alone — the trigger toggles, and
 * closing it first would leave the flyout reopening under its own press.
 */

let openCount = 0;

function markOpen(): () => void {
    openCount += 1;
    document.documentElement.classList.add('flyout-open');

    return () => {
        openCount = Math.max(0, openCount - 1);

        // Only the last one out turns the light off. Nested flyouts — a menu
        // that opens a sub-menu — would otherwise clear the class on the way
        // out of the inner one and leave the outer one's page live again.
        if (openCount === 0) {
            document.documentElement.classList.remove('flyout-open');
        }
    };
}

export function FlyoutBackdrop({
    onClose,
    dismissOnOutsidePress = true,
}: {
    onClose: () => void;

    /**
     * Leave this off when the flyout already closes on its own listener.
     * Two listeners both calling onClose is harmless, but one of them not
     * knowing where the panel is would close it on its own contents.
     */
    dismissOnOutsidePress?: boolean;

    /**
     * Taken and ignored. It described which layer the sheet sat on, and there
     * is no sheet to place any more — kept so the call sites that pass it do
     * not have to be edited to say nothing.
     */
    layer?: string;
}) {
    useEffect(markOpen, []);

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

            onClose();
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
    }, [dismissOnOutsidePress, onClose]);

    return null;
}
