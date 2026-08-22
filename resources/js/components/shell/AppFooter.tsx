import { useSession } from '@/providers/SessionProvider';

/**
 * The line at the foot of every page.
 *
 * ── Why it lives in the layout ───────────────────────────────────────────────
 *
 * Written per page it would be on most of them and missing from the two
 * somebody forgot, which is how a product ends up looking half-finished in
 * exactly the places nobody demos. In the layout it is on every page by
 * construction, including the ones written after this.
 *
 * The name comes from the boot payload rather than a literal, so a
 * white-labelled account sees its own; the year comes from the clock rather
 * than a constant, because a copyright line that needs a developer every
 * January is a copyright line that will be wrong every January.
 */
export function AppFooter() {
    const { app } = useSession();

    return (
        <footer className="mt-8 border-t border-[var(--shell-border)] px-1 py-4 text-center">
            <p className="text-xs text-[var(--color-text-muted)]">
                {new Date().getFullYear()} © {app.name}. All Rights Reserved.
            </p>
        </footer>
    );
}
