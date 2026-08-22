import { useQuery } from '@tanstack/react-query';

import { DonutChart } from '@/components/ui/Charts/DonutChart';
import { Panel, PanelState } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useMoney } from '@/hooks/useMoney';
import { api } from '@/lib/api';
import { formatCompactNumber, formatMoneyWith } from '@/lib/money';

type BreakdownData = {
    data: {
        from: string;
        to: string;
        currency: string;
        total: number;
        total_formatted: string;
        slices: Array<{ label: string; value: number; formatted: string }>;
    };
};

/**
 * The palette a breakdown ring is drawn from.
 *
 * Ordered so the largest slice takes the brand colour and the rest step away
 * from it. Deliberately not the semantic five: nothing here is good or bad news,
 * it is a composition — colouring the biggest expense "danger" would tell the
 * reader something the data does not say.
 */
const SLICE_COLORS = [
    'var(--color-brand)',
    'var(--color-info)',
    'var(--color-warning)',
    'var(--color-success)',
    'var(--color-danger)',
    'var(--color-text-subtle)',
];

export function ExpenseBreakdown({
    from,
    to,
    className,
}: {
    /** The same range every other panel on the dashboard reads. */
    from: string;
    to: string;
    className?: string;
}) {
    const business = useBusinessScope();
    const { symbol, scope: money } = useMoney();

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'expense-breakdown', business, from, to, money],
        queryFn: ({ signal }) =>
            api.get<BreakdownData>('/dashboard/expense-breakdown', { params: { from, to }, signal }),
    });

    const breakdown = data?.data;
    const slices = (breakdown?.slices ?? []).map((slice, i) => ({
        label: slice.label,
        value: slice.value,
        color: SLICE_COLORS[i % SLICE_COLORS.length] as string,
    }));

    return (
        <Panel title="Where money went" unit={symbol} className={className}>
            <PanelState
                isPending={isPending}
                isError={isError}
                isEmpty={slices.length === 0}
                emptyIcon="chart-bar"
                emptyMessage="Nothing spent yet"
                errorMessage="The expense breakdown could not be loaded."
                onRetry={() => void refetch()}
                rows={3}
            >
                <DonutChart
                    data={slices}
                    size={150}
                    legend="side"
                    // Compact in the hole, exact on hover. The full figure
                    // does not fit inside a 150px ring and ran out over the
                    // segments; the currency is on the panel's title now, so
                    // the centre only has to carry the number.
                    centerValue={
                        breakdown ? formatCompactNumber(breakdown.total_formatted) : undefined
                    }
                    centerTitle={
                        breakdown ? formatMoneyWith(symbol, breakdown.total_formatted) : undefined
                    }
                    centerLabel="total"
                    valueFormatter={(v) => {
                        const slice = breakdown?.slices.find((s) => s.value === v);

                        return slice ? formatMoneyWith(symbol, slice.formatted) : String(v);
                    }}
                />
            </PanelState>
        </Panel>
    );
}
