import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Customer = {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    total_orders: number;
    lifetime_value: number;
    lifetime_value_formatted: string;
};

type TopCustomersData = {
    data: {
        period: string;
        currency: string;
        customers: Customer[];
    };
};

type TopCustomersProps = {
    /** Number of customers to show */
    limit?: number;
    /** Additional class */
    className?: string;
};

/**
 * Top customers widget for dashboard.
 *
 * Features:
 * - Shows top N customers by lifetime value
 * - Customer avatar with fallback initials
 * - Total orders and revenue
 * - Loading and empty states
 * - Click to view customer details
 */
export function TopCustomers({ limit = 5, className }: TopCustomersProps) {
    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'top-customers', limit],
        queryFn: ({ signal }) =>
            api.get<TopCustomersData>('/dashboard/top-customers', {
                params: { limit },
                signal,
            }),
    });

    const customers = data?.data.customers ?? [];

    const getInitials = (name: string) => {
        const parts = name.split(' ').filter(Boolean);
        if (parts.length >= 2) {
            const first = parts[0]?.[0] || '';
            const last = parts[parts.length - 1]?.[0] || '';
            return `${first}${last}`.toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    };

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                    Top Customers
                </h3>
                <a
                    href="/customers"
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
                            Could not load top customers
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
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex items-center gap-3 animate-pulse"
                            >
                                <div className="h-10 w-10 rounded-full bg-[var(--color-surface)]" />
                                <div className="flex-1">
                                    <div className="h-4 w-32 rounded bg-[var(--color-surface)]" />
                                    <div className="mt-1 h-3 w-20 rounded bg-[var(--color-surface)]" />
                                </div>
                                <div className="h-4 w-16 rounded bg-[var(--color-surface)]" />
                            </div>
                        ))}
                    </div>
                ) : customers.length === 0 ? (
                    <div className="py-8 text-center">
                        <Icon
                            name="users"
                            size={32}
                            className="mx-auto text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            No customers yet
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {customers.map((customer) => (
                            <div
                                key={customer.id}
                                className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-[var(--color-surface)]"
                            >
                                {/* Avatar */}
                                {customer.avatar_url ? (
                                    <img
                                        src={customer.avatar_url}
                                        alt={customer.name}
                                        className="h-10 w-10 rounded-full object-cover"
                                    />
                                ) : (
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--color-brand-subtle)] text-xs font-medium text-[var(--color-ink-soft)]">
                                        {getInitials(customer.name)}
                                    </div>
                                )}

                                {/* Customer Info */}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-[var(--color-text-main)]">
                                        {customer.name}
                                    </p>
                                    <p className="text-xs text-[var(--color-text-muted)]">
                                        {customer.total_orders} order{customer.total_orders !== 1 ? 's' : ''}
                                    </p>
                                </div>

                                {/* Lifetime Value */}
                                <div className="flex-shrink-0 text-right">
                                    <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                        {customer.lifetime_value_formatted}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* No "view all" link at bottom - already in header */}
        </div>
    );
}
