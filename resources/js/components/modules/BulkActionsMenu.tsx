import { useState, useRef, useEffect, useLayoutEffect } from 'react';
import { createPortal } from 'react-dom';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

export type BulkActionGroup = {
    label: string;
    icon?: string;
    items: BulkActionItem[];
};

export type BulkActionItem = {
    key: string;
    label: string;
    icon?: string;
    description?: string;
    onSelect: () => void;
    disabled?: boolean;
    variant?: 'default' | 'danger';
};

/**
 * Professional bulk actions menu triggered from the toolbar.
 *
 * ── Why this menu ──────────────────────────────────────────────────────────
 *
 * Bulk actions on orders have complex, context-dependent menus (statuses,
 * couriers, print options) that don't fit in a standard select dropdown.
 *
 * A portal-rendered menu with organized sections, icons, and descriptions
 * reads as a first-class feature, not an afterthought. It also stays
 * visible when filtering or scrolling, so users can apply bulk changes
 * without losing their place in the list.
 *
 * ── Organization ───────────────────────────────────────────────────────────
 *
 * - Order Status section: for status changes (Processing, Completed, etc.)
 * - Payment section: paid/unpaid status
 * - Fulfillment section: if applicable
 * - Shipment section: courier assignment
 * - Batch Actions section: print/export (icon-based, visual hierarchy)
 * - Destructive section: cancel/trash (danger variant, last)
 *
 * This mirrors how row actions are organized: read-only/informational first,
 * then changes, then destructive.
 */
export function BulkActionsMenu({
    trigger: TriggerComponent,
    groups,
    disabled = false,
}: {
    trigger: React.ComponentType<{ onClick: () => void; disabled?: boolean }>;
    groups: BulkActionGroup[];
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        if (!open || !trigger.current) {
            return;
        }

        const rect = trigger.current.getBoundingClientRect();
        setAt({ top: rect.bottom + 4, right: Math.max(8, window.innerWidth - rect.right) });
    }, [open]);

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

    const handleSelectAction = (onSelect: () => void) => {
        setOpen(false);
        onSelect();
    };

    return (
        <>
            <div ref={trigger}>
                <TriggerComponent
                    onClick={() => setOpen((was) => !was)}
                    disabled={disabled}
                />
            </div>

            {open &&
                at &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        style={{ top: at.top, right: at.right }}
                        className="fixed z-[var(--z-toast)] w-80 overflow-hidden rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)] shadow-lg"
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {/* Menu Groups */}
                        {groups.map((group, groupIndex) => (
                            <div key={group.label}>
                                {/* Group Header */}
                                <div className="flex items-center gap-2 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                                    {group.icon && <Icon name={group.icon} size={14} />}
                                    <span>{group.label}</span>
                                </div>

                                {/* Group Items */}
                                <div className="space-y-0.5 border-b border-[var(--color-border-light)] px-1 py-1 last:border-b-0">
                                    {group.items.map((item) => (
                                        <button
                                            key={item.key}
                                            type="button"
                                            role="menuitem"
                                            disabled={item.disabled}
                                            className={cn(
                                                'flex w-full items-start gap-3 rounded-[var(--shell-radius-sm)] px-2 py-2 text-left transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
                                                item.variant === 'danger'
                                                    ? 'text-[var(--color-danger)] hover:bg-[var(--color-danger-subtle)]'
                                                    : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
                                            )}
                                            onClick={() => handleSelectAction(item.onSelect)}
                                        >
                                            {item.icon && (
                                                <div className="mt-0.5 shrink-0">
                                                    <Icon name={item.icon} size={16} />
                                                </div>
                                            )}
                                            <div className="flex flex-col gap-0.5">
                                                <span className="text-sm font-medium">{item.label}</span>
                                                {item.description && (
                                                    <span className="text-xs text-[var(--color-text-muted)]">
                                                        {item.description}
                                                    </span>
                                                )}
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}
