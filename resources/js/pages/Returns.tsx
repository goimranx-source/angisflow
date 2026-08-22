import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { useMoney } from '@/hooks/useMoney';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Return = {
    id: string;
    return_number: string;
    order: {
        id: string;
        number: string;
    };
    customer: {
        id: string;
        name: string;
    };
    type: 'return' | 'rto' | 'exchange';
    reason: 'defective' | 'wrong_item' | 'not_needed' | 'damaged' | 'delivery_failed' | 'other';
    status: 'requested' | 'approved' | 'in_transit' | 'received' | 'refunded' | 'rejected';
    items_count: number;
    refund_amount: number;
    created_at: string;
    updated_at: string;
};

type ReturnsResponse = {
    data: Return[];
    summary: {
        total_returns: number;
        pending_count: number;
        total_refunded: number;
        return_rate: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Returns() {
    useDocumentTitle('Returns & RTO');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedReturn, setSelectedReturn] = useState<Return | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['returns', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<ReturnsResponse>('/returns', {
                params: {
                    search,
                    type: typeFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const returns = data?.data ?? [];
    const summary = data?.summary;

    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    const handleClearFilters = () => {
        setSearch('');
        setTypeFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || typeFilter || statusFilter;

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney } = useMoney();

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const typeLabels: Record<string, string> = {
        return: 'Return',
        rto: 'RTO',
        exchange: 'Exchange',
    };

    const reasonLabels: Record<string, string> = {
        defective: 'Defective',
        wrong_item: 'Wrong Item',
        not_needed: 'Not Needed',
        damaged: 'Damaged',
        delivery_failed: 'Delivery Failed',
        other: 'Other',
    };

    const statusVariants: Record<string, 'info' | 'warning' | 'success' | 'danger' | 'neutral'> = {
        requested: 'info',
        approved: 'warning',
        in_transit: 'warning',
        received: 'info',
        refunded: 'success',
        rejected: 'danger',
    };

    const statusLabels = {
        requested: 'Requested',
        approved: 'Approved',
        in_transit: 'In Transit',
        received: 'Received',
        refunded: 'Refunded',
        rejected: 'Rejected',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Returns & RTO"
                    description="Manage product returns and return-to-origin shipments"
                    icon="arrow-u-down-left"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Create return')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Create return</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Returns"
                        value={summary.total_returns.toLocaleString()}
                        icon="arrow-u-down-left"
                        variant="brand"
                    />
                    <KPICard
                        label="Pending"
                        value={summary.pending_count.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                    <KPICard
                        label="Total Refunded"
                        value={formatMoney(summary.total_refunded)}
                        icon="currency-dollar"
                        variant="danger"
                    />
                    <KPICard
                        label="Return Rate"
                        value={`${summary.return_rate}%`}
                        icon="percent"
                        variant={summary.return_rate > 5 ? 'danger' : 'success'}
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search returns or orders..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'return', label: 'Return' },
                                    { value: 'rto', label: 'RTO' },
                                    { value: 'exchange', label: 'Exchange' },
                                ]}
                                placeholder="All types"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'requested', label: 'Requested' },
                                    { value: 'approved', label: 'Approved' },
                                    { value: 'in_transit', label: 'In Transit' },
                                    { value: 'received', label: 'Received' },
                                    { value: 'refunded', label: 'Refunded' },
                                ]}
                                placeholder="All statuses"
                            />
                            {hasFilters && (
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                >
                                    <Icon name="x" size={14} />
                                    <span>Clear</span>
                                </button>
                            )}
                        </>
                    }
                />
            </div>

            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load returns.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={returns}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'return_number',
                                    label: 'Return',
                                    render: (ret) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {ret.return_number}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                Order: {ret.order.number}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'customer',
                                    label: 'Customer',
                                    accessor: (ret) => ret.customer.name,
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (ret) => typeLabels[ret.type],
                                },
                                {
                                    key: 'reason',
                                    label: 'Reason',
                                    accessor: (ret) => reasonLabels[ret.reason],
                                },
                                {
                                    key: 'items_count',
                                    label: 'Items',
                                    align: 'right',
                                    render: (ret) => (
                                        <span className="tabular-nums">{ret.items_count}</span>
                                    ),
                                },
                                {
                                    key: 'refund_amount',
                                    label: 'Refund',
                                    align: 'right',
                                    sortable: true,
                                    render: (ret) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatMoney(ret.refund_amount)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (ret) => (
                                        <StatusBadge
                                            label={statusLabels[ret.status]}
                                            variant={statusVariants[ret.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(ret) => setSelectedReturn(ret)}
                            clickable
                            getRowKey={(ret) => ret.id}
                            emptyState={
                                <EmptyState
                                    icon="arrow-u-down-left"
                                    title={hasFilters ? 'No returns match' : 'No returns yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Returns will appear here when customers request them.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : null
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            <DetailDrawer
                open={!!selectedReturn}
                onClose={() => setSelectedReturn(null)}
                title={selectedReturn?.return_number ?? ''}
                subtitle={selectedReturn ? `Order: ${selectedReturn.order.number}` : ''}
                size="md"
            >
                {selectedReturn && (
                    <div className="space-y-6">
                        <DrawerSection title="Return Details">
                            <DrawerField label="Customer" value={selectedReturn.customer.name} icon="user" />
                            <DrawerField label="Type" value={typeLabels[selectedReturn.type]} icon="tag" />
                            <DrawerField label="Reason" value={reasonLabels[selectedReturn.reason]} icon="question" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedReturn.status]}
                                        variant={statusVariants[selectedReturn.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Items">
                            <DrawerField
                                label="Items Count"
                                value={selectedReturn.items_count.toString()}
                                icon="package"
                            />
                            <DrawerField
                                label="Refund Amount"
                                value={formatMoney(selectedReturn.refund_amount)}
                                icon="currency-dollar"
                            />
                        </DrawerSection>

                        <DrawerSection title="Timeline">
                            <DrawerField label="Created" value={formatDate(selectedReturn.created_at)} icon="calendar" />
                            <DrawerField
                                label="Last Updated"
                                value={formatDate(selectedReturn.updated_at)}
                                icon="clock"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            {selectedReturn.status === 'requested' && (
                                <>
                                    <button className="btn btn-primary flex-1">
                                        <Icon name="check" size={16} />
                                        <span>Approve</span>
                                    </button>
                                    <button className="btn btn-secondary text-[var(--color-danger)]">
                                        <Icon name="x" size={16} />
                                        <span>Reject</span>
                                    </button>
                                </>
                            )}
                            {selectedReturn.status === 'received' && (
                                <button className="btn btn-primary w-full">
                                    <Icon name="currency-dollar" size={16} />
                                    <span>Process Refund</span>
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
