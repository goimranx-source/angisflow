import { useQuery } from '@tanstack/react-query';

import { Panel, PanelRow, PanelState } from '@/components/ui/Panel';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMoneyWith } from '@/lib/money';

type RecentOrder = {
    id: string;
    order_number: string;
    customer_name: string;
    total_formatted: string;
    status: string;
    status_label: string;
    status_variant: string;
    created_at: string;
    created_at_human: string;
};

type RecentOrdersData = {
    data: {
        orders: RecentOrder[];
        currency: string;
    };
};

type RecentOrdersProps = {
    limit?: number;
    className?: string;
};

/** What has come in lately, newest first — including orders not yet paid for. */
export function RecentOrders({ limit = 10, className }: RecentOrdersProps) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'recent-orders', business, limit, money],
        queryFn: ({ signal }) =>
            api.get<RecentOrdersData>('/dashboard/recent-orders', {
                params: { limit },
                signal,
            }),
    });

    const orders = data?.data.orders ?? [];

    return (
        <Panel title="Recent orders" unit={symbol} href="/orders" flush className={className}>
            <PanelState
                isPending={isPending}
                isError={isError}
                isEmpty={orders.length === 0}
                emptyIcon="shopping-cart"
                emptyMessage="No orders yet"
                errorMessage="Recent orders could not be loaded."
                onRetry={() => void refetch()}
                rows={5}
            >
                {orders.map((order) => (
                    <PanelRow
                        key={order.id}
                        icon="receipt"
                        title={order.customer_name}
                        subtitle={`#${order.order_number} · ${order.created_at_human}`}
                        href={`/orders/${order.id}`}
                        stats={[
                            {
                                label: 'Total',
                                value: (
                                    <span title={formatMoneyWith(symbol, order.total_formatted)}>
                                        {formatCompactNumber(order.total_formatted)}
                                    </span>
                                ),
                            },
                        ]}
                        // The server sends its own label, which carries wording
                        // the badge's own humanising would lose — but the tone
                        // is decided from the raw status, in one place, so this
                        // pill matches every other pill in the product.
                        trailing={
                            <StatusBadge status={order.status} label={order.status_label} />
                        }
                    />
                ))}
            </PanelState>
        </Panel>
    );
}
