import { QueryClient } from '@tanstack/react-query';

import { ApiError } from '@/lib/api';

/**
 * The cache that makes navigation feel instant.
 *
 * ── What actually produces "it doesn't load" ─────────────────────────────────
 *
 * Three settings below, and they matter more than any amount of server tuning:
 *
 *   staleTime      How long data counts as fresh. At 0 — the library default —
 *                  every mount refetches, so returning to a screen you opened
 *                  ten seconds ago costs a round trip and shows a spinner. At
 *                  30 seconds, that return renders from memory on the same
 *                  frame it mounts. Nothing is fetched, nothing flashes.
 *
 *   gcTime         How long unused data survives in memory. Five minutes means
 *                  a screen you visited, left, and came back to is still there.
 *                  This is the difference between a back button that feels
 *                  native and one that feels like a page load.
 *
 *   placeholderData Keeps the previous result on screen while a new one is
 *                  fetched, so changing a filter or a page does not blank the
 *                  table and reflow the layout. The old rows stay, dimmed,
 *                  until the new ones replace them.
 *
 * The pattern behind all three is stale-while-revalidate: show what we have,
 * immediately, and correct it when the truth arrives. It is only wrong for data
 * where a stale reading would be dangerous, and those queries opt out
 * individually rather than everything paying for them.
 */

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            gcTime: 5 * 60_000,

            // The window regaining focus is not evidence anything changed, and
            // refetching on it means a user with the tab open all day generates
            // a request every time they alt-tab. Reconnecting is different —
            // that one genuinely implies missed time.
            refetchOnWindowFocus: false,
            refetchOnReconnect: true,

            // refetchOnMount is deliberately left at its default of true, which
            // means "refetch on mount *if the data is stale*" — not "refetch on
            // every mount". staleTime above is what actually decides that, and
            // it already gives the instant return this file is written around.
            //
            // It used to be set to false here, which reads like a stronger
            // version of the same idea and is in fact a different and much
            // worse one: a query nobody currently has on screen would never
            // refetch again, so invalidateQueries against an unmounted screen
            // did nothing at all. Delete a business and the workspaces list
            // went on showing the old count for as long as the tab stayed open.
            //
            // That was not a bug in one page. Every cross-page mutation in the
            // app was quietly failing to refresh the pages it affected.

            retry: (failureCount, error) => {
                // Never retry something the server has already answered
                // definitively. A 403 will be a 403 again, and retrying a 422
                // just sends the same invalid form three times.
                if (error instanceof ApiError) {
                    if (error.status < 500 && error.status !== 429) {
                        return false;
                    }
                }

                return failureCount < 2;
            },

            // Backs off rather than hammering a server that is already
            // struggling — which is how a brief wobble becomes an outage.
            retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
        },

        mutations: {
            retry: false,
        },
    },
});

/**
 * Query keys, in one place.
 *
 * Written as a tree so a whole branch can be invalidated at once —
 * `queryClient.invalidateQueries({ queryKey: keys.orders.all })` drops every
 * order list and detail without anybody having to remember what they were
 * called. Keys built ad hoc at each call site are how a cache ends up with data
 * nobody can find to invalidate.
 */
export const keys = {
    session: ['session'] as const,

    dashboard: {
        all: ['dashboard'] as const,
        summary: (period: string) => ['dashboard', 'summary', period] as const,
    },
} as const;

/** Everything cached belongs to one business. Switching them empties it. */
export function resetCacheForBusinessSwitch(): void {
    queryClient.clear();
}
