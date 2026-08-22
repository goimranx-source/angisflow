import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    CashFlowMini,
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
    };
};

/**
 * E-Commerce Dashboard
 * 
 * Optimized for online businesses selling through websites, marketplaces, or apps.
 * 
 * Key metrics:
 * - Online revenue & orders
 * - Conversion rate
 * - Cart abandonment
 * - Top products & customers
 * - Delivery status
 * - Revenue by channel
 */
/** `2026-08-16` for the first and last day of the current month — this view
 *  has no picker of its own yet, so its panels are fixed to this_month. */
function toIsoDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
        d.getDate(),
    ).padStart(2, '0')}`;
}

const now = new Date();
const monthFrom = toIsoDate(new Date(now.getFullYear(), now.getMonth(), 1));
const monthTo = toIsoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));

export default function ECommerceDashboard() {
    // No period selector in this view yet — fixed to this_month.
    const [period] = useState<string>('this_month');

    useDocumentTitle('E-Commerce Dashboard');

    const { data, isPending, isFetching, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'ecommerce', period],
        queryFn: ({ signal }) =>
            api.get<DashboardPayload>('/dashboard/ecommerce', { params: { period }, signal }),
        placeholderData: (previous) => previous,
    });

    const summary = data?.data;

    return (
        <div className="mx-auto max-w-[1400px]">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-[var(--color-text-main)]">
                        E-Commerce Dashboard
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

                    {/* Add Order Button */}
                    <button
                        type="button"
                        className="flex items-center gap-2 bg-[var(--color-brand)] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-brand-hover)]"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    >
                        <Icon name="plus" size={16} weight="bold" />
                        <span>New Order</span>
                    </button>
                </div>
            </div>

            {isError && (
                <div className="card mt-6 p-6 text-center">
                    <p className="text-sm text-[var(--color-text-body)]">
                        Dashboard data could not be loaded.
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

            {/* Dashboard Widgets */}
            <>
                {/* Row 1: Main Sales Chart (Full Width) */}
                <div className="mt-8">
                    <SalesChart from={monthFrom} to={monthTo} />
                </div>

                {/* Row 2: Cash Flow + Top Products */}
                <div className="mt-8 grid gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <CashFlowMini from={monthFrom} to={monthTo} />
                    </div>
                    <div>
                        <TopProducts limit={5} />
                    </div>
                </div>

                {/* Row 3: Recent Orders (Full Width) */}
                <div className="mt-8">
                    <RecentOrders limit={10} />
                </div>

                {/* Row 4: Top Customers */}
                <div className="mt-8 grid gap-6 lg:grid-cols-3">
                    <TopCustomers limit={5} />
                    
                    {/* E-Commerce Specific: Conversion Funnel */}
                    <div className="card p-6">
                        <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                            Conversion Funnel
                        </h3>
                        <div className="mt-4 space-y-3">
                            <div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-[var(--color-text-muted)]">Visitors</span>
                                    <span className="font-semibold text-[var(--color-text-main)]">12,450</span>
                                </div>
                                <div className="mt-1 h-2 w-full bg-[var(--color-card-bg)]" style={{ borderRadius: 'var(--shell-radius-sm)' }}>
                                    <div className="h-full bg-[var(--color-brand)]" style={{ width: '100%', borderRadius: 'var(--shell-radius-sm)' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-[var(--color-text-muted)]">Add to Cart</span>
                                    <span className="font-semibold text-[var(--color-text-main)]">2,890</span>
                                </div>
                                <div className="mt-1 h-2 w-full bg-[var(--color-card-bg)]" style={{ borderRadius: 'var(--shell-radius-sm)' }}>
                                    <div className="h-full bg-[var(--color-brand)]" style={{ width: '23%', borderRadius: 'var(--shell-radius-sm)' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-[var(--color-text-muted)]">Checkout</span>
                                    <span className="font-semibold text-[var(--color-text-main)]">1,245</span>
                                </div>
                                <div className="mt-1 h-2 w-full bg-[var(--color-card-bg)]" style={{ borderRadius: 'var(--shell-radius-sm)' }}>
                                    <div className="h-full bg-[var(--color-brand)]" style={{ width: '10%', borderRadius: 'var(--shell-radius-sm)' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-[var(--color-text-muted)]">Orders</span>
                                    <span className="font-semibold text-[var(--color-text-main)]">856</span>
                                </div>
                                <div className="mt-1 h-2 w-full bg-[var(--color-card-bg)]" style={{ borderRadius: 'var(--shell-radius-sm)' }}>
                                    <div className="h-full bg-emerald-600" style={{ width: '6.9%', borderRadius: 'var(--shell-radius-sm)' }} />
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 rounded-lg bg-[var(--color-brand-subtle)] p-3">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-[var(--color-text-muted)]">
                                    Conversion Rate
                                </span>
                                <span className="text-sm font-bold text-[var(--color-brand)]">
                                    6.9%
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Channel Performance */}
                    <div className="card p-6">
                        <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                            Revenue by Channel
                        </h3>
                        <div className="mt-4 space-y-3">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="h-3 w-3 bg-[var(--color-brand)]" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                                    <span className="text-sm text-[var(--color-text-muted)]">Website</span>
                                </div>
                                <span className="text-sm font-semibold text-[var(--color-text-main)]">
                                    $45,680
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="h-3 w-3 bg-amber-500" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                                    <span className="text-sm text-[var(--color-text-muted)]">Marketplace</span>
                                </div>
                                <span className="text-sm font-semibold text-[var(--color-text-main)]">
                                    $28,340
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="h-3 w-3 bg-purple-500" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                                    <span className="text-sm text-[var(--color-text-muted)]">Social Media</span>
                                </div>
                                <span className="text-sm font-semibold text-[var(--color-text-main)]">
                                    $12,890
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="h-3 w-3 bg-green-500" style={{ borderRadius: 'var(--shell-radius-sm)' }} />
                                    <span className="text-sm text-[var(--color-text-muted)]">Mobile App</span>
                                </div>
                                <span className="text-sm font-semibold text-[var(--color-text-main)]">
                                    $8,560
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </>
        </div>
    );
}

function KpiCard({ kpi }: { kpi: Kpi }) {
    const good = kpi.direction === 'flat' ? null : (kpi.direction === 'up') === kpi.rise_is_good;

    return (
        <div className="card p-6">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-[var(--color-text-muted)]">
                    {kpi.label}
                </span>
                <div className="flex size-10 items-center justify-center bg-emerald-500/10" style={{ borderRadius: 'var(--radius-sm)' }}>
                    <Icon name={kpi.icon} size={20} className="text-emerald-600" />
                </div>
            </div>

            <div className="mt-4">
                <p className="font-[family-name:var(--font-heading)] text-3xl font-bold text-[var(--color-text-main)]">
                    {kpi.value}
                </p>

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
