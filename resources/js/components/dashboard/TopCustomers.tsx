import { useQuery } from '@tanstack/react-query';

import { Panel, PanelRow, PanelState } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMoneyWith } from '@/lib/money';

type TopCustomer = {
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
        customers: TopCustomer[];
    };
};

type TopCustomersProps = {
    limit?: number;
    className?: string;
};

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length >= 2) {
        return `${parts[0]?.[0] ?? ''}${parts[parts.length - 1]?.[0] ?? ''}`.toUpperCase();
    }

    return name.slice(0, 2).toUpperCase();
}

/** Who is worth the most, by what they have spent over the life of the account. */
export function TopCustomers({ limit = 5, className }: TopCustomersProps) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'top-customers', business, limit, money],
        queryFn: ({ signal }) =>
            api.get<TopCustomersData>('/dashboard/top-customers', {
                params: { limit },
                signal,
            }),
    });

    const customers = data?.data.customers ?? [];

    return (
        <Panel
            title="Top customers"
            unit={symbol}
            note="all time"
            href="/customers"
            flush
            className={className}
        >
            <PanelState
                isPending={isPending}
                isError={isError}
                isEmpty={customers.length === 0}
                emptyIcon="users"
                emptyMessage="No customers yet"
                errorMessage="Top customers could not be loaded."
                onRetry={() => void refetch()}
                rows={limit}
            >
                {customers.map((customer) => (
                    <PanelRow
                        key={customer.id}
                        initials={initials(customer.name)}
                        title={customer.name}
                        subtitle={customer.email || undefined}
                        href={`/customers/${customer.id}`}
                        stats={[
                            { label: 'Orders', value: customer.total_orders },
                            {
                                label: 'Spent',
                                value: (
                                    <span
                                        title={formatMoneyWith(symbol, customer.lifetime_value_formatted)}
                                    >
                                        {formatCompactNumber(customer.lifetime_value_formatted)}
                                    </span>
                                ),
                            },
                        ]}
                    />
                ))}
            </PanelState>
        </Panel>
    );
}
