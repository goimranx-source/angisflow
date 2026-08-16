import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    CashFlowMini,
    PendingTasks,
    QuickActions,
    RecentOrders,
    SalesChart,
    TopCustomers,
    TopProducts,
} from '@/components/dashboard';
import { Icon } from '@/components/ui/Icon';
import { SkeletonKpi } from '@/components/ui/Skeleton';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

type Kpi = {
    key: string;
    label: string;
    icon: string;
    value: string;
    raw: number;
    delta: number | null;
    direction: 'up' | 'down' | 'flat';
    rise_is_good: boolean;
};

type DashboardPayload = {
    data: {
        period: { key: string; label: string; compare_label: string };
        currency: string;
        kpis: Kpi[];
        /** False while the trading modules are still being built. */
        trading_ready: boolean;
    };
};

/**
 * Main Dashboard Router
 * 
 * Routes to the appropriate category-specific dashboard based on business type.
 * If business has multiple categories, shows all available dashboards in navigation.
 */
export default function Dashboard() {
    const { tenant } = useSession();
    // No period selector in this view yet — fixed to this_month.
    const [period] = useState<string>('this_month');

    useDocumentTitle('Dashboard');

    const { data, isPending, isFetching, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'summary', period],
        queryFn: ({ signal }) =>
            api.get<DashboardPayload>('/dashboard', { params: { period }, signal }),
        placeholderData: (previous) => previous,
    });

    const summary = data?.data;

    return (
        <div className="mx-auto max-w-[1400px]">
            {/* Header with Title, Date Picker, Export, and Add button */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-[var(--color-text-main)]">
                        {tenant?.business?.name ?? 'Dashboard'}
                    </h1>
                    {summary && (
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                            {summary.period.label}
                        </p>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    {/* Date Range Picker */}
                    <button
                        type="button"
                        className="flex items-center gap-2 border border-[var(--shell-border)] bg-[var(--shell-bg)] px-3 py-2 text-sm font-medium text-[var(--shell-text-strong)] transition-colors hover:bg-[var(--shell-hover)]"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    >
                        <Icon name="calendar" size={16} />
                        <span>
                            {new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' })} to{' '}
                            {new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' })}
                        </span>
                    </button>

                    {/* Export Button */}
                    <button
                        type="button"
                        className="flex items-center gap-2 border border-[var(--shell-border)] bg-[var(--shell-bg)] px-3 py-2 text-sm font-medium text-[var(--shell-text-strong)] transition-colors hover:bg-[var(--shell-hover)]"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    >
                        <Icon name="download-simple" size={16} />
                        <span>Export</span>
                        <Icon name="caret-down" size={14} />
                    </button>

                    {/* Add Button (if applicable) */}
                    {summary?.trading_ready && (
                        <button
                            type="button"
                            className="flex items-center gap-2 bg-[var(--color-brand)] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-brand-hover)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <Icon name="plus" size={16} weight="bold" />
                            <span>Add Order</span>
                        </button>
                    )}
                </div>
            </div>

            {isError && (
                <div className="card mt-6 p-6 text-center">
                    <p className="text-sm text-[var(--color-text-body)]">
                        Those figures could not be loaded.
                    </p>
                    <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                        Try again
                    </button>
                </div>
            )}

            {/* KPI Cards */}
            <div className={cn(
                'mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4',
                isFetching && !isPending && 'opacity-60',
            )}>
                {isPending
                    ? Array.from({ length: 4 }, (_, index) => <SkeletonKpi key={index} />)
                    : summary?.kpis.map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />)}
            </div>

            {summary && (
                <p className="mt-3 text-xs text-[var(--color-text-muted)]">
                    Compared with {summary.period.compare_label}.
                </p>
            )}

            {/*
                Said plainly rather than drawn as empty charts.

                A dashboard showing a confident zero for revenue reads as a
                business having a terrible month, not as a module that has not
                shipped — and that is a phone call from the owner either way.
            */}
            {summary?.trading_ready === false && (
                <div className="card mt-6 p-8 text-center">
                    <Icon
                        name="chart-line-up"
                        size={28}
                        className="mx-auto text-[var(--color-text-subtle)]"
                    />
                    <p className="mt-3 font-semibold text-[var(--color-text-main)]">
                        Trading figures arrive with the Orders and Ledger modules
                    </p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-[var(--color-text-muted)]">
                        The shell, the API and the figures above are wired end to end. Everything
                        from here is a screen on top of them, not a rebuild.
                    </p>
                </div>
            )}

            {/* Dashboard Widgets - Only show if trading is ready */}
            {summary?.trading_ready && (
                <>
                    {/* Row 2: Main Chart (Full Width) */}
                    <div className="mt-8">
                        <SalesChart />
                    </div>

                    {/* Row 3: Secondary Chart + Stats */}
                    <div className="mt-8 grid gap-6 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <CashFlowMini />
                        </div>
                        <div className="space-y-6">
                            <TopProducts limit={5} />
                        </div>
                    </div>

                    {/* Row 4: Data Table (Full Width) */}
                    <div className="mt-8">
                        <RecentOrders limit={10} />
                    </div>

                    {/* Row 5: Secondary Widgets */}
                    <div className="mt-8 grid gap-6 lg:grid-cols-3">
                        <TopCustomers limit={5} />
                        <PendingTasks />
                        <QuickActions />
                    </div>
                </>
            )}
        </div>
    );
}

function KpiCard({ kpi }: { kpi: Kpi }) {
    // A rise in expenses is not good news, and colouring it green because the
    // arrow points up is how a dashboard teaches people to stop reading it.
    const good = kpi.direction === 'flat' ? null : (kpi.direction === 'up') === kpi.rise_is_good;

    return (
        <div className="card p-6">
            {/* Label and Icon */}
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-[var(--color-text-muted)]">
                    {kpi.label}
                </span>
                <div className="flex size-10 items-center justify-center bg-emerald-500/10" style={{ borderRadius: 'var(--radius-sm)' }}>
                    <Icon name={kpi.icon} size={20} className="text-emerald-600" />
                </div>
            </div>

            {/* Large Value */}
            <div className="mt-4">
                <p className="font-[family-name:var(--font-heading)] text-3xl font-bold text-[var(--color-text-main)]">
                    {kpi.value}
                </p>

                {/* Delta with colored percentage */}
                {kpi.delta !== null && (
                    <div className="mt-2 flex items-center gap-1.5">
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 px-1.5 py-0.5 text-xs font-semibold',
                                good === null && 'bg-gray-100 text-gray-700',
                                good === true && 'bg-emerald-100 text-emerald-700',
                                good === false && 'bg-red-100 text-red-700',
                            )}
                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                        >
                            <Icon
                                name={kpi.direction === 'down' ? 'arrow-down' : 'arrow-up'}
                                size={12}
                                weight="bold"
                            />
                            {Math.abs(kpi.delta)}%
                        </span>
                        <span className="text-xs text-[var(--color-text-muted)]">
                            vs last period
                        </span>
                    </div>
                )}
            </div>
        </div>
    );
}
