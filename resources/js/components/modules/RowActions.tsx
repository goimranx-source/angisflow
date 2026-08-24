import { type ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
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
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    /*
     * Placed from the trigger, then kept inside the window.
     *
     * ── Why clamping is not defensive padding ────────────────────────────────
     *
     * A table scrolls sideways, and the actions column is the part that goes
     * off the edge. Offsetting the menu from the trigger's right edge without
     * checking gives a negative distance the moment the trigger is past the
     * viewport — which pushes the menu further out rather than pulling it in,
     * and it opens somewhere nobody can see or click.
     */
    useLayoutEffect(() => {
        if (!open || !trigger.current) {
            return;
        }

        const rect = trigger.current.getBoundingClientRect();

        setAt({ top: rect.bottom + 4, right: Math.max(8, window.innerWidth - rect.right) });
    }, [open]);

    /*
     * And flipped above the trigger when there is no room beneath it, which is
     * every last row of every table.
     */
    useLayoutEffect(() => {
        if (!open || !at || !menu.current || !trigger.current) {
            return;
        }

        const box = menu.current.getBoundingClientRect();

        if (box.bottom <= window.innerHeight - 8) {
            return;
        }

        const above = Math.max(8, trigger.current.getBoundingClientRect().top - box.height - 4);

        if (above !== at.top) {
            setAt({ ...at, top: above });
        }
    }, [open, at]);

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
                className={cn(base, 'w-auto gap-0.5 px-1', tones.default)}
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen((was) => !was);
                }}
            >
                <Icon name={icon} size={15} />
                {/* The caret is what says a choice is coming. Without it this
                    looks like a button that does one thing and does another. */}
                <Icon name="caret-down" size={10} />
            </button>

            {open && (
                <FlyoutGuard onClose={() => setOpen(false)} dismissOnOutsidePress={false} />
            )}

            {open &&
                at &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        data-flyout-panel
                        style={{ top: at.top, right: at.right }}
                        className="fixed z-[var(--z-toast)] min-w-[10rem] overflow-hidden rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)] py-1 shadow-lg"
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {items.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition-colors',
                                    item.variant === 'danger'
                                        ? 'text-[var(--color-danger)] hover:bg-[var(--color-danger-subtle)]'
                                        : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
                                )}
                                onClick={() => {
                                    setOpen(false);
                                    item.onSelect();
                                }}
                            >
                                {item.icon && <Icon name={item.icon} size={15} />}
                                <span>{item.label}</span>
                            </button>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}
