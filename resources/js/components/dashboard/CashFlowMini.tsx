import { useQuery } from '@tanstack/react-query';

import { BarChart } from '@/components/ui/Charts/BarChart';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type CashFlowData = {
    data: {
        period: string;
        currency: string;
        current_balance: number;
        current_balance_formatted: string;
        cash_in_total: number;
        cash_out_total: number;
        net_formatted: string;
        series: Array<{ label: string; value: number }>;
    };
};

type CashFlowMiniProps = {
    /** Additional class */
    className?: string;
};

/**
 * Mini cash flow chart for dashboard.
 *
 * Features:
 * - Bar chart showing last 7 days cash in/out
 * - Current balance display
 * - Net cash flow indicator
 * - Loading and error states
 */
export function CashFlowMini({ className }: CashFlowMiniProps) {
    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'cash-flow'],
        queryFn: ({ signal }) =>
            api.get<CashFlowData>('/dashboard/cash-flow', { signal }),
    });

    const cashFlow = data?.data;

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-semibold text-[var(--color-text-main)]">
                        Cash Flow
                    </h3>
                    {cashFlow && (
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                            Last 7 days
                        </p>
                    )}
                </div>
                {cashFlow && (
                    <div className="text-right">
                        <p className="text-xs text-[var(--color-text-muted)]">Balance</p>
                        <p className="mt-0.5 text-lg font-bold text-[var(--color-text-main)]">
                            {cashFlow.current_balance_formatted}
                        </p>
                    </div>
                )}
            </div>

            {/* Content */}
            <div className="mt-6">
                {isError ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Icon
                            name="warning-circle"
                            size={24}
                            className="text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            Could not load cash flow data
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
                    <div className="flex h-[200px] items-center justify-center">
                        <div className="flex items-center gap-2 text-sm text-[var(--color-text-muted)]">
                            <Icon name="circle-notch" size={16} className="animate-spin" />
                            Loading chart...
                        </div>
                    </div>
                ) : cashFlow ? (
                    <>
                        <BarChart
                            data={cashFlow.series}
                            height={200}
                            showYAxis
                            showGrid={false}
                            barColor="var(--color-brand)"
                            valueFormatter={(v) => {
                                if (v >= 1000000) {
                                    return `${cashFlow.currency}${(v / 1000000).toFixed(1)}M`;
                                }
                                if (v >= 1000) {
                                    return `${cashFlow.currency}${(v / 1000).toFixed(1)}k`;
                                }
                                return `${cashFlow.currency}${v.toFixed(0)}`;
                            }}
                        />

                        {/* Summary */}
                        <div className="mt-4 flex items-center justify-between rounded-lg bg-[var(--color-surface)] p-3">
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">Net</p>
                                <p className="mt-0.5 text-sm font-semibold text-[var(--color-text-main)]">
                                    {cashFlow.net_formatted}
                                </p>
                            </div>
                            <div className="h-8 w-px bg-[var(--color-border-light)]" />
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">In</p>
                                <p className="mt-0.5 text-sm font-semibold text-green-600">
                                    {cashFlow.currency}{cashFlow.cash_in_total >= 1000
                                        ? `${(cashFlow.cash_in_total / 1000).toFixed(1)}k`
                                        : cashFlow.cash_in_total.toFixed(0)}
                                </p>
                            </div>
                            <div className="h-8 w-px bg-[var(--color-border-light)]" />
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">Out</p>
                                <p className="mt-0.5 text-sm font-semibold text-red-600">
                                    {cashFlow.currency}{cashFlow.cash_out_total >= 1000
                                        ? `${(cashFlow.cash_out_total / 1000).toFixed(1)}k`
                                        : cashFlow.cash_out_total.toFixed(0)}
                                </p>
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </div>
    );
}
