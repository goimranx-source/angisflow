import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

/**
 * The menu behind the bulk action bar.
 *
 * ── Why not the select it replaces ───────────────────────────────────────────
 *
 * A native <select> full of <optgroup>s was carrying thirty-odd actions across
 * six categories, and it made all of them look identical: "Move to Trash" and
 * "Processing" were the same grey line of text, one scroll apart. Nothing could
 * be marked destructive, nothing could carry an icon, and nothing could explain
 * itself — so the only defence against picking the wrong one was reading
 * carefully, every time, on an action that applies to everything selected.
 *
 * It also took two steps and a rule nobody was told: choose, then find Apply.
 * Choosing and doing are one gesture here.
 *
 * ── Why it opens upward ──────────────────────────────────────────────────────
 *
 * Because the bar it belongs to is pinned to the bottom of the window, so there
 * is never room beneath it. It flips down only if that is somehow the side with
 * space, which is the same rule the row menus use, applied to the opposite
 * default.
 */

export type BulkActionItem = {
    key: string;
    label: string;
    icon?: string;
    /** One short line, for actions whose name does not say enough. */
    description?: string;
    variant?: 'default' | 'danger';
    disabled?: boolean;
    onSelect: () => void;
};

export type BulkActionGroup = {
    /**
     * What the group is for, or nothing.
     *
     * Left off for a group that is a pair of plain actions rather than a set of
     * choices. "Cancel orders" and "Move to trash" under a heading reading
     * "Manage" is a heading explaining two verbs that already explain
     * themselves — and it makes the menu look like it has one more section than
     * it has decisions in it.
     */
    label?: string;
    icon?: string;
    items: BulkActionItem[];
};

