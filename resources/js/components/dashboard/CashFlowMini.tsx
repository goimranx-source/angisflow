import { useQuery } from '@tanstack/react-query';

import { LineChart } from '@/components/ui/Charts/LineChart';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMinorCompactNumber, formatMoneyWith } from '@/lib/money';
import { Panel } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';

type CashFlowData = {
    data: {
        from: string;
        to: string;
        currency: string;
        current_balance: number;
        current_balance_formatted: string;
        cash_in_total: number;
        cash_in_formatted: string;
        cash_out_total: number;
        cash_out_formatted: string;
        net_formatted: string;
        series: Array<{ label: string; value: number }>;
        inflow: Array<{ label: string; value: number }>;
        outflow: Array<{ label: string; value: number }>;
    };
};

type CashFlowMiniProps = {
    /** The same range every other panel on the dashboard reads. */
    from: string;
    to: string;
    /** Additional class */
    className?: string;
};

/**
 * Mini cash flow chart for dashboard.
 *
 * Features:
 * - Bar chart showing cash in/out across the selected range
 * - Current balance display
 * - Net cash flow indicator
 * - Loading and error states
 */
export function CashFlowMini({ from, to, className }: CashFlowMiniProps) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'cash-flow', business, from, to, money],
        queryFn: ({ signal }) =>
            api.get<CashFlowData>('/dashboard/cash-flow', { params: { from, to }, signal }),
    });

    const cashFlow = data?.data;

    return (
        <Panel
            title="Cash flow"
            // Said once, beside the title — see SalesChart for why.
            unit={symbol}
            action={
                cashFlow ? (
                    <div className="text-right">
                        <p className="panel-row-label">Balance</p>
                        <p
                            className="panel-row-value"
                            title={formatMoneyWith(symbol, cashFlow.current_balance_formatted)}
                        >
                            {formatCompactNumber(cashFlow.current_balance_formatted)}
                        </p>
                    </div>
                ) : undefined
            }
            className={className}
        >
            <div>
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
                        <LineChart
                            height={250}
                            series={[
                                {
                                    name: 'Money in',
                                    color: 'var(--color-success)',
                                    data: cashFlow.inflow,
                                },
                                {
                                    name: 'Money out',
                                    color: 'var(--color-danger)',
                                    data: cashFlow.outflow,
                                },
                            ]}
                            curved
                            valueFormatter={(v) => formatMinorCompactNumber(v, cashFlow.currency)}
                        />

                        {/* Summary */}
                        <div className="mt-4 flex items-center justify-between rounded-[var(--shell-radius)] bg-[var(--shell-tint)] p-3">
                            {/* Compact on screen, exact in the title — a
                                strip of three full figures is unreadable at
                                a glance and pushes the card wider than the
                                chart above it needs. */}
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">Net</p>
                                <p
                                    className="mt-0.5 text-sm font-semibold text-[var(--color-text-main)]"
                                    title={formatMoneyWith(symbol, cashFlow.net_formatted)}
                                >
                                    {formatCompactNumber(cashFlow.net_formatted)}
                                </p>
                            </div>
                            <div className="h-8 w-px bg-[var(--color-border-light)]" />
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">In</p>
                                <p
                                    className="mt-0.5 text-sm font-semibold text-[var(--color-success)]"
                                    title={formatMoneyWith(symbol, cashFlow.cash_in_formatted)}
                                >
                                    {formatCompactNumber(cashFlow.cash_in_formatted)}
                                </p>
                            </div>
                            <div className="h-8 w-px bg-[var(--color-border-light)]" />
                            <div className="text-center">
                                <p className="text-xs text-[var(--color-text-muted)]">Out</p>
                                <p
                                    className="mt-0.5 text-sm font-semibold text-[var(--color-danger-text)]"
                                    title={formatMoneyWith(symbol, cashFlow.cash_out_formatted)}
                                >
                                    {formatCompactNumber(cashFlow.cash_out_formatted)}
                                </p>
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </Panel>
    );
}
