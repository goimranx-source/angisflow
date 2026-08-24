import { type ReactNode, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { useFlyoutPosition } from '@/hooks/useFlyoutPosition';
import { cn } from '@/lib/utils';

/**
 * The controls at the end of a table row.
 *
 * ── Why a component and not markup per table ─────────────────────────────────
 *
 * Because every list in this application ends in the same column and every one
 * of them was inventing it: a text link here, a word there, a button somewhere
 * else. That is not only untidy — it means the destructive action looks
 * different on each screen, so the one control people most need to recognise
 * before clicking is the one with no consistent shape.
 *
 * Written once, a row's actions are icons of one size, in one order, with the
 * dangerous one always last and always the only red thing in the row.
 *
 * ── Why icons rather than words ──────────────────────────────────────────────
 *
 * A table row is a horizontal budget and the actions column is the part that
 * loses. Words cost four or five times the width, which either squeezes the
 * data or pushes the column off the side of a scrolling table — where the
 * actions cannot be reached at all without scrolling past everything else.
 *
 * Every icon carries a title and an aria-label, so the word is one hover or one
 * screen reader away.
 */

type Variant = 'default' | 'danger';

const base =
    'inline-flex h-7 w-7 items-center justify-center rounded-[var(--shell-radius-sm)] transition-colors disabled:opacity-40';

const tones: Record<Variant, string> = {
    default:
        'text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]',

    /*
     * Muted until reached for.
     *
     * A row of permanently red buttons turns the whole table into a warning and
     * stops meaning anything by the second screen. Red on hover puts the colour
     * exactly where it does its job — under the cursor, the moment before the
     * click.
     */
    danger: 'text-[var(--color-text-muted)] hover:bg-[var(--color-danger-subtle)] hover:text-[var(--color-danger)]',
};

/** The row of them. Keeps spacing and alignment identical across tables. */
export function RowActions({ children }: { children: ReactNode }) {
    return <div className="flex items-center justify-end gap-0.5">{children}</div>;
}

export function RowAction({
    icon,
    label,
    onClick,
    variant = 'default',
    disabled,
}: {
    icon: string;
    label: string;
    onClick: () => void;
    variant?: Variant;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            className={cn(base, tones[variant])}
            onClick={(event) => {
                // Rows open a drawer when clicked. Without this, every action
                // also opens the thing it was meant to act on.
                event.stopPropagation();
                onClick();
            }}
        >
            <Icon name={icon} size={15} />
        </button>
    );
}

/**
 * One line of a row's menu.
 *
 * Its own component because the menu now draws its items in two places — the
 * part that scrolls and the part that does not — and two copies of a button
 * are two things to keep in step.
 */
function MenuItem({ item, onPick }: { item: RowMenuItem; onPick: () => void }) {
    return (
        <button
            type="button"
            role="menuitem"
            className={cn(
                'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition',
                item.variant === 'danger'
                    ? 'hover:bg-[var(--color-danger-subtle)]'
                    : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
            )}
            style={item.variant === 'danger' ? { color: 'var(--color-danger-text)' } : undefined}
            onClick={onPick}
        >
            {item.icon && (
                <Icon name={item.icon} size={15} weight="duotone" className="shrink-0 opacity-70" />
            )}
            <span className="truncate">{item.label}</span>
        </button>
    );
}

export type RowMenuItem = {
    key: string;
    label: string;
    icon?: string;
    variant?: Variant;
    onSelect: () => void;
};

/**
 * An action that asks which one first.
 *
 * ── Why this menu is not the shared Dropdown ─────────────────────────────────
 *
 * That one positions itself absolutely inside its parent, which is right for a
 * toolbar and wrong here: a table sits in a card with clipped corners and
 * scrolls sideways, so a menu belonging to a row is cut off at the card's edge
 * — and the last row's menu is cut off by the bottom of it. Rendering into the
 * body escapes both, at the cost of having to place it by hand.
 */
export function RowActionMenu({
    icon,
    label,
    items,
}: {
    icon: string;
    label: string;
    items: RowMenuItem[];
}) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    /*
     * Placed by the shared hook, like every other flyout.
     *
     * ── What this replaces ───────────────────────────────────────────────────
     *
     * Two layout effects: one to drop the menu below the trigger, and a second
     * to notice it had gone off the bottom and move it above. That is the same
     * job useFlyoutPosition does for the filter panel, the date picker and the
     * storefront's own row menu — and doing it twice meant this menu flipped on
     * slightly different rules from the others, and never learned the rest:
     * it did not cap its own height when neither side fitted, and it did not
     * re-place itself when the table scrolled under it.
     */
    const at = useFlyoutPosition({ open, trigger, panel: menu });

    /*
     * Split so the dangerous ones can sit still while the rest scroll.
     *
     * By variant rather than by position, because a caller listing a delete
     * in the middle means it to be a delete, not the fourth item.
     */
    const dangerous = items.filter((item) => item.variant === 'danger');
    const ordinary = items.filter((item) => item.variant !== 'danger');

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = () => setOpen(false);
        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);

        // Closed rather than followed on scroll: a menu anchored to a row that
        // has moved is pointing at the wrong record, which is worse than a menu
        // that went away.
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', onKey);

        return () => {
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <>
            <button
                ref={trigger}
                type="button"
                title={label}
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                /*
                  Boxed, because a bare glyph is not obviously a button.

                  Three dots on their own read as a decoration or a truncation
                  mark until somebody happens to hover them. An outline says
                  "press this" without a label, which is the whole job of an
                  icon-only control.

                  No caret beside it. It was there to say a choice was coming,
                  which three dots already say — and it made this menu the one
                  control in the application that ended a row differently from
                  the storefront list's.
                */
                className="rounded-[var(--shell-radius-sm)] border p-1.5 text-[var(--color-text-muted)] transition hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                style={{ borderColor: 'var(--shell-border)' }}
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen((was) => !was);
                }}
            >
                <Icon name={icon} size={16} />
            </button>

            {open && (
                <FlyoutGuard onClose={() => setOpen(false)} dismissOnOutsidePress={false} />
            )}

            {/*
              Rendered as soon as it is open, not once it has been placed.

              Waiting for `at` cannot work: the hook measures the panel to
              decide where to put it, so the panel has to exist first. Gated on
              `at` it never rendered, so it was never measured, so `at` stayed
              null — the menu simply did not open. It renders hidden for the one
              frame that takes instead; the measuring happens in a layout
              effect, before the browser paints, so that frame is never seen.
            */}
            {open &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        data-flyout-panel
                        style={{
                            /*
                              Rising into place, briefly.

                              A menu that simply exists on the next frame leaves
                              the reader to work out where it came from; one that
                              rises from its button says so. 120ms — long enough
                              to be seen, short enough that nobody waits.
                            */
                            animation: 'context-flyout-slide-up 120ms ease-out',
                            transformOrigin:
                                at?.side === 'above' ? 'bottom right' : 'top right',

                            // Hidden for the frame it spends being measured: it
                            // has to be in the document to have a height, and
                            // cannot be placed without one.
                            visibility: at ? 'visible' : 'hidden',
                            top: at?.top ?? 0,
                            left: at?.left ?? 0,

                            /*
                             * Whichever is smaller: what the window has room
                             * for, or the height at which the list stops being
                             * a menu and starts being a page.
                             *
                             * Both are needed. The hook's figure keeps it on
                             * screen; the fixed one keeps it the same shape
                             * from row to row, which is what makes an item's
                             * position learnable.
                             */
                            maxHeight: Math.min(at?.maxHeight ?? 320, 320),
                        }}
                        className="fixed z-[var(--z-toast)] flex w-44 flex-col overflow-hidden rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)] shadow-lg"
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {/*
                          ── The list scrolls; the destructive item does not ──────

                          A row can carry three actions or eight, depending on
                          whether it has a shipment to call off and which tab it
                          is on, so the menu was a different height every time —
                          and on the longest it ran past the bottom of the
                          window with the delete somewhere below the fold.

                          Capped and scrolled, it is the same shape every time.
                          The one item somebody must always be able to reach and
                          must never reach by accident is the one that stays put,
                          so it is out of the scrolling part: never hidden, and
                          never arriving under a finger that was scrolling.
                        */}
                        <div className="min-h-0 flex-1 overflow-y-auto py-1">
                            {ordinary.map((item) => (
                                <MenuItem
                                    key={item.key}
                                    item={item}
                                    onPick={() => {
                                        setOpen(false);
                                        item.onSelect();
                                    }}
                                />
                            ))}
                        </div>

                        {dangerous.length > 0 && (
                            <div
                                className="shrink-0 border-t py-1"
                                style={{ borderColor: 'var(--shell-border)' }}
                            >
                                {dangerous.map((item) => (
                                    <MenuItem
                                        key={item.key}
                                        item={item}
                                        onPick={() => {
                                            setOpen(false);
                                            item.onSelect();
                                        }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
