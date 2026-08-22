/**
 * Money, formatted the way a reader expects it.
 *
 * ── One source for the symbol ────────────────────────────────────────────────
 *
 * Every figure and every heading takes its symbol from the same place: the
 * server's own currency table, handed over once in the boot payload. The
 * obvious alternative — `Intl.NumberFormat` with `style: 'currency'` — was
 * what produced the bug this replaces. Intl renders AED as "AED" in an English
 * locale while our table renders it "د.إ", so a panel heading and the values
 * beneath it disagreed about what the same currency is called, and which one
 * you got depended on which function happened to render it.
 *
 * So Intl is used for what it is unambiguously good at — grouping digits for
 * the reader's own locale — and the symbol is ours.
 */

// Mirrors Currencies::ZERO_DECIMAL / THREE_DECIMAL in
// app/Domain/Money/Currencies.php — how many minor units make one major unit,
// for the series that arrive as raw minor integers.
const ZERO_DECIMAL = new Set(['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XAF', 'XOF', 'XPF', 'KMF', 'RWF', 'UGX', 'VUV', 'GNF', 'PYG', 'BIF', 'DJF']);
const THREE_DECIMAL = new Set(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND']);

export function minorScale(currency: string): number {
    const code = currency.toUpperCase();

    if (ZERO_DECIMAL.has(code)) return 0;
    if (THREE_DECIMAL.has(code)) return 3;

    return 2;
}

/** Minor units back to major — 2888423 minor MYR → 28884.23. */
export function minorToMajor(minor: number, currency: string): number {
    return minor / 10 ** minorScale(currency);
}

function toNumber(value: string | number): number {
    return typeof value === 'string' ? Number.parseFloat(value) : value;
}

/**
 * A symbol and a number, joined the way that pair is normally written.
 *
 * A space after a lettered symbol ("RM 28,884") and none after a glyph
 * ("৳28,884", "$28,884"), which is how each is actually set.
 */
function join(symbol: string, digits: string): string {
    const needsSpace = /[\p{L}]$/u.test(symbol);

    return `${symbol}${needsSpace ? ' ' : ''}${digits}`;
}

/**
 * Grouped digits, no symbol — "28,884.23".
 *
 * `digits` fixes both the minimum and the maximum, because an exact money
 * figure wants its trailing zeroes: "৳871,089.1" is a number, "৳871,089.10"
 * is an amount, and the difference is whether the reader trusts it.
 */
export function formatNumber(value: string | number, digits = 2): string {
    const amount = toNumber(value);

    if (!Number.isFinite(amount)) {
        return String(value);
    }

    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(amount);
}

/**
 * A plain compacted number — "0", "30K", "1.2M", "30B". No symbol.
 *
 * For an axis, where repeating the symbol on every one of five labels says
 * the same thing five times and steals the width the digits need.
 */
export function formatCompactNumber(value: string | number): string {
    const amount = toNumber(value);

    if (!Number.isFinite(amount)) {
        return String(value);
    }

    // Below a thousand there is nothing to compact, and "0.5K" reads worse
    // than "500" ever did.
    if (Math.abs(amount) < 1000) {
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(amount);
    }

    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(amount);
}

/** A chart's raw minor-unit integer as a plain compacted number. */
export function formatMinorCompactNumber(minor: number, currency: string): string {
    return formatCompactNumber(minorToMajor(minor, currency));
}

/** The full figure with its symbol — "RM 28,884.23". */
export function formatMoneyWith(symbol: string, value: string | number, digits = 2): string {
    return join(symbol, formatNumber(value, digits));
}

/** The same, compacted — "RM 28.9K". */
export function formatMoneyShortWith(symbol: string, value: string | number): string {
    return join(symbol, formatCompactNumber(value));
}

/**
 * Both renderings of one amount.
 *
 * A dashboard wants the short one on screen — a column of "RM 1,284,993.40"
 * is unreadable at a glance and pushes every card wider than it needs to be —
 * and the exact one within reach, which is what the `title` is for. Returning
 * the pair together means a caller cannot show one without having the other.
 */
export function money(symbol: string, value: string | number): { short: string; exact: string } {
    return {
        short: formatMoneyShortWith(symbol, value),
        exact: formatMoneyWith(symbol, value),
    };
}
