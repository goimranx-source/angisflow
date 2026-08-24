import { createPortal } from 'react-dom';

/**
 * Controls a click on the sheet should be handed on to, and ones it should not.
 *
 * Anything that does something when pressed is worth forwarding to. A flyout's
 * own trigger is not: the sheet has already closed the flyout by the time the
 * press is forwarded, so a trigger that toggles would open it straight back up
 * and the flyout would look stuck. `aria-expanded` and `aria-haspopup` are how
 * a trigger announces itself, and every one of ours carries them.
 */
const FORWARDABLE =
    'button, a[href], input, select, textarea, label, [role="button"], [role="menuitem"], [role="tab"], [tabindex]:not([tabindex="-1"])';

const IS_A_TRIGGER = '[aria-expanded], [aria-haspopup]';

/**
 * The sheet under an open flyout.
 *
 * ── Why a flyout needs one at all ────────────────────────────────────────────
 *
 * Most of these menus closed on a document-level `mousedown` listener and drew
 * nothing. That handles the closing, and nothing else: while the menu was open
 * the whole page stayed live underneath it, and every button in it still lit on
 * hover and still showed a hand as though the menu were not there.
 *
 * ── Why the click is forwarded rather than eaten ─────────────────────────────
 *
 * The obvious sheet swallows the click that closes the flyout. That is one line
 * of code and it makes the entire page cost two presses: one to dismiss, one to
 * do the thing you were reaching for. With a menu open, nothing on the screen
 * responds the first time you press it — which reads, correctly, as the page
 * being broken.
 *
 * So the sheet closes the flyout and then hands the press on to whatever was
 * underneath. One press, and the flyout still goes away. `elementFromPoint`
 * needs the sheet out of the way to see past it, and React has not unmounted it
 * yet at that point, so it is taken out of hit-testing for the one measurement.
 *
 * The cursor stays an arrow while the sheet is up. That is deliberate: it is
 * what tells you the menu is the thing in front, and that pressing anywhere
 * else dismisses it.
 *
 * ── Why it is portalled ──────────────────────────────────────────────────────
 *
 * `position: fixed` is measured against the viewport right up until an ancestor
 * has a transform, a filter, or `will-change` on it — then it is measured
 * against that ancestor instead, and a sheet meant to cover the screen covers
 * one card. Rendering into <body> means no ancestor can do that to it, and no
 * `overflow: hidden` on the way down can clip it.
 *
 * ── Why the layer is a prop ──────────────────────────────────────────────────
 *
 * The sheet has to sit directly under its own panel and above everything else,
 * and the panels are not all on one layer: a page's filter panel is above the
 * sidebar, and a row menu portalled out of a drawer is above the drawer. One
 * default covers the ordinary case; the rest say where they live.
 */
export function FlyoutBackdrop({
    onClose,
    layer = 'var(--z-flyout)',
}: {
    onClose: () => void;
    /** A CSS value for `z-index` — one less than the panel it sits under. */
    layer?: string;
}) {
    const dismiss = (event: React.MouseEvent<HTMLDivElement>) => {
        const sheet = event.currentTarget;
        const { clientX, clientY } = event;

        onClose();

        // Look past the sheet at what the press was actually aimed at. It is
        // still in the DOM at this point — React unmounts it on the next
        // render — so it has to stand aside for the one measurement.
        sheet.style.pointerEvents = 'none';
        const under = document.elementFromPoint(clientX, clientY);
        sheet.style.pointerEvents = '';

        // Element, not HTMLElement. Almost every control here is a button with
        // an icon in it, and an icon is an <svg> — so what comes back from
        // elementFromPoint is usually an SVGPathElement, which is an Element
        // and is not an HTMLElement. Testing for the narrower one bailed out
        // before forwarding on very nearly every press.
        if (!under) {
            return;
        }

        const target = under.closest<HTMLElement>(FORWARDABLE);

        // The control itself, not its surroundings. Checking ancestors too
        // would rule out every button that happens to sit inside a toolbar
        // with a menu somewhere in it — which is most of them.
        if (!target || target.matches(IS_A_TRIGGER)) {
            return;
        }

        /*
         * A field is entered, not pressed.
         *
         * `.click()` on a text box does nothing you can see: no caret, no
         * focus, no keyboard. So with a menu open the search box was the one
         * thing on the page that still took two presses — one to dismiss and
         * one to actually get into it — which is exactly what "the fields
         * aren't clickable" describes.
         *
         * A select needs the click as well as the focus: focus alone will not
         * drop its list open.
         */
        if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
            target.focus();

            return;
        }

        if (target instanceof HTMLSelectElement) {
            target.focus();
        }

        target.click();
    };

    return createPortal(
        <div
            className="flyout-backdrop fixed inset-0"
            style={{ zIndex: layer }}
            onClick={dismiss}
            /* Decorative. What it covers is still reachable by keyboard, and
               the menu above it closes on Escape. */
            aria-hidden
        />,
        document.body,
    );
}
