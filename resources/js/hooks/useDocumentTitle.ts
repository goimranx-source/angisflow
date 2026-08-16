import { useEffect } from 'react';

import { useSession } from '@/providers/SessionProvider';

/**
 * What the browser tab says.
 *
 * In a server-rendered app the title comes with the page and is right by
 * construction. Here it does not: the document is rendered once and never
 * again, so a screen that forgets to set one leaves whatever the *previous*
 * screen set — which is how a tab ends up reading "Profile" while showing
 * Reports, and how a browser history entry is filed under the wrong name.
 *
 * A hook rather than a helmet library: it is one assignment in an effect, and a
 * dependency for that would cost more bytes than every page using it.
 *
 * Pass null while data is still loading, and the title is left alone until
 * there is something true to say.
 */
export function useDocumentTitle(title: string | null): void {
    const { app } = useSession();

    useEffect(() => {
        if (title === null) {
            return;
        }

        document.title = `${title} — ${app.name}`;
    }, [title, app.name]);
}
