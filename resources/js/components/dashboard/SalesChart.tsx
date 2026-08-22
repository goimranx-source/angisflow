import { useQuery } from '@tanstack/react-query';

import { ComparisonChart } from '@/components/ui/Charts/ComparisonChart';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMinorCompactNumber, formatMoneyWith } from '@/lib/money';
import { Panel } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';

type SalesData = {
    data: {
        from: string;
        to: string;
        currency: string;
        series: {
            revenue: Array<{ label: string; value: number }>;
            orders: Array<{ label: string; value: number }>;
        };
        totals: {
            revenue: number;
            revenue_formatted: string;
            orders: number;
        };
    };
};

type SalesChartProps = {
    /** The same range every other panel on the dashboard reads — see
     *  Dashboard.tsx's DateRangePicker. There is no chart-local period
     *  control here on purpose: two controls that can disagree about what
     *  "the period" means is worse than one. */
    from: string;
    to: string;
    /** Additional class */
    className?: string;
};

/**
 * Sales overview chart showing revenue and order trends.
 *
 * Features:
 * - Dual series (Revenue & Orders count)
 * - Total summary
 * - Loading and error states
 * - Uses internal API endpoint
 */
export function SalesChart({ from, to, className }: SalesChartProps) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'sales-chart', business, from, to, money],
        queryFn: ({ signal }) =>
            api.get<SalesData>('/dashboard/sales-chart', {
                params: { from, to },
                signal,
            }),
        // Keep previous data while loading the next range
        placeholderData: (previous) => previous,
    });

    const chartData = data?.data;

    return (
        <Panel
            title="Sales overview"
            // The currency, said once. Every figure below is in it, so the
            // axis is free to be a scale rather than five repetitions of the
            // same symbol.
            unit={symbol}
            // At the far end of the head rather than trailing the title. It
            // is the panel's headline figure, not a qualifier on its name,
            // and against the right edge it lines up with the totals every
            // other panel puts there.
            action={
                chartData ? (
                    <div className="text-right">
                        <p className="panel-row-label">Total</p>
                        <p
                            className="panel-row-value"
                            title={`${formatMoneyWith(symbol, chartData.totals.revenue_formatted)} · ${chartData.totals.orders} orders`}
                        >
                            {formatCompactNumber(chartData.totals.revenue_formatted)} ·{' '}
                            {chartData.totals.orders} orders
                        </p>
                    </div>
                ) : undefined
            }
            className={className}
        >
            <div>
                {isError ? (
                    <div className="flex flex-col items-center justify-center py-12 text-center">
                        <Icon
                            name="warning-circle"
                            size={32}
                            className="text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            Could not load sales data
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="btn btn-primary mt-3 text-sm"
                        >
                            Try again
                        </button>
                    </div>
                ) : isPending ? (
                    <div className="flex h-[300px] items-center justify-center">
                        <div className="flex items-center gap-2 text-sm text-[var(--color-text-muted)]">
                            <Icon name="circle-notch" size={16} className="animate-spin" />
                            Loading chart...
                        </div>
                    </div>
                ) : chartData ? (
                    // Revenue and orders, each scaled to its own peak —
                    // sharing one axis would flatten the order bars to a
                    // sliver beside revenue's, present but unreadable. Scaled
                    // independently the question changes from "which is
                    // bigger" (obviously revenue) to "did they move
                    // together", which is the one worth asking of this pair.
                    <ComparisonChart
                        series={[
                            {
                                name: 'Revenue',
                                data: chartData.series.revenue,
                                // Tokens, not literals — this was an emerald
                                // picked to look like some other product's
                                // chart, and it stayed the same brightness on
                                // both themes.
                                color: 'var(--color-brand)',
                                formatter: (v) => formatMinorCompactNumber(v, chartData.currency),
                            },
                            {
                                name: 'Orders',
                                data: chartData.series.orders,
                                color: 'var(--color-warning)',
                                formatter: (v) => `${v} order${v === 1 ? '' : 's'}`,
                            },
                        ]}
                        height={260}
                        independentScale
                        valueFormatter={(v) => formatMinorCompactNumber(v, chartData.currency)}
                    />
                ) : null}
            </div>
        </Panel>
    );
}
