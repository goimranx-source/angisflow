import { useCallback, useMemo } from 'react';
import { useLocation } from 'react-router';

import { BrandMark } from '@/components/shell/BrandMark';
import { NavChild, NavFlat, PinButton } from '@/components/shell/NavRow';
import { PRIMARY_ITEMS, PrimaryNav } from '@/components/shell/PrimaryNav';
import { Icon } from '@/components/ui/Icon';
import { useAccordionReveal } from '@/hooks/useAccordionReveal';
import { useMenu } from '@/hooks/useMenu';
import { useRail } from '@/hooks/useRail';
import { useScrollReveal } from '@/hooks/useScrollReveal';
import { cn, pathMatches } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';
import type { NavItem, NavSection } from '@/types';

type SidebarProps = {
    mobileOpen: boolean;
    onCloseMobile: () => void;
};

/**
 * The sidebar.
 *
 * Two levels rather than one, because forty entries under ten shouting headings
 * is not a menu, it is an inventory — you read all of it to find one thing. A
 * department you can fold away is a menu you can scan.
 *
 * ── One menu, at two widths ──────────────────────────────────────────────────
 *
 * Collapsed, the rail widens under the pointer and shrinks when it leaves —
 * over the page rather than pushing it. So there is exactly one menu here, and
 * `narrow` (collapsed *and* not currently peeked) is what every appearance
 * decision reads. `collapsed` is only what the user chose, which is what the
 * header's control toggles.
 *
 * Mounted once by the layout route and never re-created, so none of this state
 * is rebuilt on navigation.
 */
export function Sidebar({ mobileOpen, onCloseMobile }: SidebarProps) {
    const { nav, app } = useSession();
    const { pathname } = useLocation();
    const { collapsed, narrow, toggle: toggleRail, rail, openPeek, closePeek } = useRail();
    const navScroll = useScrollReveal<HTMLElement>();

    const isActive = useCallback((item: NavItem) => pathMatches(pathname, item.match), [pathname]);

    // Which department holds the page being looked at. Recomputed only when the
    // path changes, not on every render of every row.
    const activeSectionKey = useMemo(
        () => nav.find((section) => !section.flat && section.items.some(isActive))?.key ?? null,
        [nav, isActive],
    );

    const menu = useMenu(nav, activeSectionKey, isActive);

    // Which of the two menus the rail is showing. Derived from the path rather
    // than held as state: a browser back button out of a business has to put
    // the rail back too, and remembered state does not do that.
    const inBusiness = useMemo(
        () => !PRIMARY_ITEMS.some((item) => (item.end ? pathname === item.to : pathname.startsWith(item.to))),
        [pathname],
    );

    const flat = nav.filter((section) => section.flat);
    const grouped = nav.filter((section) => !section.flat);

    return (
        <>
            {/* Mobile scrim. Closes the menu — one that covers the page with no
                obvious way out is a trap. */}
            {mobileOpen && (
                <div
                    className="fixed inset-x-0 bottom-0 top-[var(--header-bottom)] z-40 bg-[rgba(0,0,0,0.45)] md:hidden"
                    onClick={onCloseMobile}
                    aria-hidden
                />
            )}

            <aside
                ref={rail}
                className={cn(
                    // Stacking, transitions and now the drawer's own offset are
                    // all left to .rail in the stylesheet. Utilities here
                    // silently won over it every time — Tailwind's utilities
                    // layer outranks the components layer whatever the
                    // specificity — so `z-50` beat the three z-index cases the
                    // rail has to express, and `transition-transform` replaced
                    // the width transition outright, which is why the peek used
                    // to snap open with no animation at all.
                    //
                    // The translate utilities have gone the same way: the rail
                    // is inset from the left by its own margin, so Tailwind's
                    // -translate-x-full — which shifts by exactly the element's
                    // width — left that margin's worth of it still on screen as
                    // a sliver down the edge of the page. Closing it properly
                    // needs the margin in the sum, which a utility cannot know.
                    'rail fixed flex flex-col',
                    mobileOpen && 'is-open',
                )}
                aria-label="Main navigation"
                // Collapsed, the pointer arriving here widens the rail over the
                // page and leaving shrinks it back. See useRail.
                onMouseEnter={openPeek}
                onMouseLeave={closePeek}
            >
                {/* ── Sidebar Header with Logo and Collapse ─────────────── */}
                <div className="rail-head">
                    {/*
                        The mark is drawn once, at one size, in both states —
                        see BrandMark. Only the wordmark comes and goes, so the
                        reveal adds a name beside a mark that has not moved
                        rather than swapping one drawing for another.
                    */}
                    <div className="rail-logo">
                        <BrandMark />
                        {/* Hide wordmark when show_logo is true (logo is present) or when narrow */}
                        {!narrow && !app.show_logo && (
                            <span className="rail-wordmark">{app.name}</span>
                        )}
                    </div>
                    
                    {/*
                        Not rendered at all while narrow, rather than hidden.
                        Hidden, it still took its 36px out of a 60px header and
                        pushed the mark off centre — and CSS could not take that
                        space back, because `md:inline-flex` is a Tailwind
                        utility and outranks anything the stylesheet says about
                        display. Nothing is lost: narrow, the pointer arriving
                        is what widens the rail, and the control comes with the
                        width.

                        The icon reads `collapsed`, not `narrow`. Peeked, the
                        rail looks open but is not, and the control has to offer
                        the thing that is not already true — keeping it.
                        Pointing it left because the rail happens to be wide
                        under the pointer would make the one button that
                        explains the state lie about it.
                    */}
                    {!narrow && <button
                        type="button"
                        onClick={toggleRail}
                        className="rail-action-footer hidden md:inline-flex"
                        aria-label={collapsed ? 'Keep sidebar open' : 'Collapse sidebar'}
                        title={collapsed ? 'Keep open' : 'Collapse'}
                    >
                        <Icon name={collapsed ? 'caret-right' : 'caret-left'} size={16} weight="bold" />
                    </button>}
                </div>
                {/* ── The account's own menu ────────────────────────────────
                    Full rows while the rail belongs to the account; a strip of
                    icons once a business is open, so the way back out is always
                    there without burying that business's modules. */}
                <div className="rail-primary flex-none">
                    <PrimaryNav compact={inBusiness || narrow} />
                </div>

                {/* ── That business's modules ───────────────────────────── */}
                <nav
                    ref={navScroll}
                    className={cn('rail-nav flex-1 overflow-y-auto', !inBusiness && 'hidden')}
                >
                    {flat.map((section) =>
                        section.items.map((item) => (
                            <NavFlat
                                key={item.key}
                                item={item}
                                active={isActive(item)}
                                pinned={menu.isPinned(item.key)}
                                onTogglePin={menu.togglePin}
                            />
                        )),
                    )}

                    {/* ── Pinned ────────────────────────────────────────
                        Whatever somebody put here, straight under the
                        dashboard. A tool with forty pages has maybe five that
                        one person opens all day, and which five is not
                        something the menu can know. */}
                    {menu.pinnedEntries.length > 0 && (
                        <PinnedGroup menu={menu} narrow={narrow} isActive={isActive} />
                    )}

                    {grouped.map((section) => (
                        <NavGroup
                            key={section.key}
                            section={section}
                            openKey={section.key}
                            narrow={narrow}
                            menu={menu}
                            isActive={isActive}
                            holdsActive={activeSectionKey === section.key}
                        />
                    ))}
                </nav>

            </aside>

        </>
    );
}

