import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { ManageTagsModal } from '@/components/organiser/ManageTagsModal';
import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { Icon } from '@/components/ui/Icon';
import { confirm } from '@/lib/confirm';
import { organiserKey, useOrganiser, useOrganiserActions, type ItemType } from '@/hooks/useOrganiser';
import { cn } from '@/lib/utils';

/**
 * The three-dot menu on a row.
 *
 * ── Why it is portalled and measured ─────────────────────────────────────────
 *
 * Rows live inside cards with `overflow: hidden`, so a menu positioned inside
 * one is a menu clipped by it. Rendering to the body avoids that, and means the
 * position has to be worked out rather than inherited — including which way to
 * open, because a row near the bottom of the window has no room below it.
 */
export function ItemMenu({
    type,
    id,
    name,
    holds,
    onDelete,
    onEdit,
}: {
    type: ItemType;
    id: string;
    name: string;
    /** For a workspace: how many businesses go with it. Drawn in the warning. */
    holds?: number;
    onDelete: () => void;
    onEdit?: () => void;
}) {
    const { data } = useOrganiser();
    const { setFavourite, assignTag } = useOrganiserActions();
    const [open, setOpen] = useState(false);
    const [managing, setManaging] = useState(false);
    /*
     * Deleting a workspace takes its books with it. That belongs in the
     * sentence somebody reads before typing the name, not in a toast
     * afterwards.
     */
    const cascade = type === 'workspace' && (holds ?? 0) > 0;
    const [tagsOpen, setTagsOpen] = useState(false);
    const [box, setBox] = useState({ top: 0, left: 0, above: false, tagsLeft: false });
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    const key = organiserKey(type, id);
    const isFavourite = data?.favourites.includes(key) ?? false;
    const assigned = data?.assigned[key] ?? [];
    const tags = data?.data ?? [];

    // Measured after layout but before paint, so the menu never appears in the
    // wrong place for a frame and then jumps.
    useLayoutEffect(() => {
        if (!open || !triggerRef.current) {
            return;
        }

        const rect = triggerRef.current.getBoundingClientRect();
        const MENU_HEIGHT = 184; // Updated from 148 to account for Edit button
        const MENU_WIDTH = 208;
        const below = window.innerHeight - rect.bottom;

        setBox({
            above: below < MENU_HEIGHT,
            top: below < MENU_HEIGHT ? rect.top - MENU_HEIGHT - 6 : rect.bottom + 6,
            left: Math.max(8, rect.right - MENU_WIDTH),
            // The tag list opens left when there is not room for it on the right.
            tagsLeft: window.innerWidth - rect.right < 200,
        });
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;

            if (!menuRef.current?.contains(target) && !triggerRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setOpen((was) => !was)}
                className="row-menu-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={`More options for ${name}`}
            >
                <Icon name="dots-three" size={18} weight="duotone" />
            </button>

            {open && <FlyoutBackdrop onClose={() => setOpen(false)} dismissOnOutsidePress={false} />}

            {open &&
                createPortal(
                    <div
                        ref={menuRef}
                        className="row-menu"
                        data-flyout-panel
                        style={{ top: box.top, left: box.left }}
                        role="menu"
                    >
                        <button
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                setFavourite.mutate({ type, target: id, on: !isFavourite });
                                setOpen(false);
                            }}
                            className="row-menu-item"
                        >
                            <Icon
                                name="star"
                                size={15}
                                weight={isFavourite ? 'fill' : 'duotone'}
                                className={isFavourite ? 'text-[var(--color-warning)]' : undefined}
                            />
                            {isFavourite ? 'Remove from favourites' : 'Add to favourites'}
                        </button>

                        <div
                            className="relative"
                            onMouseEnter={() => setTagsOpen(true)}
                            onMouseLeave={() => setTagsOpen(false)}
                        >
                            <button
                                type="button"
                                role="menuitem"
                                onClick={() => setTagsOpen((was) => !was)}
                                className={cn('row-menu-item', tagsOpen && 'is-open')}
                                aria-haspopup="menu"
                                aria-expanded={tagsOpen}
                            >
                                <Icon name="tag" size={15} weight="duotone" />
                                Add tag
                                <Icon name="caret-right" size={12} weight="duotone" className="ml-auto" />
                            </button>

                            {tagsOpen && (
                                <div className={cn('row-submenu', box.tagsLeft && 'is-left')} role="menu" data-flyout-panel>
                                    {tags.length === 0 ? (
                                        <p className="px-3 py-2.5 text-xs text-[var(--color-text-muted)]">
                                            You don’t have any tags yet
                                        </p>
                                    ) : (
                                        <div className="max-h-52 overflow-y-auto py-1">
                                            {tags.map((tag) => {
                                                const on = assigned.includes(tag.id);

                                                return (
                                                    <label key={tag.id} className="row-tag-option">
                                                        <input
                                                            type="checkbox"
                                                            checked={on}
                                                            onChange={() =>
                                                                assignTag.mutate({
                                                                    tag: tag.id,
                                                                    type,
                                                                    target: id,
                                                                    on: !on,
                                                                })
                                                            }
                                                        />
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {tag.name}
                                                        </span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setManaging(true);
                                            setOpen(false);
                                        }}
                                        className="row-submenu-manage"
                                    >
                                        Manage tags
                                    </button>
                                </div>
                            )}
                        </div>

                        <button
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                if (onEdit) {
                                    onEdit();
                                }
                                setOpen(false);
                            }}
                            className="row-menu-item"
                        >
                            <Icon name="pencil-simple" size={15} weight="duotone" />
                            Edit
                        </button>

                        <button
                            type="button"
                            role="menuitem"
                            onClick={async () => {
                                setOpen(false);

                                /*
                                  Typing the name, in the app's own dialog.

                                  This used to raise a private copy of the
                                  confirmation -- its own backdrop, its own
                                  buttons, its own idea of what a dangerous
                                  question looks like. The typing is the part
                                  worth keeping; the rest is now the same
                                  dialog every other delete in the app uses.
                                */
                                const sure = await confirm(
                                    `Delete ${name}?`,
                                    type === 'workspace'
                                        ? cascade
                                            ? holds === 1
                                                ? 'The workspace goes, and so does the set of books kept in it — along with every figure in them.'
                                                : `The workspace goes, and so do all ${holds} sets of books kept in it — along with every figure in them.`
                                            : 'The workspace is removed from every list.'
                                        : 'This set of books is removed from every list, along with the figures kept in it.',
                                    { requireText: name, confirmText: 'Yes, Delete' },
                                );

                                if (sure) {
                                    onDelete();
                                }
                            }}
                            className="row-menu-item is-danger"
                        >
                            <Icon name="trash" size={15} weight="duotone" />
                            Delete
                        </button>
                    </div>,
                    document.body,
                )}

            {managing && <ManageTagsModal onClose={() => setManaging(false)} />}

        </>
    );
}
