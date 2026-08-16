import { useEffect, useRef, useState } from 'react';

import { AccountMenu } from '@/components/shell/AccountMenu';
import { ContextPicker } from '@/components/shell/ContextPicker';
import { HeaderPopover } from '@/components/shell/HeaderPopover';
import { AngisflowLogo } from '@/components/ui/AngisflowLogo';
import { Icon } from '@/components/ui/Icon';
import { useTheme } from '@/hooks/useTheme';
import { useSession } from '@/providers/SessionProvider';

type TopbarProps = {
    mobileOpen: boolean;
    onOpenMobileMenu: () => void;
    onCloseMobileMenu: () => void;
    onOpenPalette: () => void;
    onAskAi: () => void;
};

export function Topbar({ mobileOpen, onOpenMobileMenu, onCloseMobileMenu, onOpenPalette, onAskAi }: TopbarProps) {
    const { app } = useSession();
    const { dark, toggle: toggleTheme } = useTheme();
    // Lifted rather than left inside HeaderPopover, because the overflow
    // menu's "Messages" row — reached only once the direct button has
    // collapsed away — needs a way to open the same popover from outside it.
    const [messagesOpen, setMessagesOpen] = useState(false);

    return (
        <header className="topbar flex flex-none items-center gap-1.5 px-3 sm:gap-3 sm:px-4">
            {/* Logo leads the bar on mobile/tablet, where the sidebar is not
                visible to carry it. Use small favicon logo for compact mobile header. */}
            <div className="topbar-logo md:hidden">
                <AngisflowLogo size="small" forceType="favicon" />
                {/* Only show name if show_logo is false */}
                {!app.show_logo && (
                    <span className="topbar-logo-name">{app.name}</span>
                )}
            </div>

            <button
                type="button"
                onClick={mobileOpen ? onCloseMobileMenu : onOpenMobileMenu}
                className="rail-action md:hidden"
                aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
            >
                <Icon name={mobileOpen ? 'x' : 'list'} size={18} weight="duotone" />
            </button>

            <ContextPicker />

            <div className="flex flex-1 items-center justify-end gap-1.5 sm:gap-2.5">
                {/* Lowest-priority first: each of these three collapses into
                    the overflow menu, in this order, before Notifications,
                    Ask AI or the account ever would. See the matching
                    .topbar-*-direct / .topbar-overflow-item-* breakpoints. */}
                <button
                    type="button"
                    onClick={toggleTheme}
                    className="topbar-icon topbar-theme-direct"
                    aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
                    title={dark ? 'Light mode' : 'Dark mode'}
                >
                    <Icon name={dark ? 'sun' : 'moon'} size={17} weight="duotone" />
                </button>

                <button
                    type="button"
                    onClick={onOpenPalette}
                    data-search-trigger
                    className="topbar-icon topbar-search-direct"
                    aria-label="Search"
                    aria-haspopup="dialog"
                    title="Search"
                >
                    <Icon name="magnifying-glass" size={17} weight="duotone" />
                </button>

                <HeaderPopover
                    icon="bell"
                    label="Notifications"
                    title="Notifications"
                    emptyText="Nothing here yet."
                />

                <HeaderPopover
                    icon="envelope"
                    label="Messages"
                    title="Messages"
                    emptyText="Nothing here yet."
                    className="topbar-messages-direct"
                    open={messagesOpen}
                    onOpenChange={setMessagesOpen}
                />

                {/* Always visible, whatever the width: the assistant and
                    you. */}
                <button type="button" onClick={onAskAi} className="ai-pill" aria-haspopup="dialog">
                    <span className="ai-pill-inner">
                        <Icon name="sparkle" size={15} weight="fill" />
                        <span className="hidden sm:inline">Ask AI</span>
                    </span>
                </button>

                {/* Its own tight group — the overflow trigger reads as
                    attached to the account button, not as another item in
                    the row's normal rhythm. */}
                <div className="flex items-center gap-0.5">
                    <AccountMenu />
                    <TopbarOverflow
                        onSearch={onOpenPalette}
                        onToggleTheme={toggleTheme}
                        onMessages={() => setMessagesOpen(true)}
                        dark={dark}
                    />
                </div>
            </div>
        </header>
    );
}

/**
 * The catch for whichever direct actions the bar didn't have room for.
 *
 * Its trigger and each of its rows are shown purely by CSS breakpoint — see
 * .topbar-overflow-trigger and .topbar-overflow-item-* in app.css — so it
 * stays empty and hidden until something has actually been dropped into it,
 * rather than existing as a permanent extra tap.
 */
function TopbarOverflow({
    onSearch,
    onToggleTheme,
    onMessages,
    dark,
}: {
    onSearch: () => void;
    onToggleTheme: () => void;
    onMessages: () => void;
    dark: boolean;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const handler = (e: PointerEvent) => {
            if (!ref.current?.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('pointerdown', handler);
        return () => document.removeEventListener('pointerdown', handler);
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="topbar-overflow-trigger"
                aria-label="More actions"
                aria-expanded={open}
            >
                <Icon name="dots-three-vertical" size={18} weight="bold" />
            </button>

            {open && (
                <div className="topbar-overflow-menu">
                    <button
                        type="button"
                        onClick={() => { setOpen(false); onToggleTheme(); }}
                        className="topbar-overflow-item topbar-overflow-item-theme"
                    >
                        <Icon name={dark ? 'sun' : 'moon'} size={16} weight="duotone" />
                        {dark ? 'Light mode' : 'Dark mode'}
                    </button>
                    <button
                        type="button"
                        onClick={() => { setOpen(false); onSearch(); }}
                        className="topbar-overflow-item topbar-overflow-item-search"
                    >
                        <Icon name="magnifying-glass" size={16} weight="duotone" />
                        Search
                    </button>
                    <button
                        type="button"
                        onClick={() => { setOpen(false); onMessages(); }}
                        className="topbar-overflow-item topbar-overflow-item-messages"
                    >
                        <Icon name="envelope" size={16} weight="duotone" />
                        Messages
                    </button>
                </div>
            )}
        </div>
    );
}
