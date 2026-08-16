import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { SkeletonCard } from '@/components/ui/Skeleton';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type BillingPayload = {
    data: {
        account: {
            status: string;
            trial_ends_at: string | null;
            suspended_reason: string | null;
        };
        subscription: {
            status: string;
            plan: string | null;
            price: { minor: number; currency: string; formatted: string } | null;
            interval: string | null;
            renews_at: string | null;
            trial_ends_at: string | null;
            cancel_at_period_end: boolean;
        } | null;
    };
};

const STATUS_LABELS: Record<string, string> = {
    trialing: 'On trial',
    active: 'Active',
    past_due: 'Payment failed',
    suspended: 'On hold',
    cancelled: 'Cancelled',
};

/**
 * The subscription, from the subscriber's side.
 *
 * Reachable even when the account has been stopped — note the absent
 * account.usable check on the route. A lock-out screen behind the lock-out
 * check is a door with the key on the inside.
 */
export default function Billing() {
    const { data, isPending } = useQuery({
        queryKey: ['billing'],
        queryFn: ({ signal }) => api.get<BillingPayload>('/billing', { signal }),
    });

    useDocumentTitle('Subscription');

    const account = data?.data.account;
    const subscription = data?.data.subscription;

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader title="Subscription" description="What you are on, and when it renews." />

            {isPending ? (
                <SkeletonCard className="mt-6" />
            ) : account ? (
                <div className="card mt-6 divide-y divide-[var(--color-border-light)]">
                    <Row label="Status" value={STATUS_LABELS[account.status] ?? account.status} />

                    {subscription?.plan && <Row label="Plan" value={subscription.plan} />}

                    {subscription?.price && (
                        <Row
                            label="Price"
                            value={`${subscription.price.currency} ${subscription.price.formatted}${
                                subscription.interval === 'yearly' ? ' a year' : ' a month'
                            }`}
                        />
                    )}

                    {account.trial_ends_at && (
                        <Row label="Trial ends" value={formatDate(account.trial_ends_at)} />
                    )}

                    {subscription?.renews_at && (
                        <Row
                            label={subscription.cancel_at_period_end ? 'Access until' : 'Renews'}
                            value={formatDate(subscription.renews_at)}
                        />
                    )}

                    {account.suspended_reason && (
                        <Row label="Reason" value={account.suspended_reason} />
                    )}
                </div>
            ) : null}

            <div className="card mt-5 flex gap-3 p-5">
                <Icon
                    name="info"
                    size={18}
                    className="mt-0.5 flex-none text-[var(--color-text-muted)]"
                />
                <p className="text-sm text-[var(--color-text-body)]">
                    Changing plan and payment details arrive with the admin panel, which is what
                    manages all of this from the other side. Both read the same two tables that back
                    this screen.
                </p>
            </div>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-4 px-5 py-3.5">
            <span className="text-sm text-[var(--color-text-muted)]">{label}</span>
            <span className="text-sm font-semibold text-[var(--color-text-main)]">{value}</span>
        </div>
    );
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
