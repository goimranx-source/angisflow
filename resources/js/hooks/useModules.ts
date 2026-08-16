import { useQuery } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { useSession } from '@/providers/SessionProvider';
import type { PlannedModule } from '@/types';

/**
 * The module catalogue — every department mapped out but not yet built.
 *
 * ── Why the version is in the URL ────────────────────────────────────────────
 *
 * This answer is identical for every user and changes only on a deploy, so it
 * wants to be cached hard. A plain max-age does that and gets it wrong: promote
 * a module from "coming soon" to built, ship it, and every browser that has
 * already asked keeps the old answer until the header happens to expire — with
 * the menu pointing at a placeholder page for a screen that now exists.
 *
 * Putting the catalogue's fingerprint in the URL makes the response immutable
 * by construction. Change the catalogue and the URL changes, so the browser has
 * nothing stale to serve; leave it alone and the request never leaves the
 * machine again.
 */
export function useModules() {
    const { config } = useSession();

    return useQuery({
        queryKey: ['modules', config.catalogue_version],
        queryFn: ({ signal }) =>
            api.get<{ version: string; data: PlannedModule[] }>('/modules', {
                params: { v: config.catalogue_version },
                signal,
            }),
        // The URL already guarantees freshness, so there is nothing to
        // revalidate for the life of the tab.
        staleTime: Infinity,
        gcTime: Infinity,
    });
}
