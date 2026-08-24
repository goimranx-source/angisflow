import { createPortal } from 'react-dom';

/**
 * The sheet under an open flyout.
 *
 * ── Why a flyout needs one at all ────────────────────────────────────────────
 *
 * Most of these menus closed on a document-level `mousedown` listener and drew
 * nothing. That handles the closing, and nothing else: while the menu was open
 * the whole page stayed live underneath it. Every button still lit on hover and
 * still showed a hand, and the first click on any of them went to that button
 * rather than closing the menu. Two clicks to leave, and the first one did
 * something nobody asked for.
 *
 * A sheet fixes all three at once. It takes the click, so leaving costs one
 * press; it carries `cursor: default`, so nothing underneath claims to be
 * pressable; and it is what stops the click reaching the button in the first
 * place.
 *
 * The listener stays wherever it already exists — it is what catches a click
 * that lands outside the window entirely, and it is usually wired to Escape.
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
    return createPortal(
        <div
            className="flyout-backdrop fixed inset-0"
            style={{ zIndex: layer }}
            onClick={onClose}
            /* Decorative. The thing it covers is already reachable by keyboard,
               and the menu above it closes on Escape. */
            aria-hidden
        />,
        document.body,
    );
}
