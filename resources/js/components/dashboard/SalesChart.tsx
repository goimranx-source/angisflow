import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { LineChart } from '@/components/ui/Charts/LineChart';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type SalesData = {
    data: {
        period: '7d' | '30d' | '12m';
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

const PERIODS = [
    { key: '7d', label: '7D' },
    { key: '30d', label: '1M' },
    { key: '12m', label: '1Y' },
] as const;

type SalesChartProps = {
    /** Additional class */
    className?: string;
};

/**
 * Sales overview chart showing revenue and order trends.
 *
 * Features:
 * - Toggle between 7 days, 30 days, 12 months
 * - Dual series (Revenue & Orders count)
 * - Total summary
 * - Loading and error states
 * - Uses internal API endpoint
 */
export function SalesChart({ className }: SalesChartProps) {
    const [period, setPeriod] = useState<'7d' | '30d' | '12m'>('30d');

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'sales-chart', period],
        queryFn: ({ signal }) =>
            api.get<SalesData>('/dashboard/sales-chart', {
                params: { period },
                signal,
            }),
        // Keep previous data while loading next period
        placeholderData: (previous) => previous,
    });

    const chartData = data?.data;

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                        Sales Overview
                    </h3>
                    {chartData && (
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                            {chartData.totals.revenue_formatted} revenue ·{' '}
                            {chartData.totals.orders} orders
                        </p>
                    )}
                </div>

                {/* Period selector */}
                <div className="flex items-center gap-1 border border-[var(--color-border-light)] bg-white p-1" style={{ borderRadius: 'var(--shell-radius)' }}>
                    {PERIODS.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setPeriod(option.key)}
                            className={cn(
                                'px-3 py-1 text-sm font-medium transition-colors',
                                option.key === period
                                    ? 'bg-[var(--color-brand)] text-white'
                                    : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                            )}
                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Chart */}
            <div className="mt-6">
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
                    <LineChart
                        series={[
                            {
                                name: 'Revenue',
                                data: chartData.series.revenue,
                                color: '#10b981',
                                showPoints: true,
                            },
                            {
                                name: 'Orders',
                                data: chartData.series.orders.map((point) => ({
                                    ...point,
                                    // Scale orders to be visible alongside revenue
                                    value: point.value,
                                })),
                                color: '#f59e0b',
                                showPoints: true,
                            },
                        ]}
                        height={350}
                        showYAxis
                        showLegend
                        curved
                        filled
                        valueFormatter={(v) => {
                            // Format revenue values
                            if (v >= 1000000) {
                                return `${chartData.currency}${(v / 1000000).toFixed(1)}M`;
                            }
                            if (v >= 1000) {
                                return `${chartData.currency}${(v / 1000).toFixed(1)}k`;
                            }
                            return `${chartData.currency}${v.toFixed(0)}`;
                        }}
                    />
                ) : null}
            </div>
        </div>
    );
}
