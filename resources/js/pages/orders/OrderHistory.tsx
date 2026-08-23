import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';

type Entry = {
    id: string;
    at: string | null;
    verb: string;
    by: string | null;
    /** The platform did this, not a person — a webhook, a sync, a queued push. */
    automated: boolean;
    title: string;
    detail: string | null;
    tone: 'good' | 'bad' | 'quiet' | 'plain';
};

type Milestone = { label: string; at: string };

type History = { entries: Entry[]; milestones: Milestone[]; shop: string | null };

/** Which mark sits on the line for a given kind of event. */
const MARKS: Record<string, string> = {
    created: 'plus',
    'status.changed': 'arrows-clockwise',
    'payment.changed': 'currency-dollar',
    'fulfilment.changed': 'package',
    'total.changed': 'currency-dollar',
    'paid.changed': 'currency-dollar',
    edited: 'pencil',
    archived: 'archive',
    unarchived: 'archive',
    trashed: 'trash',
    restored: 'arrow-clockwise',
    'push.sent': 'arrow-up-right',
    'push.failed': 'warning',
    pulled: 'cloud-arrow-down',
};

/*
 * Colour, used on two kinds of line out of eleven.
 *
 * A timeline where every row is coloured is a timeline where colour says
 * nothing. The point of it here is that the one thing which went wrong is
 * visible without reading, and that only works while nearly everything else is
 * quiet.
 */
const TONES: Record<Entry['tone'], { dot: string; text?: string }> = {
    good: { dot: 'var(--color-success)' },
    bad: { dot: 'var(--color-danger)', text: 'var(--color-danger-text)' },
    quiet: { dot: 'var(--color-text-subtle)' },
    plain: { dot: 'var(--color-border-strong)' },
};

function when(iso: string | null): { day: string; time: string } {
    if (!iso) return { day: '—', time: '' };

    const at = new Date(iso);

    return {
        day: at.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }),
        time: at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
    };
}

/**
 * What happened to one order, in the order it happened.
 *
 * ── Why a timeline and not a table ───────────────────────────────────────────
 *
 * Because the question is almost never "list every change". It is "when did this
 * become shipped", or "did the shop ever get my change", or "who cancelled it" —
 * questions about a sequence, asked by someone scanning down it. A table sorts
 * and filters things you already know how to name; a timeline is for the ones
 * you do not.
 *
 * ── The two kinds of line, kept apart ────────────────────────────────────────
 *
 * Events are things this application watched happen, with a moment and usually a
 * person attached. Milestones are the timestamps the order carries in its own
 * columns, which say only that something had happened by the time anybody
 * looked.
 *
 * Every order placed before the event log was switched on has milestones and no
 * events at all. Mixing the two would quietly present a reconstruction as a
 * record, so they are shown separately and the difference is stated.
 */
export function OrderHistory({ orderId }: { orderId: string }) {
    const { data, isLoading, error } = useQuery({
        queryKey: ['order', orderId, 'history'],
        queryFn: () => api.get<{ data: History }>(`/orders/${orderId}/history`),
        enabled: Boolean(orderId),
    });

    const history = data?.data;

    if (isLoading) {
        return (
            <div className="space-y-3">
                {[0, 1, 2].map((i) => (
                    <div
                        key={i}
                        className="h-12 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]"
                    />
                ))}
            </div>
        );
    }

    if (error) {
        return (
            <p className="text-sm text-[var(--color-text-muted)]">
                That order&rsquo;s history could not be read just now.
            </p>
        );
    }

    const entries = history?.entries ?? [];
    const milestones = history?.milestones ?? [];

    return (
        <div className="space-y-6">
            {entries.length > 0 && (
                <ol className="relative space-y-0">
                    {/*
                      One line down the whole column, drawn behind the marks
                      rather than as a border on each of them — a border per row
                      leaves a hairline gap at every join, and forty of those
                      read as a dotted line nobody asked for.
                    */}
                    <div
                        className="absolute bottom-4 left-[11px] top-4 w-px"
                        style={{ background: 'var(--shell-border)' }}
                        aria-hidden="true"
                    />

                    {entries.map((entry) => {
                        const tone = TONES[entry.tone] ?? TONES.plain;
                        const stamp = when(entry.at);

                        return (
                            <li key={entry.id} className="relative flex gap-3 py-2.5">
                                <span
                                    className="relative z-10 mt-0.5 flex size-[23px] shrink-0 items-center justify-center rounded-full border"
                                    style={{
                                        background: 'var(--color-card-bg)',
                                        borderColor: tone.dot,
                                        color: tone.dot,
                                    }}
                                >
                                    <Icon name={MARKS[entry.verb] ?? 'circle'} size={11} />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                        <span
                                            className="text-sm font-medium"
                                            style={{ color: tone.text ?? 'var(--color-text-main)' }}
                                        >
                                            {entry.title}
                                        </span>

                                        <span className="whitespace-nowrap text-xs tabular-nums text-[var(--color-text-muted)]">
                                            {stamp.day} · {stamp.time}
                                        </span>
                                    </div>

                                    {entry.detail && (
                                        <p className="mt-0.5 break-words text-xs text-[var(--color-text-muted)]">
                                            {entry.detail}
                                        </p>
                                    )}

                                    {/*
                                      Who, and never nobody.

                                      An unattributed line invites the reader to
                                      assume a colleague did something they did
                                      not. Saying the sync did it is both true
                                      and the more useful half — it is the
                                      difference between looking for a person and
                                      looking at a connection.
                                    */}
                                    <p className="mt-0.5 text-xs text-[var(--color-text-subtle)]">
                                        {entry.automated ? 'By the sync' : (entry.by ?? 'Unknown')}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}

            {entries.length === 0 && (
                <p className="text-sm text-[var(--color-text-muted)]">
                    Nothing has been recorded against this order yet. Changes made from here on
                    will appear as they happen.
                </p>
            )}

            {milestones.length > 0 && (
                <div className="border-t border-[var(--shell-border)] pt-4">
                    <p className="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-muted)]">
                        Dates on the order
                    </p>

                    <dl className="text-sm">
                        {milestones.map((milestone) => {
                            const stamp = when(milestone.at);

                            return (
                                <div
                                    key={milestone.label}
                                    className="flex items-baseline justify-between border-b border-[var(--shell-border)] py-2 last:border-0"
                                >
                                    <dt className="text-[var(--color-text-muted)]">{milestone.label}</dt>
                                    <dd className="text-right tabular-nums text-[var(--color-text-main)]">
                                        {stamp.day}
                                        <span className="ml-2 text-xs text-[var(--color-text-muted)]">
                                            {stamp.time}
                                        </span>
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            )}
        </div>
    );
}
