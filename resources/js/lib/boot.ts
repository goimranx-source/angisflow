import type { BootPayload } from '@/types';

/**
 * The payload the server inlined into the document.
 *
 * ── Why this exists rather than a fetch on start-up ──────────────────────────
 *
 * The usual single-page application cold load is: HTML (empty), bundle (still
 * empty), run it, *then* ask the server who is signed in, wait, and finally
 * draw the sidebar. That last request cannot be started any earlier, because it
 * does not exist until the JavaScript is already running — which is why so many
 * SPAs show a skeleton where their navigation should be.
 *
 * Reading it out of the document removes the step. By the time React renders
 * its first frame it already knows the user, the business, the capabilities and
 * the whole menu.
 *
 * It is revalidated in the background straight afterwards, so a document served
 * from the browser's back-forward cache — which can be minutes or hours old —
 * corrects itself without ever showing an empty shell.
 */
export function readBootPayload(): BootPayload {
    const element = document.getElementById('prism-boot');

    if (element?.textContent) {
        try {
            return JSON.parse(element.textContent) as BootPayload;
        } catch {
            // Malformed for some reason we cannot do anything about here. The
            // fallback below keeps the application mountable, and the
            // revalidation that follows will replace it with the truth.
        }
    }

    return {
        app: { name: 'Prism', tagline: '', logo: null, logo_mark: null, favicon: null, show_logo: false },
        platform: { name: 'Prism', tagline: null, logo: null, logo_mark: null },
        config: {
            registration_enabled: true,
            turnstile_site_key: null,
            catalogue_version: 'unknown',
            // Off in the fallback: this shape only exists when the real payload
            // failed to parse, and offering a feature we cannot confirm is
            // configured is how you get a button that only ever errors.
            assistant_enabled: false,
        },
        auth: null,
        tenant: null,
        nav: [],
        todo_marks: {},
    };
}
