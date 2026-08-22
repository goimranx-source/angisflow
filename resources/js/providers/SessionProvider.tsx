import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { api, setApiBusinessScope, setApiMoneyScope, setUnauthenticatedHandler } from '@/lib/api';
import { readBootPayload } from '@/lib/boot';
import { queryClient } from '@/lib/query';
import type { BootPayload, Capability } from '@/types';

type SessionValue = BootPayload & {
    /** Replace the whole shell — after signing in, or switching business. */
    apply: (boot: BootPayload) => void;
    /** Ask the server for the current shell. */
    refresh: () => Promise<void>;
    /** Forget everything, locally, without waiting for the server. */
    clear: () => void;
    can: (capability: Capability) => boolean;
};

const SessionContext = createContext<SessionValue | null>(null);

/**
 * Who is signed in, whose books, and the menu.
 *
 * ── Seeded from the document, not from a request ─────────────────────────────
 *
 * The initial value is the payload the server inlined into the HTML, so the
 * first render already has everything the shell needs. No loading state, no
 * skeleton sidebar, no flash of a signed-out layout before the session
 * resolves — the three things that make a single-page application feel slower
 * than the server-rendered thing it replaced.
 *
 * ── And revalidated once, quietly ────────────────────────────────────────────
 *
 * A document can be older than it looks: served from the browser's
 * back-forward cache, or restored with a tab from yesterday. So the payload is
 * re-fetched after mount and swapped in if it differs. The user never sees this
 * happen unless something actually changed.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
    const [boot, setBoot] = useState<BootPayload>(() => {
        const initial = readBootPayload();

        // Before the first request rather than in an effect after it. An effect
        // runs after the first render, and the first render is what fires the
        // dashboard's queries — so those would go out unscoped and be cached
        // against a URL with no business in it.
        setApiBusinessScope(initial.tenant?.business?.id ?? null);
        setApiMoneyScope(initial.money?.scope ?? null);

        return initial;
    });

    const apply = useCallback((next: BootPayload) => {
        // Ahead of the state change, so the re-render this triggers already has
        // the new scope to make its requests with. The other order leaves one
        // render's worth of queries pointed at the business just left.
        setApiBusinessScope(next.tenant?.business?.id ?? null);
        setApiMoneyScope(next.money?.scope ?? null);
        setBoot(next);
    }, []);

    const refresh = useCallback(async () => {
        try {
            apply(await api.get<BootPayload>('/bootstrap'));
        } catch {
            // Offline, or the server is having a moment. What is on screen is
            // the last thing known to be true, which is a better answer than
            // blanking the shell.
        }
    }, [apply]);

    const clear = useCallback(() => {
        setBoot((current) => ({ ...current, auth: null, tenant: null, nav: [] }));
        queryClient.clear();
    }, []);

    // Whatever the API says about the session wins over what this thinks. A
    // 401 from any call anywhere ends the session here too, so a tab left open
    // overnight does not sit there showing a sidebar for somebody who is no
    // longer signed in.
    useEffect(() => {
        setUnauthenticatedHandler(() => {
            clear();

            // A full navigation rather than a client-side one. The session is
            // gone, so the CSRF token and every cookie need re-issuing, and
            // letting the server render the sign-in document is the one way to
            // be certain nothing stale survives.
            if (!window.location.pathname.startsWith('/login')) {
                window.location.href = '/login';
            }
        });
    }, [clear]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    // The tab icon follows the subscriber's setting.
    //
    // Swapped rather than added: browsers keep the *first* icon they were
    // given, so appending a second link leaves the default showing and the
    // setting looks like it did nothing.
    useEffect(() => {
        const href = boot.app.favicon;

        if (!href) {
            return;
        }

        const link =
            document.querySelector<HTMLLinkElement>('link[rel="icon"]') ??
            document.head.appendChild(Object.assign(document.createElement('link'), { rel: 'icon' }));

        link.href = href;
        // Cleared so the browser stops inferring a type from the old extension.
        link.removeAttribute('type');
    }, [boot.app.favicon]);

    const value = useMemo<SessionValue>(() => {
        // Built once per capability list rather than on every call, so a screen
        // asking thirty times does thirty hash lookups and no array scans.
        const capabilities = new Set(boot.auth?.capabilities ?? []);

        return {
            ...boot,
            apply,
            refresh,
            clear,
            // Not a security boundary — every one of these is checked again on
            // the server, on every request. It is how the interface avoids
            // offering somebody a button that will refuse them, which reads as
            // the tool being broken rather than as a permission they lack.
            can: (capability) => capabilities.has('*') || capabilities.has(capability),
        };
    }, [boot, apply, refresh, clear]);

    return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
    const value = useContext(SessionContext);

    if (value === null) {
        throw new Error('useSession must be used inside SessionProvider.');
    }

    return value;
}

export function useAuth() {
    return useSession().auth;
}

export function useTenant() {
    return useSession().tenant;
}

export function useCan() {
    return useSession().can;
}
