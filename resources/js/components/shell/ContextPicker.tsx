import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router';

import { BusinessBadge, WorkspaceBadge } from '@/components/ui/Badge';
import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { useScrollLock } from '@/hooks/useScrollLock';
import { api } from '@/lib/api';
import { queryClient } from '@/lib/query';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload, PlanQuota, WorkspaceSummary } from '@/types';

type SubMenuType = 'workspaces' | 'businesses';

export function ContextPicker() {
    const { tenant, apply } = useSession();
    const [open, setOpen] = useState(false);
    const [exiting, setExiting] = useState(false);
    const [busy, setBusy] = useState(false);
    const [peekTop, setPeekTop] = useState(0);
    const [peekLeft, setPeekLeft] = useState(0);
    const [activeSubMenu, setActiveSubMenu] = useState<SubMenuType | null>(null);
    const [subPos, setSubPos] = useState({ top: -9999, left: -9999 });
    // Below this width the anchored flyouts have nowhere good to sit — a
    // 280px card clipped against a 320px screen is worse than no card at all.
    // Full screen instead, with a back button standing in for "beside it".
    const [mobileFull, setMobileFull] = useState(() => window.innerWidth < 880);

    // Only the full-screen state locks the page — the small anchored card
    // at wider widths sits over the page without needing to freeze it.
    useScrollLock(open && mobileFull);

    const container = useRef<HTMLDivElement>(null);
    const flyoutRef = useRef<HTMLDivElement>(null);
    const subMenuRef = useRef<HTMLDivElement>(null);
    const pendingButtonRect = useRef<DOMRect | null>(null);
    const exitTimer = useRef<number | null>(null);

    const handleClose = () => {
        if (exiting) return;
        setActiveSubMenu(null);
        setExiting(true);
        exitTimer.current = window.setTimeout(() => {
            setOpen(false);
            setExiting(false);
        }, 150);
    };

    const calculatePosition = useCallback(() => {
        const menu = flyoutRef.current;
        const anchor = container.current?.getBoundingClientRect();
        if (!menu || !anchor) return;

        const margin = 12;
        const gap = 8;
        const bar = container.current?.closest('.topbar')?.getBoundingClientRect();
        const top = (bar?.bottom ?? anchor.bottom) + gap;

        menu.style.maxHeight = '';
        const room = window.innerHeight - top - margin;
        menu.style.maxHeight = `${Math.max(room, 220)}px`;

        let left = anchor.left;
        const width = menu.offsetWidth;
        if (left + width > window.innerWidth - margin) left = window.innerWidth - width - margin;
        if (left < margin) left = margin;

        setPeekTop(top);
        setPeekLeft(left);
    }, []);

    const handleToggle = () => {
        if (busy) return;
        if (open) { handleClose(); return; }
        if (exitTimer.current !== null) window.clearTimeout(exitTimer.current);
        setExiting(false);
        setActiveSubMenu(null);
        setOpen(true);
    };

    useLayoutEffect(() => {
        if (open) calculatePosition();
    }, [open, calculatePosition]);

    // Position submenu after mount; prefer right of main flyout, flip left if needed
    useLayoutEffect(() => {
        if (!activeSubMenu || !subMenuRef.current || !flyoutRef.current) return;
        const buttonRect = pendingButtonRect.current;
        if (!buttonRect) return;

        const flyoutRect = flyoutRef.current.getBoundingClientRect();
        const subEl = subMenuRef.current;
        const gap = 8;
        const margin = 8;

        let top = buttonRect.top;
        const subWidth = subEl.offsetWidth;
        const subHeight = subEl.offsetHeight;

        let left = flyoutRect.right + gap;
        if (left + subWidth > window.innerWidth - margin) {
            left = flyoutRect.left - gap - subWidth;
        }
        if (left < margin) left = margin;

        if (top + subHeight > flyoutRect.bottom) top = flyoutRect.top;
        if (top + subHeight > window.innerHeight - margin) {
            top = Math.max(margin, window.innerHeight - subHeight - margin);
        }

        setSubPos({ top, left });
    }, [activeSubMenu]);

    useEffect(() => {
        if (!open) return;
        const reposition = () => calculatePosition();
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        return () => {
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        };
    }, [open, calculatePosition]);

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;
            if (
                !container.current?.contains(target) &&
                !flyoutRef.current?.contains(target) &&
                !subMenuRef.current?.contains(target)
            ) {
                handleClose();
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                if (activeSubMenu) setActiveSubMenu(null);
                else handleClose();
            }
        };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, activeSubMenu]);

    useEffect(() => () => {
        if (exitTimer.current !== null) window.clearTimeout(exitTimer.current);
    }, []);

    useEffect(() => {
        const onResize = () => setMobileFull(window.innerWidth < 880);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    const workspace = tenant?.workspace ?? null;
    const business = tenant?.business ?? null;

    if (!tenant || !workspace) return null;

    const currentWorkspace = tenant.workspaces.find((w) => w.id === workspace.id);

    const openContext = async (workspaceId: string, businessId?: string) => {
        setBusy(true);
        queryClient.clear();
        try {
            const result = await api.post<{ message: string; boot: BootPayload }>(
                '/workspaces/open',
                { workspace: workspaceId, business: businessId ?? null },
            );
            apply(result.boot);
            toast.success(result.message);
            handleClose();
        } catch {
            toast.error('That could not be opened.');
        } finally {
            setBusy(false);
        }
    };

    const handleSubMenu = (type: SubMenuType, buttonRect: DOMRect) => {
        if (activeSubMenu === type) { setActiveSubMenu(null); return; }
        pendingButtonRect.current = buttonRect;
        setSubPos({ top: -9999, left: -9999 });
        setActiveSubMenu(type);
    };

    return (
        <div ref={container} className="context-anchor">
            <button
                type="button"
                onClick={handleToggle}
                disabled={busy}
                className={cn('context-trigger', open && 'is-open', busy && 'is-busy')}
                aria-haspopup="listbox"
                aria-expanded={open}
                title={`${business?.name ?? workspace.name} — change workspace or business`}
            >
                <span className="context-trigger-mark">
                    {busy ? (
                        <Icon name="spinner-gap" size={14} weight="bold" className="animate-spin" />
                    ) : (
                        <BusinessBadge
                            business={business ? {
                                name: business.name,
                                short_code: business.short_code,
                                logo_url: business.logo_url,
                            } : { name: workspace.name }}
                            workspace={currentWorkspace}
                            size="sm"
                            showOverlay={!!business}
                            invertOverlay
                        />
                    )}
                </span>
                <span className="context-trigger-text">
                    <span className="context-trigger-name">{business?.name ?? workspace.name}</span>
                    <span className="context-trigger-meta">{workspace.name}</span>
                </span>
                <Icon name="caret-down" size={12} weight="bold" className="context-trigger-caret" />
            </button>

            {/* One sheet for both slots below, not one each: the submenu
                replaces the main menu rather than stacking on it, and two
                sheets would mean the first click after drilling down went to
                the wrong one. */}
            {open && (
                <FlyoutGuard onClose={handleClose} dismissOnOutsidePress={false} />
            )}

            {/* On mobile, opening a submenu drills down full-screen rather than
                floating beside a card with nowhere to sit — so the main menu
                steps aside while it is showing, in the same slot. */}
            {open && !(mobileFull && activeSubMenu) && createPortal(
                <div
                    ref={flyoutRef}
                    data-flyout-panel
                    className={cn('context-menu', exiting && 'is-exiting', mobileFull && 'context-menu-mobile')}
                    style={mobileFull ? undefined : { top: `${peekTop}px`, left: `${peekLeft}px` }}
                >
                    {busy && (
                        <div className="context-busy">
                            <Icon name="spinner-gap" size={20} weight="bold" className="animate-spin" />
                            <span>Switching…</span>
                        </div>
                    )}
                    <ContextFlyout
                        workspace={workspace}
                        currentWorkspace={currentWorkspace ?? null}
                        business={business}
                        busy={busy}
                        activeSubMenu={activeSubMenu}
                        onSubMenu={handleSubMenu}
                        onNavigate={handleClose}
                    />
                </div>,
                document.body,
            )}

            {open && activeSubMenu && !exiting && createPortal(
                <div
                    ref={subMenuRef}
                    data-flyout-panel
                    className={cn('context-submenu', mobileFull && 'context-submenu-mobile')}
                    style={mobileFull ? undefined : { top: `${subPos.top}px`, left: `${subPos.left}px` }}
                >
                    <ContextSubFlyout
                        type={activeSubMenu}
                        currentWorkspaceId={workspace.id}
                        currentBusinessId={business?.id ?? null}
                        workspaces={tenant.workspaces}
                        businesses={tenant.businesses}
                        allowance={tenant.allowance}
                        busy={busy}
                        onPick={openContext}
                        mobile={mobileFull}
                        onBack={() => setActiveSubMenu(null)}
                        onNavigate={handleClose}
                    />
                </div>,
                document.body,
            )}
        </div>
    );
}

/**
 * Main flyout — two sections, each with a title + Manage link, and the current
 * selection shown as a tappable option row that opens the submenu.
 */
function ContextFlyout({
    workspace,
    currentWorkspace,
    business,
    busy,
    activeSubMenu,
    onSubMenu,
    onNavigate,
}: {
    workspace: { id: string; name: string };
    currentWorkspace: WorkspaceSummary | null;
    business: { id: string; name: string; short_code: string | null; logo_url?: string | null } | null;
    busy: boolean;
    activeSubMenu: SubMenuType | null;
    onSubMenu: (type: SubMenuType, rect: DOMRect) => void;
    onNavigate: () => void;
}) {
    const navigate = useNavigate();

    const handleOpen = (type: SubMenuType) => (e: React.MouseEvent<HTMLButtonElement>) => {
        onSubMenu(type, e.currentTarget.getBoundingClientRect());
    };

    const goManage = (path: string) => (e: React.MouseEvent<HTMLButtonElement>) => {
        e.stopPropagation();
        navigate(path);
        onNavigate();
    };

    return (
        <div className="context-flyout">
            {/* Workspace section */}
            <div className="context-section">
                <div className="context-section-head">
                    <span className="context-section-title">Workspace</span>
                    <button
                        type="button"
                        onClick={goManage('/workspaces')}
                        className="context-manage"
                    >
                        Manage
                    </button>
                </div>
                <button
                    type="button"
                    disabled={busy}
                    onClick={handleOpen('workspaces')}
                    className={cn('context-option context-section-option', activeSubMenu === 'workspaces' && 'is-sub-open')}
                >
                    <span className="context-option-mark">
                        {currentWorkspace
                            ? <WorkspaceBadge workspace={currentWorkspace} size="sm" />
                            : <span>{workspace.name.slice(0, 2).toUpperCase()}</span>
                        }
                    </span>
                    <span className="context-option-text">
                        <span className="context-option-name">{workspace.name}</span>
                    </span>
                    <Icon name="caret-right" size={11} weight="bold" className="context-sub-caret" />
                </button>
            </div>

            {/* Border divider between sections */}
            <div className="context-section-sep" />

            {/* Business section */}
            <div className="context-section">
                <div className="context-section-head">
                    <span className="context-section-title">Business</span>
                    <button
                        type="button"
                        onClick={goManage('/businesses')}
                        className="context-manage"
                    >
                        Manage
                    </button>
                </div>
                <button
                    type="button"
                    disabled={busy}
                    onClick={handleOpen('businesses')}
                    className={cn('context-option context-section-option', activeSubMenu === 'businesses' && 'is-sub-open')}
                >
                    {business ? (
                        <>
                            <span className="context-option-mark">
                                <BusinessBadge business={business} size="sm" />
                            </span>
                            <span className="context-option-text">
                                <span className="context-option-name">{business.name}</span>
                            </span>
                        </>
                    ) : (
                        <span className="context-option-text">
                            <span className="context-option-name context-no-selection">None selected</span>
                        </span>
                    )}
                    <Icon name="caret-right" size={11} weight="bold" className="context-sub-caret" />
                </button>
            </div>
        </div>
    );
}

/**
 * Submenu panel — search, add button with plan-limit tooltip (hover), and
 * scrollable options list.
 */
function ContextSubFlyout({
    type,
    currentWorkspaceId,
    currentBusinessId,
    workspaces,
    businesses,
    allowance,
    busy,
    onPick,
    mobile = false,
    onBack,
    onNavigate,
}: {
    type: SubMenuType;
    currentWorkspaceId: string;
    currentBusinessId: string | null;
    workspaces: WorkspaceSummary[];
    businesses: {
        id: string;
        workspace: string | null;
        name: string;
        short_code: string | null;
        currency: string;
        logo_url?: string | null;
    }[];
    allowance: { plan: string | null; workspaces: PlanQuota; businesses: PlanQuota | null };
    busy: boolean;
    onPick: (workspaceId: string, businessId?: string) => void;
    mobile?: boolean;
    onBack?: () => void;
    onNavigate: () => void;
}) {
    const navigate = useNavigate();
    const [query, setQuery] = useState('');
    const [limitPos, setLimitPos] = useState<{ top: number; left: number } | null>(null);
    const limitIconRef = useRef<HTMLButtonElement>(null);

    const quota = type === 'workspaces' ? allowance.workspaces : allowance.businesses;
    const atLimit = quota !== null ? !quota.can_add : false;

    const filteredWorkspaces = useMemo(() => {
        if (type !== 'workspaces') return [];
        const term = query.trim().toLowerCase();
        return term ? workspaces.filter((w) => w.name.toLowerCase().includes(term)) : workspaces;
    }, [query, type, workspaces]);

    const filteredBusinesses = useMemo(() => {
        if (type !== 'businesses') return [];
        const term = query.trim().toLowerCase();
        const inWorkspace = businesses.filter((b) => b.workspace === currentWorkspaceId);
        return term ? inWorkspace.filter((b) => b.name.toLowerCase().includes(term)) : inWorkspace;
    }, [query, type, businesses, currentWorkspaceId]);

    const handleLimitEnter = () => {
        const rect = limitIconRef.current?.getBoundingClientRect();
        if (!rect) return;
        const tooltipWidth = 224;
        const margin = 8;
        let left = rect.right + margin;
        if (left + tooltipWidth > window.innerWidth - margin) {
            left = rect.left - margin - tooltipWidth;
        }
        // Vertically: align top of tooltip to icon, clamp to viewport
        let top = rect.top - 4;
        if (top < margin) top = margin;
        setLimitPos({ top, left });
    };

    const handleLimitLeave = () => setLimitPos(null);

    const managePath = type === 'workspaces' ? '/workspaces' : '/businesses';
    const placeholder = type === 'workspaces' ? 'Search workspaces' : 'Search businesses';
    const addLabel = type === 'workspaces' ? 'Add Workspace' : 'Add Business';
    const manageLabel = type === 'workspaces' ? 'Manage Workspaces' : 'Manage Businesses';
    const isEmpty = type === 'workspaces' ? filteredWorkspaces.length === 0 : filteredBusinesses.length === 0;
    const emptyMessage = query.trim()
        ? `Nothing matches "${query.trim()}".`
        : type === 'businesses' ? 'No businesses in this workspace.' : 'No workspaces found.';

    return (
        <div className="context-subflyout">
            {mobile && (
                <div className="context-subflyout-mobile-head">
                    <button
                        type="button"
                        onClick={onBack}
                        className="context-back"
                        aria-label="Back"
                    >
                        <Icon name="arrow-left" size={16} weight="duotone" />
                    </button>
                    <span className="context-subflyout-mobile-title">
                        {type === 'workspaces' ? 'Workspaces' : 'Businesses'}
                    </span>
                </div>
            )}
            <div className="context-search">
                <Icon name="magnifying-glass" size={14} weight="duotone" className="context-search-icon" />
                <input
                    autoFocus
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={placeholder}
                    className="context-search-input"
                    aria-label={placeholder}
                    disabled={busy}
                />
            </div>

            <div className="context-add-wrapper">
                <button
                    type="button"
                    onClick={() => { if (!atLimit) { navigate(managePath); onNavigate(); } }}
                    className={cn('context-add', atLimit && 'is-disabled')}
                    disabled={busy}
                >
                    <Icon name="plus" size={14} weight="bold" />
                    {addLabel}
                    {atLimit && quota && quota.limit !== null && (
                        <span className="context-add-note">{quota.used}/{quota.limit}</span>
                    )}
                </button>

                {atLimit && (
                    <button
                        ref={limitIconRef}
                        type="button"
                        onMouseEnter={handleLimitEnter}
                        onMouseLeave={handleLimitLeave}
                        className="context-limit-icon"
                        aria-label="Plan limit information"
                    >
                        <Icon name="warning-diamond" size={14} weight="duotone" />
                    </button>
                )}
            </div>

            <div className="context-list">
                {type === 'workspaces' && filteredWorkspaces.map((ws) => {
                    const isCurrent = ws.id === currentWorkspaceId;
                    return (
                        <button
                            key={ws.id}
                            type="button"
                            role="option"
                            aria-selected={isCurrent}
                            disabled={busy}
                            onClick={() => onPick(ws.id)}
                            className={cn('context-option', isCurrent && 'is-current')}
                        >
                            <span className="context-option-mark">
                                <WorkspaceBadge workspace={ws} size="sm" />
                            </span>
                            <span className="context-option-text">
                                <span className="context-option-name">{ws.name}</span>
                            </span>
                            {isCurrent && <Icon name="check" size={13} weight="bold" className="context-option-check" />}
                        </button>
                    );
                })}

                {type === 'businesses' && filteredBusinesses.map((biz) => {
                    const isCurrent = biz.id === currentBusinessId;
                    const meta = [biz.short_code, biz.currency].filter(Boolean).join(' · ');
                    return (
                        <button
                            key={biz.id}
                            type="button"
                            role="option"
                            aria-selected={isCurrent}
                            disabled={busy}
                            onClick={() => onPick(biz.workspace!, biz.id)}
                            className={cn('context-option', isCurrent && 'is-current')}
                        >
                            <span className="context-option-mark">
                                <BusinessBadge business={biz} size="sm" />
                            </span>
                            <span className="context-option-text">
                                <span className="context-option-name">{biz.name}</span>
                                {meta !== '' && <span className="context-option-meta">{meta}</span>}
                            </span>
                            {isCurrent && <Icon name="check" size={13} weight="bold" className="context-option-check" />}
                        </button>
                    );
                })}

                {isEmpty && <p className="context-empty">{emptyMessage}</p>}
            </div>

            <div className="context-subflyout-foot">
                <button
                    type="button"
                    onClick={(e) => { e.stopPropagation(); navigate(managePath); onNavigate(); }}
                    className="context-manage"
                >
                    {manageLabel}
                </button>
            </div>

            {/* Hover tooltip for plan limit — portaled to avoid clipping */}
            {limitPos && createPortal(
                <div
                    className="context-limit-tooltip"
                    style={{ top: `${limitPos.top}px`, left: `${limitPos.left}px` }}
                    onMouseEnter={() => setLimitPos(limitPos)}
                    onMouseLeave={handleLimitLeave}
                >
                    <div className="context-limit-head">
                        <Icon name="warning-diamond" size={14} weight="duotone" style={{ color: 'var(--color-warning)' }} />
                        <span className="context-limit-title">Plan limit reached</span>
                    </div>
                    <p className="context-limit-desc">
                        Your {allowance.plan ?? 'current'} plan allows{' '}
                        {quota?.limit !== null && quota?.limit !== undefined ? quota.limit : 'unlimited'}{' '}
                        {type}. Upgrade your plan to add more.
                    </p>
                </div>,
                document.body,
            )}
        </div>
    );
}
