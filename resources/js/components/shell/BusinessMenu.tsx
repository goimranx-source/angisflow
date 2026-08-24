import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router';

import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { queryClient } from '@/lib/query';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload, BusinessSummary } from '@/types';

/**
 * Which set of books is open — and everything you'd want to do about it.
 *
 * ── Why the header's right side ─────────────────────────────────────────────
 *
 * It began as an icon and a name on the left, which said what the business was
 * and nothing else. The right side of a bar is where an account lives in every
 * tool of this kind, and putting it there buys the room for the things that
 * actually belong next to it: what currency the figures are in, how to reach
 * the business's own settings, and how to switch.
 *
 * ── Why the cache is emptied on switch ───────────────────────────────────────
 *
 * Everything cached on the client belongs to one business: its orders, its
 * figures, its stock. Switching without clearing means the new business's
 * dashboard renders instantly from the *previous* one's cached data and then
 * corrects itself a moment later — and for that moment the owner is looking at
 * one business's numbers under the other's name, with no way to know.
 */
export function BusinessMenu() {
    const { tenant, apply } = useSession();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    /*
     * Where the panel goes once it is out of the header.
     *
     * It hung off the trigger with position:absolute, which meant it lived
     * inside the topbar -- and inside the topbar's stacking context, where its
     * z-index ranks it against its siblings and nothing else. The bar sits at
     * 55; the sheet that has to cover the sidebar sits well above that. The
     * panel went under its own backdrop.
     *
     * Measured on open and pinned to the viewport instead, which is the same
     * thing .account-menu and .header-popover already do.
     */
    const [box, setBox] = useState({ top: 0, right: 0 });

    useLayoutEffect(() => {
        if (!open || !container.current) {
            return;
        }

        const place = () => {
            const rect = container.current?.getBoundingClientRect();

            if (rect) {
                setBox({
                    top: rect.bottom + 8,

                    // Right-aligned to the trigger, measured from the window's
                    // right edge because that is what `right` is relative to.
                    right: Math.max(8, window.innerWidth - rect.right),
                });
            }
        };

        place();
        window.addEventListener('resize', place);

        return () => window.removeEventListener('resize', place);
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!container.current?.contains(event.target as Node)) {
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

    const business = tenant?.business;

    if (!business) {
        return null;
    }

    const others = tenant.businesses.filter((item) => item.id !== business.id);

    const switchTo = async (id: string) => {
        setOpen(false);

        if (id === business.id || busy) {
            return;
        }

        setBusy(true);
        queryClient.clear();

        try {
            const result = await api.post<{ message: string; boot: BootPayload }>('/businesses/switch', {
                business: id,
            });

            // The whole shell comes back with the switch — account, business,
            // menu — so this is one round trip rather than a switch followed by
            // a reload to find out what changed.
            apply(result.boot);
            toast.success(result.message);
        } catch {
            toast.error('That business could not be opened.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                disabled={busy}
                className="biz-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
            >
                <span className="biz-mark">{initials(business)}</span>

                <span className="hidden min-w-0 text-left lg:block">
                    <span className="block max-w-36 truncate text-[0.8125rem] leading-tight font-semibold">
                        {business.name}
                    </span>
                    <span className="block text-[0.6875rem] leading-tight text-[var(--color-text-subtle)]">
                        {business.currency}
                    </span>
                </span>

                <Icon
                    name="caret-down"
                    size={13}
                    className={`flex-none text-[var(--color-text-subtle)] transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>

            {open && <FlyoutBackdrop onClose={() => setOpen(false)} dismissOnOutsidePress={false} />}

            {open &&
                createPortal(
                <div role="menu" className="biz-panel" style={{ top: box.top, right: box.right }}>
                    <div className="biz-head">
                        <span className="biz-mark biz-mark-lg">{initials(business)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-[var(--color-text-main)]">
                                {business.name}
                            </span>
                            <span className="block text-xs text-[var(--color-text-muted)]">
                                Figures shown in {business.currency}
                                {business.short_code ? ` · ${business.short_code}` : ''}
                            </span>
                        </span>
                    </div>

                    {others.length > 0 && (
                        <div className="biz-section">
                            <p className="biz-label">Switch to</p>
                            {others.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    role="menuitem"
                                    onClick={() => void switchTo(item.id)}
                                    className="biz-item"
                                >
                                    <span className="biz-mark biz-mark-sm">{initials(item)}</span>
                                    <span className="min-w-0 flex-1 text-left">
                                        <span className="block truncate font-medium">{item.name}</span>
                                        <span className="block text-[0.6875rem] text-[var(--color-text-muted)]">
                                            {item.currency}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="biz-section biz-section-divided">
                        <MenuLink to="/settings/appearance" icon="paint-brush" onGo={() => setOpen(false)}>
                            Business settings
                        </MenuLink>
                        <MenuLink to="/settings/currency" icon="currency-circle-dollar" onGo={() => setOpen(false)}>
                            Currency &amp; rates
                        </MenuLink>
                        <MenuLink to="/billing" icon="wallet" onGo={() => setOpen(false)}>
                            Plan &amp; billing
                        </MenuLink>
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
}

function MenuLink({
    to,
    icon,
    children,
    onGo,
}: {
    to: string;
    icon: string;
    children: React.ReactNode;
    onGo: () => void;
}) {
    return (
        <Link to={to} role="menuitem" onClick={onGo} className="biz-item">
            <Icon name={icon} size={16} className="flex-none text-[var(--color-text-muted)]" />
            <span className="flex-1">{children}</span>
        </Link>
    );
}

function initials(business: BusinessSummary): string {
    if (business.short_code) {
        return business.short_code.slice(0, 2).toUpperCase();
    }

    // Two words give two letters; one word gives its first two, so a mark is
    // always the same width and the row never jitters between businesses.
    const words = business.name.trim().split(/\s+/).filter(Boolean);

    if (words.length > 1) {
        return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase();
    }

    return business.name.slice(0, 2).toUpperCase();
}
