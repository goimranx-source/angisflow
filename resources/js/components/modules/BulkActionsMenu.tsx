import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

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
    label: string;
    icon?: string;
    items: BulkActionItem[];
};

export function BulkActionsMenu({
    label,
    icon = 'list',
    groups,
    disabled = false,
    busy = false,
}: {
    label: string;
    icon?: string;
    groups: BulkActionGroup[];
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

            {open &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        style={{
                            top: at?.top ?? -9999,
                            left: at?.left ?? -9999,
                            // Hidden for the single frame before it is measured,
                            // so nobody sees it land in one place and jump.
                            visibility: at === null ? 'hidden' : 'visible',
                        }}
                        className="fixed z-[var(--z-toast)] max-h-[70vh] w-72 overflow-y-auto rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)] py-1 shadow-lg"
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {groups.map((group, index) => (
                            <div
                                key={group.label}
                                className={cn(
                                    index > 0 && 'mt-1 border-t border-[var(--color-border-light)] pt-1',
                                )}
                            >
                                <p className="flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                                    {group.icon && <Icon name={group.icon} size={12} />}
                                    {group.label}
                                </p>

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
                                        className={cn(
                                            'flex w-full items-start gap-2.5 px-3 py-1.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                            item.variant === 'danger'
                                                ? 'text-[var(--color-danger)] hover:bg-[var(--color-danger-subtle)]'
                                                : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
                                        )}
                                    >
                                        <span className="mt-0.5 w-4 shrink-0">
                                            {item.icon && <Icon name={item.icon} size={15} />}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-sm">{item.label}</span>
                                            {item.description && (
                                                <span className="mt-0.5 block text-xs text-[var(--color-text-muted)]">
                                                    {item.description}
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}
