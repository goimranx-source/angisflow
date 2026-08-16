import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Order = {
    id: string;
    order_number: string;
    customer_name: string;
    total_formatted: string;
    status: string;
    status_label: string;
    status_variant: 'default' | 'success' | 'warning' | 'error' | 'info';
    created_at: string;
    created_at_human: string;
};

type RecentOrdersData = {
    data: {
        orders: Order[];
    };
};

type RecentOrdersProps = {
    /** Number of orders to show */
    limit?: number;
    /** Additional class */
    className?: string;
};

/**
 * Recent orders widget for dashboard.
 *
 * Features:
 * - Shows last N orders
 * - Order number, customer, total, status
 * - Status badge with color variants
 * - Click to view order details
 * - Loading and empty states
 */
export function RecentOrders({ limit = 10, className }: RecentOrdersProps) {
    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'recent-orders', limit],
        queryFn: ({ signal }) =>
            api.get<RecentOrdersData>('/dashboard/recent-orders', {
                params: { limit },
                signal,
            }),
    });

    const orders = data?.data.orders ?? [];

    const statusColors = {
        default: 'bg-gray-100 text-gray-700',
        success: 'bg-green-100 text-green-700',
        warning: 'bg-amber-100 text-amber-700',
        error: 'bg-red-100 text-red-700',
        info: 'bg-blue-100 text-blue-700',
    };

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                    Recent Orders
                </h3>
                <a
                    href="/orders"
                    className="btn btn-secondary flex items-center gap-1 py-1.5 text-sm"
                >
                    View All
                    <Icon name="caret-right" size={14} weight="bold" />
                </a>
            </div>

            {/* Content */}
            <div className="mt-4">
                {isError ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Icon
                            name="warning-circle"
                            size={24}
                            className="text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            Could not load recent orders
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="mt-2 text-sm font-medium text-[var(--color-brand)] hover:underline"
                        >
                            Try again
                        </button>
                    </div>
                ) : isPending ? (
                    <div className="space-y-3">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-between gap-4 animate-pulse"
                            >
                                <div className="flex-1">
                                    <div className="h-4 w-24 rounded bg-[var(--color-surface)]" />
                                    <div className="mt-1 h-3 w-32 rounded bg-[var(--color-surface)]" />
                                </div>
                                <div className="h-4 w-16 rounded bg-[var(--color-surface)]" />
                                <div className="h-6 w-20 rounded-full bg-[var(--color-surface)]" />
                            </div>
                        ))}
                    </div>
                ) : orders.length === 0 ? (
                    <div className="py-8 text-center">
                        <Icon
                            name="shopping-cart"
                            size={32}
                            className="mx-auto text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            No orders yet
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="border-b border-[var(--color-border-light)]">
                                <tr className="text-left text-xs font-medium text-[var(--color-text-muted)]">
                                    <th className="pb-3">Order #</th>
                                    <th className="pb-3">Customer</th>
                                    <th className="pb-3">Date</th>
                                    <th className="pb-3 text-right">Amount</th>
                                    <th className="pb-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--color-border-light)]">
                                {orders.map((order) => (
                                    <tr
                                        key={order.id}
                                        className="group transition-colors hover:bg-[var(--color-surface)]"
                                    >
                                        <td className="py-3">
                                            <a
                                                href={`/orders/${order.id}`}
                                                className="text-sm font-medium text-[var(--color-brand)] hover:underline"
                                            >
                                                {order.order_number}
                                            </a>
                                        </td>
                                        <td className="py-3">
                                            <p className="text-sm text-[var(--color-text-main)]">
                                                {order.customer_name}
                                            </p>
                                        </td>
                                        <td className="py-3">
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                {order.created_at_human}
                                            </p>
                                        </td>
                                        <td className="py-3 text-right">
                                            <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                                {order.total_formatted}
                                            </p>
                                        </td>
                                        <td className="py-3 text-center">
                                            <span
                                                className={cn(
                                                    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                                                    statusColors[order.status_variant],
                                                )}
                                            >
                                                {order.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* No "view all" link at bottom - already in header */}
        </div>
    );
}
