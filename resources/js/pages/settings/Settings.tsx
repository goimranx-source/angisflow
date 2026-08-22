import { useQuery } from '@tanstack/react-query';
import { lazy, Suspense } from 'react';
import { NavLink, useParams } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { SettingsGroup, SettingsGroupKey } from '@/types/settings';

// Each tab is its own chunk. Somebody adjusting a logo never downloads the
// currency table, and somebody on the currency tab never downloads the media
// grid — which is the same reasoning as the route-level splitting, one level
// further in.
const panels = {
    currency: lazy(() => import('@/pages/settings/CurrencyPanel')),
    integrations: lazy(() => import('@/pages/settings/IntegrationsPanel')),
} as const;

/**
 * Settings.
 *
 * ── Tabs are addresses, not state ────────────────────────────────────────────
 *
 * /settings/currency is a real URL: it can be linked to, bookmarked, and the
 * back button returns to the tab you came from. A purely client-side tab strip
 * cannot do any of that, and the first thing anybody does with a settings page
 * is send somebody else a link to the bit they mean.
 *
 * ── Each tab fetches only itself ─────────────────────────────────────────────
 *
 * The first version of this page sent every tab's data on every switch — a
 * third of a megabyte to show a dozen exchange rates. Here the group is in the
 * URL, the endpoint answers for that group alone, and each is cached under its
 * own key, so going back to a tab you have already opened costs nothing.
 */
export default function Settings() {
    const { group } = useParams<{ group?: string }>();
    const active = (group ?? 'currency') as SettingsGroupKey;

    useDocumentTitle('Settings');

    // The tab strip itself: which groups exist, and which this person may open.
    // Cached for the session — it changes only when their role does.
    const { data, isPending } = useQuery({
        queryKey: ['settings', 'groups'],
        queryFn: ({ signal }) => api.get<{ data: SettingsGroup[] }>('/settings', { signal }),
        staleTime: 5 * 60 * 1000,
    });

    const groups = data?.data ?? [];
    const Panel = panels[active as keyof typeof panels] ?? panels.currency;

    return (
        <div className="mx-auto max-w-5xl">
            {/* Title alone. The tab strip immediately below already says what
                is in here, and a sentence restating it is a line of furniture
                between the heading and the thing people came to change. */}
            <PageHeader title="Settings" />

            <div className="mb-5 border-b border-[var(--color-border-light)]">
                <nav className="-mb-px flex flex-wrap gap-1" aria-label="Settings sections">
                    {isPending
                        ? Array.from({ length: 4 }, (_, i) => (
                              <span key={i} className="mb-2 h-8 w-28 animate-pulse rounded-lg bg-[var(--color-brand-subtle)]" />
                          ))
                        : groups.map((tab) => (
                              // isActive is computed against `active` rather than
                              // left to NavLink's own path matching. `/settings`
                              // with no group segment renders the currency panel
                              // (see the `active` fallback above), but its own
                              // href is `/settings/currency` — a path NavLink
                              // never sees you as being on, so the tab strip
                              // showed no tab selected while the currency panel
                              // was plainly on screen.
                              <NavLink
                                  key={tab.key}
                                  to={tab.key === 'appearance' ? '/settings' : `/settings/${tab.key}`}
                                  className={cn(
                                      'flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                                      tab.key === active
                                          ? 'border-[var(--color-brand)] text-[var(--color-text-main)]'
                                          : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                                  )}
                              >
                                  <Icon name={tab.icon} size={16} weight="regular" />
                                  {tab.label}
                              </NavLink>
                          ))}
                </nav>
            </div>

            {/* The tab's own blurb is gone too. Each card below now carries its
                explanation behind an info icon, so this line was a third
                restatement — page title, tab label, then a sentence — before
                anything editable appeared. */}

            {/* Nothing while a chunk resolves. The tab strip is already on
                screen and the panel usually arrives on the next frame; a
                spinner would flash and announce a load nobody experienced. */}
            <Suspense fallback={null}>
                <Panel />
            </Suspense>
        </div>
    );
}
