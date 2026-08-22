import { useMemo } from 'react';

import { formatMoneyShortWith, formatMoneyWith, minorScale, minorToMajor } from '@/lib/money';
import { useSession } from '@/providers/SessionProvider';

/**
 * What this workspace reports money in, and how to render it.
 *
 * ── Why every money figure comes through here ────────────────────────────────
 *
 * Every amount on a dashboard has already been converted into the workspace's
 * reporting currency by the time it reaches the client — see
 * CurrencyService::base() and the dashboard endpoints. So the currency is a
 * property of the session, not of each panel's payload, and reading it from
 * one place is what stops a heading saying "AED" while the figures beneath it
 * say "د.إ".
 *
 * ── And why `scope` is keyed into every money-bearing query ──────────────────
 *
 * Changing the reporting currency changes what every figure means and changes
 * none of the URLs they arrive on. Without the scope in the key, React Query
 * answers the refetch from its own cache and the browser answers from its
 * fifteen-second one — both in the currency just left. That is the "it only
 * corrects itself after a reload" fault. See BootPayload::money().
 */
export function useMoney() {
    const { money } = useSession();

    const symbol = money?.symbol ?? '';
    const base = money?.base ?? '';
    const scope = money?.scope ?? 'no-currency';

    return useMemo(
        () => ({
            base,
            symbol,
            /** Key this into every query whose answer contains money. */
            scope,

            /** "RM 28,884.23" — the exact figure, at this currency's own
             *  precision (none for yen, three for dinar). */
            format: (value: string | number) =>
                formatMoneyWith(symbol, value, minorScale(base)),

            /** "RM 28.9K" — for a card or a row, with `exact` in the title. */
            short: (value: string | number) => formatMoneyShortWith(symbol, value),

            /** Both, for somewhere that shows one and titles it with the other. */
            both: (value: string | number) => ({
                short: formatMoneyShortWith(symbol, value),
                exact: formatMoneyWith(symbol, value),
            }),

            /** A chart's raw minor-unit integer, compacted and symbol-less. */
            fromMinor: (minor: number) => minorToMajor(minor, base),
        }),
        [base, symbol, scope],
    );
}
