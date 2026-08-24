import { NavLink } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';
import { useNavigationLoading } from '@/providers/NavigationLoadingProvider';

/**
 * The five places that exist above any one business.
 *
 * Home points at /home, not /. Both render the same screen for somebody signed
 * in — / is the public landing that swaps to Home once you are — but /home is
 * where signing in actually lands you, and this link used to point at / with
 * `end: true`. The result was that the moment after logging in, no item in the
 * sidebar was highlighted at all: the URL was /home and the only Home link in
 * the rail was matching / exactly. You arrived nowhere, according to the menu.
 */
export const PRIMARY_ITEMS = [
    { to: '/home', icon: 'house', label: 'Home', end: true },
    { to: '/workspaces', icon: 'briefcase', label: 'Workspaces', end: false },
    { to: '/businesses', icon: 'buildings', label: 'Businesses', end: false },
    { to: '/inbox', icon: 'chats-circle', label: 'Inbox', end: false },

    /*
     * Settings belongs here rather than in a business's own menu.
     *
     * What it holds -- the account, its people, its billing, the look of the
     * app -- is true across every business, so reaching it from inside one and
     * not another was a distinction with nothing behind it.
     *
     * It also fills the strip. Four squares spread across the rail's width sat
     * so far apart they stopped reading as one group; five close the gaps.
     */
    { to: '/settings', icon: 'gear', label: 'Settings', end: false },
] as const;

/**
 * The account-level menu — what you see before you have opened a business.
 *
 * ── Why it collapses rather than disappears ──────────────────────────────────
 *
 * Once a business is open the rail belongs to that business's modules, and
 * forty of those under four account-level entries would bury them. But removing
 * the four outright would leave no way back except the browser's back button:
 * somebody three pages into Stock has to be able to reach another business
 * without retracing their steps.
 *
 * So it shrinks to a row of icons and stays. Full rows when the rail is the
 * account's, a single strip when it is a business's — present either way.
 */
export function PrimaryNav({ compact }: { compact: boolean }) {
    const { startNavigation } = useNavigationLoading();
    
    if (compact) {
        return (
            <div className="primary-strip">
                {PRIMARY_ITEMS.map((item) => (
                    <NavLink
                        key={item.to}
                        to={item.to}
                        end={item.end}
                        className={({ isActive }) => cn('primary-chip', isActive && 'is-active')}
                        title={item.label}
                        aria-label={item.label}
                        onClick={({ currentTarget }) => {
                            const isActive = currentTarget.classList.contains('is-active');
                            if (!isActive) startNavigation();
                        }}
                    >
                        <Icon name={item.icon} size={17} weight="duotone" />
                    </NavLink>
                ))}
            </div>
        );
    }

    return (
        <div className="primary-list">
            {PRIMARY_ITEMS.map((item) => (
                <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className={({ isActive }) => cn('nav-flat', isActive && 'active')}
                    title={item.label}
                    onClick={({ currentTarget }) => {
                        const isActive = currentTarget.classList.contains('active');
                        if (!isActive) startNavigation();
                    }}
                >
                    <span className="nav-icon">
                        <Icon name={item.icon} size={20} weight="duotone" />
                    </span>
                    <span className="nav-label truncate">{item.label}</span>
                </NavLink>
            ))}
        </div>
    );
}
