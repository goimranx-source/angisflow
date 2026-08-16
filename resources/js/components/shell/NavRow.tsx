import type { MouseEvent } from 'react';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { queryClient } from '@/lib/query';
import { cn } from '@/lib/utils';
import { pageKeyForPath, prefetchPage } from '@/router';
import { useNavigationLoading } from '@/providers/NavigationLoadingProvider';
import type { NavItem } from '@/types';

/**
 * Warm a page before the user asks for it.
 *
 * Two things, and both matter: the page's own chunk, so the component exists
 * when the click lands rather than being fetched after it; and the query it
 * will run, so it renders with figures rather than a skeleton. The gap between
 * a pointer resting on a menu item and the click that follows is a few hundred
 * milliseconds — comfortably longer than either takes.
 *
 * Bounded to the sidebar on purpose. Prefetching every link on a page would
 * mean a table of two hundred rows firing two hundred requests at whatever
 * speed the pointer travels across it.
 */
export function warmRoute(href: string): void {
    const key = pageKeyForPath(href);

    if (key) {
        prefetchPage(key);
    }

    if (href === '/dashboard') {
        void queryClient.prefetchQuery({
            queryKey: ['dashboard', 'summary', 'this_month'],
            // A static import: the client is in the entry chunk already, so
            // deferring it bought nothing and split the module in two.
            queryFn: ({ signal }) => api.get('/dashboard', { params: { period: 'this_month' }, signal }),
        });
    }
}

/** The paused mark a department that is planned but not built carries. */
export function SoonMark() {
    return (
        <span className="nav-soon" title="Not built yet" aria-label="not built yet">
            <Icon name="pause-circle" size={13} weight="fill" />
        </span>
    );
}

type PinProps = {
    itemKey: string;
    pinned: boolean;
    onToggle: (key: string) => void;
    label: string;
};

export function PinButton({ itemKey, pinned, onToggle, label }: PinProps) {
    const click = (event: MouseEvent) => {
        // The row is a link. Without both of these, pinning navigates.
        event.preventDefault();
        event.stopPropagation();
        onToggle(itemKey);
    };

    return (
        <button
            type="button"
            onClick={click}
            className={cn('nav-pin', pinned && 'is-pinned')}
            // Short on the pointer, specific for a screen reader. A tooltip
            // reading "Pin Receivable & Payable to the top" is a paragraph
            // hovering over a 17px button.
            title={pinned ? 'Unpin this' : 'Pin this'}
            aria-label={pinned ? `Unpin ${label}` : `Pin ${label} to the top`}
        >
            <Icon name="push-pin" size={12} weight="fill" />
        </button>
    );
}

type FlatRowProps = {
    item: NavItem;
    active: boolean;
    pinned: boolean;
    onTogglePin: (key: string) => void;
};

/** A top-level page — the workspace, the dashboard. */
export function NavFlat({ item, active, pinned, onTogglePin }: FlatRowProps) {
    const { startNavigation } = useNavigationLoading();
    
    return (
        <Link
            to={item.href}
            className={cn('nav-flat', active && 'active')}
            aria-current={active ? 'page' : undefined}
            title={item.label}
            onMouseEnter={() => warmRoute(item.href)}
            onFocus={() => warmRoute(item.href)}
            onTouchStart={() => warmRoute(item.href)}
            onClick={() => !active && startNavigation()}
        >
            <span className="nav-icon">
                <Icon name={item.icon} size={18} weight="duotone" />
            </span>
            <span className="nav-label">{item.label}</span>
            {!item.built && <SoonMark />}
            <PinButton itemKey={item.key} pinned={pinned} onToggle={onTogglePin} label={item.label} />
        </Link>
    );
}

type ChildRowProps = {
    item: NavItem;
    active: boolean;
    pinned: boolean;
    onTogglePin: (key: string) => void;
};

/** A page inside a department. */
export function NavChild({ item, active, pinned, onTogglePin }: ChildRowProps) {
    const { startNavigation } = useNavigationLoading();
    
    return (
        <Link
            to={item.href}
            className={cn('nav-child', active && 'active')}
            aria-current={active ? 'page' : undefined}
            title={item.built ? item.summary : `${item.summary} — not built yet`}
            onMouseEnter={() => warmRoute(item.href)}
            onFocus={() => warmRoute(item.href)}
            onTouchStart={() => warmRoute(item.href)}
            onClick={() => !active && startNavigation()}
        >
            <Icon name={item.icon} size={14} weight="duotone" className="nav-child-icon" />
            <span className="nav-label">{item.label}</span>
            {!item.built && <SoonMark />}
            <PinButton itemKey={item.key} pinned={pinned} onToggle={onTogglePin} label={item.label} />
        </Link>
    );
}

