import { cn } from '@/lib/utils';

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Every state in the product, mapped to one of five tones.
 *
 * ── Why one table and not a helper per page ──────────────────────────────────
 *
 * "Paid" on an invoice, "delivered" on an order and "active" on a member are
 * the same fact wearing three words: this went the way it was meant to. Left to
 * each page they became three greens, because each page picked its own — which
 * is exactly the drift the rebuild exists to remove. Deciding it once here means
 * a colour in a table cell means the same thing on every screen in the tool.
 *
 * The four tones, in the words a person would use:
 *
 *   success   it worked, or it is live
 *   warning   it needs somebody, but nothing is wrong yet
 *   danger    it failed, lapsed, or was called off
 *   info      it is in flight — sent, moving, booked for later
 *   neutral   it is over, or it never applied
 *
 * Keys are compared lowercased with spaces and hyphens folded to underscores,
 * so 'On Leave', 'on-leave' and 'on_leave' all land in the same place.
 */
const TONES: Record<string, StatusTone> = {
    // Worked, or live
    active: 'success',
    approved: 'success',
    available: 'success',
    completed: 'success',
    confirmed: 'success',
    delivered: 'success',
    fulfilled: 'success',
    in_stock: 'success',
    paid: 'success',
    posted: 'success',
    present: 'success',
    published: 'success',
    received: 'success',
    reconciled: 'success',
    resolved: 'success',
    settled: 'success',
    succeeded: 'success',
    verified: 'success',
    won: 'success',

    // Needs somebody
    awaiting_approval: 'warning',
    backordered: 'warning',
    draft: 'warning',
    expiring: 'warning',
    half_day: 'warning',
    high: 'warning',
    hold: 'warning',
    in_progress: 'warning',
    low_stock: 'warning',
    maintenance: 'warning',
    on_hold: 'warning',
    on_leave: 'warning',
    open: 'warning',
    partial: 'warning',
    pending: 'warning',
    processing: 'warning',
    requested: 'warning',
    review: 'warning',
    unpaid: 'warning',
    waiting: 'warning',

    // Failed, lapsed, called off
    absent: 'danger',
    blocked: 'danger',
    cancelled: 'danger',
    canceled: 'danger',
    declined: 'danger',
    error: 'danger',
    expired: 'danger',
    failed: 'danger',
    lost: 'danger',
    no_show: 'danger',
    out_of_stock: 'danger',
    overdue: 'danger',
    refunded: 'danger',
    rejected: 'danger',
    suspended: 'danger',
    urgent: 'danger',
    void: 'danger',

    // In flight
    booked: 'info',
    dispatched: 'info',
    in_transit: 'info',
    normal: 'info',
    ordered: 'info',
    packed: 'info',
    quoted: 'info',
    reserved: 'info',
    scheduled: 'info',
    sent: 'info',
    shipped: 'info',
    submitted: 'info',
    upcoming: 'info',

    // Over, or never applied
    archived: 'neutral',
    closed: 'neutral',
    holiday: 'neutral',
    inactive: 'neutral',
    left: 'neutral',
    low: 'neutral',
    na: 'neutral',
    none: 'neutral',
    other: 'neutral',
    retired: 'neutral',
    unknown: 'neutral',
};

function normalise(status: string): string {
    return status.trim().toLowerCase().replace(/[\s-]+/g, '_');
}

/** The tone a status resolves to. Exported so a chart or a row border can take
 *  the same colour decision without rendering a badge. */
export function statusTone(status: string): StatusTone {
    return TONES[normalise(status)] ?? 'neutral';
}

/** 'on_leave' → 'On leave'. Only used when no explicit label is given. */
function humanise(status: string): string {
    const words = normalise(status).replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

type StatusBadgeProps = {
    /** The raw status from the API — 'paid', 'on_leave', 'In Transit'. */
    status: string;
    /** Overrides the tone this status would resolve to. For the rare state whose
     *  meaning is domain-specific: an 'open' ticket is warning, an 'open'
     *  fiscal year is success. */
    tone?: StatusTone;
    /** Overrides the displayed text. Use for a translated or domain-specific
     *  wording — the tone still comes from `status`. */
    label?: string;
    /** Hide the leading dot. Worth doing where badges are stacked densely and
     *  the dots start to read as a column of their own. */
    hideDot?: boolean;
    className?: string;
};

/**
 * The status of one record, as a small coloured pill.
 *
 * @example
 * <StatusBadge status={order.status} />
 * <StatusBadge status={year.status} tone="success" />
 * <StatusBadge status="on_leave" label="Away until 4 Sep" />
 */
export function StatusBadge({ status, tone, label, hideDot = false, className }: StatusBadgeProps) {
    const resolved = tone ?? statusTone(status);

    return (
        <span className={cn('status', `status-${resolved}`, className)}>
            {!hideDot && <span className="status-dot" aria-hidden />}
            {label ?? humanise(status)}
        </span>
    );
}