export function BulkActionsMenu({
    label,
    icon = 'list',
    groups,
    heading,
    disabled = false,
    busy = false,
}: {
    label: string;
    icon?: string;
    groups: BulkActionGroup[];

    /**
     * What the menu will act on, held at the top while the list scrolls.
     *
     * ── Why not the first group's label ──────────────────────────────────────
     *
     * A heading inside the scrolling list is gone by the time somebody reaches
     * the bottom of it — which is where the destructive actions are. The thing
     * that says "this is what you are about to change" cannot be the thing that
     * scrolls away.
     */
    heading?: string;
    disabled?: boolean;
    busy?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [at, setAt] = useState<{ left: number; top: number } | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    /*
     * Placed above the trigger, then kept inside the window.
     *
     * Measured after the menu exists rather than guessed from the item count:
     * the height depends on how many groups this tab offers and whether their
     * items carry descriptions, which is not knowable before layout.
     */
    useLayoutEffect(() => {
        if (!open || !trigger.current || !menu.current) {
            return;
        }

        const anchor = trigger.current.getBoundingClientRect();
        const box = menu.current.getBoundingClientRect();

        const above = anchor.top - box.height - 6;
        const below = anchor.bottom + 6;

        const top = above >= 8 ? above : Math.min(below, window.innerHeight - box.height - 8);

        // Clamped so a bar near the edge of a narrow window cannot push its own
        // menu off the side.
        const left = Math.max(8, Math.min(anchor.left, window.innerWidth - box.width - 8));

        if (at === null || at.top !== top || at.left !== left) {
            setAt({ top, left });
        }
    }, [open, at, groups]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = () => setOpen(false);
        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);

        window.addEventListener('resize', close);
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', onKey);

        return () => {
            window.removeEventListener('resize', close);
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    /*
     * The destructive actions are lifted out of their groups.
     *
     * They are the ones somebody must always be able to reach and must never
     * reach by accident, and in a list this long they were both: below the fold,
     * and arriving under a finger that was already scrolling. Out of the
     * scrolling part entirely, at the foot, where a last resort belongs.
     */
    const shown = groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.variant !== 'danger'),
        }))
        .filter((group) => group.items.length > 0);

    const dangerous = groups.flatMap((group) =>
        group.items.filter((item) => item.variant === 'danger'),
    );

    return (
        <>
            <button
                ref={trigger}
                type="button"
                disabled={disabled || busy}
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={(event) => {
                    event.stopPropagation();
                    setAt(null);
                    setOpen((was) => !was);
                }}
                className="inline-flex h-8 items-center gap-2 rounded-[var(--shell-radius-sm)] border border-[var(--shell-border)] bg-[var(--color-card-bg)] px-3 text-sm font-medium text-[var(--color-text-body)] transition-colors hover:bg-[var(--shell-hover)] disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Icon name={busy ? 'circle-notch' : icon} size={15} className={busy ? 'animate-spin' : undefined} />
                <span>{busy ? 'Applying…' : label}</span>
                <Icon name="caret-down" size={11} />
            </button>

            {open && (
                <FlyoutGuard onClose={() => setOpen(false)} dismissOnOutsidePress={false} />
            )}

            {open &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        data-flyout-panel
                        style={{
                            top: at?.top ?? -9999,
                            left: at?.left ?? -9999,
                            // Hidden for the single frame before it is measured,
                            // so nobody sees it land in one place and jump.
                            visibility: at === null ? 'hidden' : 'visible',
                            borderColor: 'var(--shell-border)',

                            /* The same arrival every other panel in the
                               application has. This one appeared outright. */
                            animation: 'context-flyout-slide-up 120ms ease-out',
                            transformOrigin: 'bottom left',
                        }}
                        className="fixed z-[var(--z-toast)] flex max-h-[60vh] w-64 flex-col overflow-hidden rounded-[var(--shell-radius)] border bg-[var(--color-card-bg)] shadow-lg"
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {/*
                          What the menu acts on, held while the list moves.

                          This opens from a bar floating over the table, so the
                          rows it will change are often hidden behind it — and
                          the list is long enough that anything said inside it
                          is gone by the time somebody reaches the bottom.
                        */}
                        {heading && (
                            <p
                                className="shrink-0 border-b px-3 py-2 text-xs font-semibold text-[var(--color-text-main)]"
                                style={{ borderColor: 'var(--shell-border)' }}
                            >
                                {heading}
                            </p>
                        )}

                        <div className="min-h-0 flex-1 overflow-y-auto py-1">
                        {shown.map((group, index) => (
                            <div
                                key={group.label ?? `group-${index}`}
                                className={cn(
                                    index > 0 && 'mt-1 border-t pt-1',
                                )}
                                style={
                                    index > 0
                                        ? { borderColor: 'var(--shell-border)' }
                                        : undefined
                                }
                            >
                                {/*
                                  A heading you can actually see.

                                  11px, uppercase, wide-tracked and grey is the
                                  style of something trying not to be read --
                                  which is the wrong instruction for the only
                                  words in the menu that say what the choices
                                  below them are. Same size as the items, darker
                                  than them, and no tracking: a heading, not a
                                  watermark.
                                */}
                                {group.label && (
                                    <p className="flex items-center gap-2 px-3 pb-1 pt-2 text-xs font-semibold text-[var(--color-text-main)]">
                                        {group.icon && (
                                            <Icon
                                                name={group.icon}
                                                size={13}
                                                weight="duotone"
                                                className="shrink-0 opacity-60"
                                            />
                                        )}
                                        {group.label}
                                    </p>
                                )}

                                {group.items.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        role="menuitem"
                                        disabled={item.disabled}
                                        onClick={() => {
                                            setOpen(false);
                                            item.onSelect();
                                        }}
                                        /*
                                          Every item indented the same, whether
                                          it has an icon or not.

                                          The icon slot is always drawn, so a
                                          group whose items have no icons still
                                          starts its words where the ones above
                                          it start theirs. Without it the menu
                                          has two left margins and reads as two
                                          menus stacked.

                                          Indented past the heading, and the
                                          icon indented with it.

                                          Aligning the text alone was not
                                          enough: an item's icon then shared its
                                          left edge with the heading's, so a
                                          group whose items have icons -- the
                                          documents, the couriers -- read as
                                          un-indented beside a group whose
                                          items have none. The row moves, not
                                          just the words in it.
                                        */
                                        className={cn(
                                            'flex w-full items-center gap-2 py-1.5 pl-6 pr-3 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                            item.variant === 'danger'
                                                ? 'hover:bg-[var(--color-danger-subtle)]'
                                                : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
                                        )}
                                        style={
                                            item.variant === 'danger'
                                                ? { color: 'var(--color-danger-text)' }
                                                : undefined
                                        }
                                    >
                                        <span className="flex w-4 shrink-0 justify-center">
                                            {item.icon && (
                                                <Icon
                                                    name={item.icon}
                                                    size={15}
                                                    weight="duotone"
                                                    className="opacity-70"
                                                />
                                            )}
                                        </span>
                                        <span className="min-w-0 truncate">{item.label}</span>
                                    </button>
                                ))}
                            </div>
                                ))}
                        </div>

                        {/*
                          The last resorts, outside the scroll.

                          A menu this long put the delete below the fold and
                          under a finger that was already moving. Held at the
                          foot it is always one press away and never arrives
                          under a scroll — and the rule above it says these two
                          are not more of the same list.
                        */}
                        {dangerous.length > 0 && (
                            <div
                                className="shrink-0 border-t py-1"
                                style={{ borderColor: 'var(--shell-border)' }}
                            >
                                {dangerous.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        role="menuitem"
                                        disabled={item.disabled}
                                        onClick={() => {
                                            setOpen(false);
                                            item.onSelect();
                                        }}
                                        className="flex w-full items-center gap-2 py-1.5 pl-6 pr-3 text-left text-sm transition-colors hover:bg-[var(--color-danger-subtle)] disabled:cursor-not-allowed disabled:opacity-40"
                                        style={{ color: 'var(--color-danger-text)' }}
                                    >
                                        <span className="flex w-4 shrink-0 justify-center">
                                            {item.icon && (
                                                <Icon
                                                    name={item.icon}
                                                    size={15}
                                                    weight="duotone"
                                                    className="opacity-70"
                                                />
                                            )}
                                        </span>
                                        <span className="min-w-0 truncate">{item.label}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
