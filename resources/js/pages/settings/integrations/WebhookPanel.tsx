import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

/**
 * Whether the shop is actually set up to call us — and a button that fixes it.
 *
 * ── Why this replaced an address and an instruction ──────────────────────────
 *
 * What was here before was the delivery URL, a Copy button, and a sentence
 * asking somebody to go and create a webhook in their shop's admin with the
 * same secret as the field above. That is four manual steps across two
 * applications, and every one of them fails silently: a mistyped secret, a
 * missed event, a webhook created against the wrong topic all produce a
 * connection that tests green and never receives anything.
 *
 * It also could not stay correct. The delivery address changes when this
 * application moves, and a shop disables a webhook of its own accord after a
 * run of failed deliveries — neither of which anybody finds out about until
 * they notice the orders stopped.
 *
 * Since the shop's own API can create and repair webhooks, none of that needs
 * to be a person's problem. This shows the truth and offers one button.
 *
 * ── Why the address is still shown ───────────────────────────────────────────
 *
 * For the platforms that cannot do this — a bespoke site behind the generic
 * driver has no webhook API to call — and because seeing where a shop is
 * pointed is what makes "it stopped working after we moved the server"
 * diagnosable in one glance rather than one afternoon.
 */

type TopicState = 'ok' | 'missing' | 'disabled' | 'wrong-address';

type WebhookStatus = {
    supported: boolean;
    url: string | null;
    has_secret: boolean;
    healthy: boolean;
    topics: Array<{ topic: string; state: TopicState; url: string | null }>;
    /** What the shop's last real call did — the only proof the secret matches. */
    last_delivery: { at: string; accepted: boolean; reason: string | null } | null;
};

/** What each state means to somebody who did not write this. */
const explain: Record<TopicState, { label: string; tone: string; icon: string }> = {
    ok: { label: 'Receiving', tone: 'var(--color-success)', icon: 'check-circle' },
    missing: { label: 'Not set up', tone: 'var(--color-text-subtle)', icon: 'circle' },
    disabled: { label: 'Turned off by the shop', tone: 'var(--color-danger)', icon: 'warning' },
    'wrong-address': { label: 'Pointing somewhere else', tone: 'var(--color-warning)', icon: 'warning' },
};

/** 'order.created' reads as machinery. 'New orders' reads as a promise. */
const topicLabels: Record<string, string> = {
    'order.created': 'New orders',
    'order.updated': 'Order changes',
    'product.created': 'New products',
    'product.updated': 'Product changes',
};

export function WebhookPanel({ connectionId, fallbackUrl }: { connectionId: string; fallbackUrl: string }) {
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['integration-webhooks', connectionId],
        queryFn: () =>
            api
                .get<{ data: WebhookStatus }>(`/settings/integrations/${connectionId}/webhooks`)
                .then((response) => response.data),
        // Reading this costs a round trip to the shop, so it is not refetched
        // every time the modal regains focus.
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    const repair = useMutation({
        mutationFn: (rotate: boolean) =>
            api.post<{ message: string }>(`/settings/integrations/${connectionId}/webhooks/repair`, {
                rotate,
            }),
        onSuccess: (result) => {
            toast.success(result.message);
            void queryClient.invalidateQueries({ queryKey: ['integration-webhooks', connectionId] });
        },
        onError: (error: Error) => toast.error(error.message || 'The shop would not accept that.'),
    });

    const url = data?.url ?? fallbackUrl;

    return (
        <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-medium">Live updates</p>
                    <p className="text-xs text-[var(--color-text-subtle)]">
                        So orders arrive as they are placed, rather than at the next sync.
                    </p>
                </div>

                {data?.supported && (
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => repair.mutate(false)}
                        disabled={repair.isPending}
                    >
                        <Icon
                            name={repair.isPending ? 'spinner' : 'arrows-clockwise'}
                            size={14}
                            className={repair.isPending ? 'animate-spin' : undefined}
                        />
                        <span>
                            {repair.isPending
                                ? 'Setting up…'
                                : data.healthy
                                  ? 'Check again'
                                  : 'Set up automatically'}
                        </span>
                    </button>
                )}
            </div>

            {isLoading && (
                <p className="mt-3 text-xs text-[var(--color-text-subtle)]">Asking the shop…</p>
            )}

            {data?.supported && (
                <ul className="mt-3 space-y-1.5">
                    {data.topics.map((topic) => {
                        const state = explain[topic.state];

                        return (
                            <li key={topic.topic} className="flex items-center gap-2 text-sm">
                                <Icon name={state.icon} size={14} style={{ color: state.tone }} />
                                <span className="flex-1">{topicLabels[topic.topic] ?? topic.topic}</span>
                                <span className="text-xs" style={{ color: state.tone }}>
                                    {state.label}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}

            {/*
              What the shop's last real call did.

              Shown because it is the only thing here that proves the secret
              matches — every row above can read "Receiving" while every
              delivery is turned away at the door.
            */}
            {data?.last_delivery && (
                <p
                    className="mt-3 border-t border-[var(--shell-border)] pt-2 text-xs"
                    style={{
                        color: data.last_delivery.accepted
                            ? 'var(--color-text-subtle)'
                            : 'var(--color-danger)',
                    }}
                >
                    {data.last_delivery.accepted ? 'Last call from the shop' : 'Last call was refused'}
                    {' — '}
                    {new Date(data.last_delivery.at).toLocaleString()}
                    {data.last_delivery.reason ? `. ${data.last_delivery.reason}` : '.'}
                    {!data.last_delivery.accepted && ' Setting it up again fixes this.'}
                </p>
            )}

            {/*
              Replacing the secret, for when one has been seen by somebody it
              should not have been.

              Deliberately understated: it is the right thing to do after a leak
              and the wrong thing to do idly, and a button of equal weight beside
              "Set up automatically" would get pressed by people looking for the
              other one. Safe either way — the previous secret keeps verifying
              until the next rotation, so nothing in flight is lost.
            */}
            {data?.supported && data.has_secret && (
                <button
                    type="button"
                    className="mt-2 text-xs underline underline-offset-2"
                    style={{ color: 'var(--color-text-subtle)' }}
                    onClick={() => repair.mutate(true)}
                    disabled={repair.isPending}
                >
                    Replace the signing secret
                </button>
            )}

            {/*
              Only where the platform cannot manage its own — otherwise this is
              an instruction for work the button already did.
            */}
            {data && !data.supported && (
                <div className="mt-3">
                    <div className="flex items-center gap-2">
                        <code className="min-w-0 flex-1 truncate rounded-[var(--shell-radius-sm)] border border-[var(--shell-border)] bg-[var(--shell-muted)] px-3 py-2 text-xs">
                            {url}
                        </code>
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => {
                                void navigator.clipboard?.writeText(url);
                                toast.success('Address copied.');
                            }}
                        >
                            Copy
                        </button>
                    </div>

                    <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                        This platform cannot be set up from here. Add a webhook at this address in the
                        shop, using the same secret as above — unsigned calls are refused.
                    </p>
                </div>
            )}
        </div>
    );
}
