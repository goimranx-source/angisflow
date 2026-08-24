import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { createPortal } from 'react-dom';

import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { Icon } from '@/components/ui/Icon';
import { useScrollLock } from '@/hooks/useScrollLock';
import { api } from '@/lib/api';
import { useSession } from '@/providers/SessionProvider';

/**
 * Who is signed in, and everything that belongs to them.
 *
 * ── Why it moved out of the sidebar ──────────────────────────────────────────
 *
 * It used to sit at the foot of the rail, which meant it disappeared into a
 * 56px column whenever the rail was collapsed and had to grow a second copy of
 * the name for that case. The top-right corner is where an account lives in
 * every tool of this shape, it is in the same place whatever the rail is doing,
 * and it leaves the rail free to be only navigation.
 *
 * The initials are drawn here rather than fetched from an avatar service. The
 * first version of Prism reached out to a third party on every page load for a
 * picture of two letters, and when that was slow or blocked the word "Avatar"
 * spilled across the sidebar.
 *
 * Signing out is a POST, never a link. A GET that destroys a session can be
 * triggered by anything that makes the browser issue a request — an image tag
 * on a forum post is enough — and the result is users being signed out at
 * random with nobody able to reproduce it.
 */
export function AccountMenu() {
    const { auth, tenant, clear } = useSession();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [exiting, setExiting] = useState(false);
    const [busy, setBusy] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    useScrollLock(open, 880);

    // Close with animation
    const closeMenu = useCallback(() => {
        setExiting(true);
        setTimeout(() => {
            setOpen(false);
            setExiting(false);
        }, 150);
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                closeMenu();
            }
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeMenu();
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, closeMenu]);

    if (!auth) {
        return null;
    }

    const signOut = async () => {
        setBusy(true);

        try {
            await api.post('/auth/logout');
        } catch {
            // The session may already be gone, which is the outcome anyway.
        } finally {
            // Cleared locally whatever the server said, so a failed call cannot
            // leave somebody looking at a signed-in shell they cannot use.
            clear();
            navigate('/login', { replace: true });
        }
    };

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                onClick={() => {
                    if (open) {
                        closeMenu();
                    } else {
                        setOpen(true);
                    }
                }}
                className="account-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Account"
                title={auth.user.name}
            >
                {auth.user.avatar ? (
                    <img src={auth.user.avatar} className="account-avatar" alt="" />
                ) : (
                    <Icon name="user" size={16} weight="duotone" />
                )}
            </button>

            {open && <FlyoutBackdrop onClose={closeMenu} layer="calc(var(--z-shell-menu) - 1)" />}

            {/*
              This leaves the header, and has to.

              A panel inside the topbar is inside the topbar's stacking context,
              so its z-index is a rank among its siblings and nothing more: the
              bar sits at 55, and the sheet that has to cover the sidebar sits
              well above that, so the panel went under its own backdrop.

              Rendered into <body> it is ranked against the sheet directly,
              which is the comparison that was meant all along. Costs nothing to
              move -- .account-menu is already position:fixed against the
              viewport, so it lands in exactly the same place.
            */}
            {open &&
                createPortal(
                <div role="menu" className={`account-menu ${exiting ? 'is-exiting' : ''}`}>
                    <div className="account-menu-who">
                        <span className="block truncate font-semibold text-[var(--color-text-main)]">
                            {auth.user.name}
                        </span>
                        <span className="block truncate text-xs text-[var(--color-text-muted)]">
                            {auth.user.email}
                        </span>
                    </div>

                    <div className="account-menu-group">
                        <MenuItem to="/profile" icon="user" onGo={closeMenu}>
                            Profile &amp; security
                        </MenuItem>
                        <MenuItem to="/billing" icon="wallet" onGo={closeMenu}>
                            <span className="flex-1">Subscription</span>
                            {tenant?.allowance?.plan && (
                                <span className="account-menu-plan">{tenant.allowance.plan}</span>
                            )}
                        </MenuItem>
                        {/*
                            Settings has moved to the sidebar, under Admin &
                            Settings. It belongs with the workspace rather than
                            with the person: currency and integrations now
                            resolve per workspace, so the screen genuinely says
                            something different depending on which one is open —
                            and this menu does not change when you switch. Left
                            here, it would read as an account-wide screen.

                            What remains in this drawer is what is true of the
                            person however they switch: their profile, their
                            subscription, their two-factor.
                        */}

                        {!auth.user.two_factor_enabled && (
                            <MenuItem
                                to="/profile#two-factor"
                                icon="shield-check"
                                onGo={closeMenu}
                            >
                                Turn on two-factor
                            </MenuItem>
                        )}
                    </div>

                    <div className="account-menu-group account-menu-group-divided">
                        <button
                            type="button"
                            onClick={() => void signOut()}
                            disabled={busy}
                            className="account-menu-item"
                            role="menuitem"
                        >
                            <Icon name="sign-out" size={15} weight="duotone" />
                            {busy ? 'Signing out…' : 'Sign out'}
                        </button>
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
}

function MenuItem({
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
        <Link
            to={to}
            role="menuitem"
            onClick={onGo}
            className="account-menu-item"
        >
            <Icon name={icon} size={15} weight="duotone" className="flex-none" />
            <span className="flex flex-1 items-center gap-2">{children}</span>
        </Link>
    );
}
