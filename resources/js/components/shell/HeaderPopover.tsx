import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { Icon } from '@/components/ui/Icon';
import { useScrollLock } from '@/hooks/useScrollLock';
import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { cn } from '@/lib/utils';

/**
 * A small anchored flyout for a header icon button — notifications, messages,
 * anything that is a placeholder today and a real panel later.
 *
 * Positioned from the trigger's own rect rather than pinned to the page's
 * right edge (see AccountMenu), because this one does not live at the end of
 * the row — Notifications and Messages both sit to the left of Ask AI and the
 * account. Anchoring to the edge would leave the flyout floating well away
 * from the button that opened it.
 */
export function HeaderPopover({
    icon,
    label,
    title,
    emptyText,
    triggerClassName = 'topbar-icon',
    className,
    iconSize = 17,
    open: controlledOpen,
    onOpenChange,
}: {
    icon: string;
    label: string;
    title: string;
    emptyText: string;
    triggerClassName?: string;
    /** Goes on the wrapper, not the button. Anything that collapses this
     *  popover out of the bar has to hide the wrapper: it stays a flex item
     *  of the header row even with its button display:none inside, and the
     *  row keeps allocating a gap either side of the empty box. */
    className?: string;
    iconSize?: number;
    /** Left uncontrolled by default. Passed when something outside this
     *  component — the overflow menu, say — also needs to open it, which a
     *  purely internal useState has no way to reach. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const isControlled = controlledOpen !== undefined;
    const [internalOpen, setInternalOpen] = useState(false);
    const open = isControlled ? controlledOpen : internalOpen;
    const setOpen = (value: boolean) => {
        if (isControlled) onOpenChange?.(value);
        else setInternalOpen(value);
    };
    const [exiting, setExiting] = useState(false);
    const [pos, setPos] = useState({ top: 0, right: 0 });
    const container = useRef<HTMLDivElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const exitTimer = useRef<number | null>(null);

    useScrollLock(open, 880);

    const closeMenu = () => {
        if (exiting) return;
        setExiting(true);
        exitTimer.current = window.setTimeout(() => {
            setOpen(false);
            setExiting(false);
        }, 150);
    };

    useLayoutEffect(() => {
        if (!open) return;
        const rect = container.current?.getBoundingClientRect();
        if (!rect) return;
        const margin = 12;
        const gap = 8;
        const bar = container.current?.closest('.topbar')?.getBoundingClientRect();
        const top = (bar?.bottom ?? rect.bottom) + gap;
        const right = Math.max(margin, window.innerWidth - rect.right);
        setPos({ top, right });
    }, [open]);

    useEffect(() => {
        if (!open) return;

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;
            if (!container.current?.contains(target) && !panelRef.current?.contains(target)) {
                closeMenu();
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') closeMenu();
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => () => {
        if (exitTimer.current !== null) window.clearTimeout(exitTimer.current);
    }, []);

    return (
        <div ref={container} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => (open ? closeMenu() : setOpen(true))}
                className={triggerClassName}
                aria-label={label}
                aria-haspopup="dialog"
                aria-expanded={open}
                title={label}
            >
                <Icon name={icon} size={iconSize} weight="duotone" />
            </button>

            {open && <FlyoutBackdrop onClose={() => setOpen(false)} layer="calc(var(--z-shell-menu) - 1)" />}

            {open && createPortal(
                <div
                    ref={panelRef}
                    className={cn('header-popover', exiting && 'is-exiting')}
                    style={{ top: `${pos.top}px`, right: `${pos.right}px` }}
                    role="dialog"
                    aria-label={title}
                >
                    <div className="header-popover-head">{title}</div>
                    <p className="header-popover-empty">{emptyText}</p>
                </div>,
                document.body,
            )}
        </div>
    );
}