/**
 * Pinned, folding like any other department.
 *
 * Its own component rather than markup inline in the rail, because it needs
 * the same reveal-on-open behaviour the departments have and a hook cannot be
 * called from the middle of a conditional in somebody else's JSX. Being a
 * component also means it is a group by construction rather than by looking
 * like one.
 */
function PinnedGroup({
    menu,
    narrow,
    isActive,
}: {
    menu: ReturnType<typeof useMenu>;
    narrow: boolean;
    isActive: (item: NavItem) => boolean;
}) {
    const open = menu.isOpen('pinned');
    const reveal = useAccordionReveal(open);

    return (
        <div ref={reveal} className={cn('nav-group', open && 'is-open')}>
            <div
                className="nav-parent"
                role="button"
                tabIndex={0}
                onClick={() => menu.toggle('pinned')}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        menu.toggle('pinned');
                    }
                }}
                aria-expanded={narrow ? undefined : open}
                title="Pinned"
            >
                <span className="nav-icon">
                    <Icon name="push-pin" size={18} weight="duotone" />
                </span>
                <span className="nav-label">Pinned</span>
                <Icon name="caret-right" size={11} className="nav-caret" />
            </div>

            {!narrow && (
                <div className={cn('nav-children-shell', open && 'is-open')}>
                    <div className="nav-children">
                        {/* Every entry is a heading and its pages, whether the
                            whole department was pinned or the pages were pinned one
                            at a time. A page printed loose between two groups reads
                            as belonging to whichever one it happens to follow. */}
                        {menu.pinnedEntries.map((entry) => (
                            <div key={`${entry.kind}:${entry.key}`} className="nav-pinned-section">
                                {entry.label !== null && (
                                    <div className="nav-pinned-title">{entry.label}</div>
                                )}

                                {entry.items.map((item) => (
                                    <NavChild
                                        key={item.key}
                                        item={item}
                                        active={isActive(item)}
                                        pinned={menu.isPinned(item.key)}
                                        onTogglePin={menu.togglePin}
                                    />
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

type NavGroupProps = {
    section: NavSection;
    openKey: string;
    narrow: boolean;
    menu: ReturnType<typeof useMenu>;
    isActive: (item: NavItem) => boolean;
    holdsActive: boolean;
    alwaysPinned?: boolean;
};

function NavGroup({
    section,
    openKey,
    narrow,
    menu,
    isActive,
    holdsActive,
    alwaysPinned = false,
}: NavGroupProps) {
    const open = menu.isOpen(openKey);
    const reveal = useAccordionReveal(open);

    return (
        <div ref={reveal} className={cn('nav-group', open && 'is-open')}>
            <div
                className={cn('nav-parent', holdsActive && 'holds-active')}
                role="button"
                tabIndex={0}
                onClick={() => menu.toggle(openKey)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        menu.toggle(openKey);
                    }
                }}
                aria-expanded={narrow ? undefined : open}
                title={section.label ?? undefined}
            >
                <span className="nav-icon">
                    <Icon name={section.icon} size={18} weight="duotone" />
                </span>
                <span className="nav-label">{section.label}</span>
                <Icon name="caret-right" size={11} className="nav-caret" />
                <PinButton
                    itemKey={section.key}
                    pinned={alwaysPinned || menu.isPinned(section.key)}
                    onToggle={menu.togglePin}
                    label={section.label ?? section.key}
                />
            </div>

            {!narrow && (
                <div className={cn('nav-children-shell', open && 'is-open')}>
                    <div className="nav-children">
                        {section.items.map((item) => (
                            <NavChild
                                key={item.key}
                                item={item}
                                active={isActive(item)}
                                pinned={menu.isPinned(item.key)}
                                onTogglePin={menu.togglePin}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
