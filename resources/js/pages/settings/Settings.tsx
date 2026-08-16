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
    appearance: lazy(() => import('@/pages/settings/AppearancePanel')),
    currency: lazy(() => import('@/pages/settings/CurrencyPanel')),
    media: lazy(() => import('@/pages/settings/MediaPanel')),
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
    const active = (group ?? 'appearance') as SettingsGroupKey;

    useDocumentTitle('Settings');

    // The tab strip itself: which groups exist, and which this person may open.
    // Cached for the session — it changes only when their role does.
    const { data, isPending } = useQuery({
        queryKey: ['settings', 'groups'],
        queryFn: ({ signal }) => api.get<{ data: SettingsGroup[] }>('/settings', { signal }),
        staleTime: 5 * 60 * 1000,
    });

    const groups = data?.data ?? [];
    const current = groups.find((candidate) => candidate.key === active);
    const Panel = panels[active] ?? panels.appearance;

    return (
        <div className="mx-auto max-w-5xl">
            <PageHeader
                title="Settings"
                description="How the tool looks, what it counts in, and what it connects to."
            />

            <div className="mb-5 border-b border-[var(--color-border-light)]">
                <nav className="-mb-px flex flex-wrap gap-1" aria-label="Settings sections">
                    {isPending
                        ? Array.from({ length: 4 }, (_, i) => (
                              <span key={i} className="mb-2 h-8 w-28 animate-pulse rounded-lg bg-[var(--color-brand-subtle)]" />
                          ))
                        : groups.map((tab) => (
                              <NavLink
                                  key={tab.key}
                                  to={tab.key === 'appearance' ? '/settings' : `/settings/${tab.key}`}
                                  // `end` on the index tab only, so /settings
                                  // does not stay highlighted on every child.
                                  end={tab.key === 'appearance'}
                                  className={({ isActive }) =>
                                      cn(
                                          'flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                                          isActive
                                              ? 'border-[var(--color-brand)] text-[var(--color-text-main)]'
                                              : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                                      )
                                  }
                              >
                                  <Icon name={tab.icon} size={16} weight="regular" />
                                  {tab.label}
                              </NavLink>
                          ))}
                </nav>
            </div>

            {current && (
                <p className="mb-4 text-sm text-[var(--color-text-muted)]">{current.blurb}</p>
            )}

            {/* Nothing while a chunk resolves. The tab strip is already on
                screen and the panel usually arrives on the next frame; a
                spinner would flash and announce a load nobody experienced. */}
            <Suspense fallback={null}>
                <Panel />
            </Suspense>
        </div>
    );
}
