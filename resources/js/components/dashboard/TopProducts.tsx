import { useQuery } from '@tanstack/react-query';

import { Panel, PanelRow, PanelState } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMoneyWith } from '@/lib/money';

type TopProduct = {
    id: string;
    name: string;
    sku: string | null;
    image_url: string | null;
    quantity_sold: number;
    revenue: number;
    revenue_formatted: string;
};

type TopProductsData = {
    data: {
        period: string;
        currency: string;
        products: TopProduct[];
    };
};

type TopProductsProps = {
    limit?: number;
    className?: string;
};

/** What is selling, ranked by what it brought in rather than by units moved. */
export function TopProducts({ limit = 5, className }: TopProductsProps) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'top-products', business, limit, money],
        queryFn: ({ signal }) =>
            api.get<TopProductsData>('/dashboard/top-products', {
                params: { limit },
                signal,
            }),
    });

    const products = data?.data.products ?? [];

    return (
        <Panel
            title="Top products"
            unit={symbol}
            note="this month"
            href="/products"
            flush
            className={className}
        >
            <PanelState
                isPending={isPending}
                isError={isError}
                isEmpty={products.length === 0}
                emptyIcon="package"
                emptyMessage="Nothing sold yet"
                errorMessage="Top products could not be loaded."
                onRetry={() => void refetch()}
                rows={limit}
            >
                {products.map((product) => (
                    <PanelRow
                        key={product.id || product.name}
                        icon="package"
                        title={product.name}
                        subtitle={product.sku ?? undefined}
                        href={product.id ? `/products/${product.id}` : undefined}
                        stats={[
                            { label: 'Sold', value: product.quantity_sold },
                            {
                                label: 'Revenue',
                                value: (
                                    <span title={formatMoneyWith(symbol, product.revenue_formatted)}>
                                        {formatCompactNumber(product.revenue_formatted)}
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
