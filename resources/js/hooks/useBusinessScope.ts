import { useSession } from '@/providers/SessionProvider';

/**
 * The open business, as something to key a cache on.
 *
 * ── Why every business-scoped query has to carry this ────────────────────────
 *
 * Switching business calls queryClient.clear() (see useOpenBusiness), which
 * empties React Query — and that is genuinely enough for React Query. It is not
 * enough on its own, for two reasons.
 *
 * The dashboard's figures come back with `Cache-Control: private, max-age=15`.
 * The URL for "this month's revenue" is the same string whichever business is
 * open, so for fifteen seconds after a switch the browser is entitled to answer
 * out of its own cache — with the previous business's money, under the new
 * business's name, having never reached the server. Clearing a cache in
 * JavaScript does not reach into the HTTP one.
 *
 * And it puts the guarantee in one place instead of on one code path. Every
 * deliberate switch goes through useOpenBusiness today; the moment something
 * changes tenancy by another route — a redirect after a business is deleted, a
 * session resumed on another tab — the clear() is skipped and nothing else
 * stops one business's numbers rendering under another's name.
 *
 * Keyed, both problems are structural rather than remembered: a query for
 * business A cannot be read as an answer for business B, because they are not
 * the same query and not the same URL.
 *
 * Returns a stable placeholder rather than null when no business is open, so a
 * key never contains undefined — which React Query treats as a different key on
 * every render.
 */
export function useBusinessScope(): string {
    return useSession().tenant?.business?.id ?? 'no-business';
}
