import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';

import { ErrorBoundary } from '@/components/ErrorBoundary';
import { Preloader } from '@/components/ui/Preloader';
import { queryClient } from '@/lib/query';
import { SessionProvider } from '@/providers/SessionProvider';
import { router } from '@/router';

/**
 * The client.
 *
 * ── What happens on a cold load, in order ────────────────────────────────────
 *
 *   1. The server sends one HTML document with the boot payload already in it.
 *   2. The browser paints the background and the rail at its stored width,
 *      before any JavaScript runs, from the inline script in <head>.
 *   3. This file mounts. The session is read out of the document — no request —
 *      so the sidebar, the header and the business name are correct on the very
 *      first frame.
 *   4. The route's chunk resolves and the page appears inside the shell.
 *   5. Only then does anything hit the network: the page asks the API for its
 *      figures, and the session is quietly revalidated.
 *
 * ── And on every navigation after that ───────────────────────────────────────
 *
 * Nothing hits the server for the page at all. The router swaps what is inside
 * the shell; the chunk is usually already in memory because the sidebar
 * prefetched it on hover; the data is usually already in the query cache
 * because the screen was open a minute ago. Where all three hold — which is the
 * common case — a click paints on the next frame and the network stays silent.
 *
 * ── Why the two providers sit above the router ───────────────────────────────
 *
 * Both hold state that must outlive any single screen. The query cache is what
 * makes returning to a page instant, and the session is what the shell is drawn
 * from; either one mounted inside the router would be torn down and rebuilt on
 * navigation, and every screen would start from an empty cache and an unknown
 * user. That is the default behaviour this whole architecture exists to avoid.
 */

const container = document.getElementById('app');

if (container) {
    try {
        createRoot(container).render(
            <StrictMode>
                <ErrorBoundary>
                    <Preloader />
                    <QueryClientProvider client={queryClient}>
                        <SessionProvider>
                            <RouterProvider router={router} />
                        </SessionProvider>
                    </QueryClientProvider>
                </ErrorBoundary>
            </StrictMode>,
        );
    } catch (error) {
        console.error('❌ Error mounting React app:', error);
        throw error;
    }
} else {
    console.error('❌ Container #app not found!');
}
