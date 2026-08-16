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
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Payment = {
    id: string;
    reference: string;
    customer: {
        id: string;
        name: string;
    };
    amount: number;
    method: 'cash' | 'card' | 'bank_transfer' | 'upi' | 'wallet' | 'other';
    status: 'pending' | 'completed' | 'failed' | 'refunded';
    invoice?: {
        id: string;
        number: string;
    };
    processed_at?: string;
    created_at: string;
};

type PaymentsResponse = {
    data: Payment[];
    summary: {
        total_payments: number;
        total_amount: number;
        pending_amount: number;
        failed_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Payments() {
    useDocumentTitle('Payments');

    const [search, setSearch] = useState('');
    const [methodFilter, setMethodFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedPayment, setSelectedPayment] = useState<Payment | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['payments', { search, methodFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<PaymentsResponse>('/payments', {
                params: {
                    search,
                    method: methodFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const payments = data?.data ?? [];
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
        setMethodFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || methodFilter || statusFilter;

    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
    };

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const methodLabels: Record<string, string> = {
        cash: 'Cash',
        card: 'Card',
        bank_transfer: 'Bank Transfer',
        upi: 'UPI',
        wallet: 'Wallet',
        other: 'Other',
    };

    const statusVariants: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = {
        pending: 'warning',
        completed: 'success',
        failed: 'danger',
        refunded: 'neutral',
    };

    const statusLabels = {
        pending: 'Pending',
        completed: 'Completed',
        failed: 'Failed',
        refunded: 'Refunded',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Payments"
                    description="Track and manage customer payments"
                    icon="currency-circle-dollar"
                    actions={
                        <button type="button" className="btn btn-primary" onClick={() => console.log('Record payment')}>
                            <Icon name="plus" size={16} />
                            <span>Record payment</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Payments"
                        value={summary.total_payments.toLocaleString()}
                        icon="currency-circle-dollar"
                        variant="brand"
                    />
                    <KPICard
                        label="Total Amount"
                        value={formatMoney(summary.total_amount)}
                        icon="currency-dollar"
                        variant="success"
                    />
                    <KPICard
                        label="Pending"
                        value={formatMoney(summary.pending_amount)}
                        icon="clock"
                        variant="warning"
                    />
                    <KPICard label="Failed" value={summary.failed_count.toLocaleString()} icon="x-circle" variant="danger" />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search payments or customers..."
                    filters={
                        <>
                            <FilterSelect
                                label="Method"
                                value={methodFilter}
                                onChange={setMethodFilter}
                                options={[
                                    { value: 'cash', label: 'Cash' },
                                    { value: 'card', label: 'Card' },
                                    { value: 'bank_transfer', label: 'Bank Transfer' },
                                    { value: 'upi', label: 'UPI' },
                                    { value: 'wallet', label: 'Wallet' },
                                ]}
                                placeholder="All methods"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'completed', label: 'Completed' },
                                    { value: 'failed', label: 'Failed' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load payments.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={payments}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'reference',
                                    label: 'Reference',
                                    render: (pmt) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{pmt.reference}</p>
                                            {pmt.invoice && (
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    Invoice: {pmt.invoice.number}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'customer',
                                    label: 'Customer',
                                    accessor: (pmt) => pmt.customer.name,
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    align: 'right',
                                    sortable: true,
                                    render: (pmt) => (
                                        <span className="font-semibold tabular-nums">{formatMoney(pmt.amount)}</span>
                                    ),
                                },
                                {
                                    key: 'method',
                                    label: 'Method',
                                    accessor: (pmt) => methodLabels[pmt.method],
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (pmt) => (
                                        <StatusBadge
                                            label={statusLabels[pmt.status]}
                                            variant={statusVariants[pmt.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'created_at',
                                    label: 'Date',
                                    sortable: true,
                                    accessor: (pmt) => formatDate(pmt.created_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(pmt) => setSelectedPayment(pmt)}
                            clickable
                            getRowKey={(pmt) => pmt.id}
                            emptyState={
                                <EmptyState
                                    icon="currency-circle-dollar"
                                    title={hasFilters ? 'No payments match' : 'No payments yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Payments will appear here as customers pay.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Record payment</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            <DetailDrawer
                open={!!selectedPayment}
                onClose={() => setSelectedPayment(null)}
                title={selectedPayment?.reference ?? ''}
                subtitle={selectedPayment ? formatMoney(selectedPayment.amount) : ''}
                size="md"
            >
                {selectedPayment && (
                    <div className="space-y-6">
                        <DrawerSection title="Payment Details">
                            <DrawerField label="Customer" value={selectedPayment.customer.name} icon="user" />
                            <DrawerField label="Amount" value={formatMoney(selectedPayment.amount)} icon="currency-dollar" />
                            <DrawerField label="Method" value={methodLabels[selectedPayment.method]} icon="credit-card" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedPayment.status]}
                                        variant={statusVariants[selectedPayment.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>
                        {selectedPayment.invoice && (
                            <DrawerSection title="Invoice">
                                <DrawerField
                                    label="Invoice Number"
                                    value={selectedPayment.invoice.number}
                                    icon="receipt"
                                />
                            </DrawerSection>
                        )}
                        <DrawerSection title="Timeline">
                            <DrawerField label="Created" value={formatDate(selectedPayment.created_at)} icon="calendar" />
                            {selectedPayment.processed_at && (
                                <DrawerField
                                    label="Processed"
                                    value={formatDate(selectedPayment.processed_at)}
                                    icon="check-circle"
                                />
                            )}
                        </DrawerSection>
                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-secondary flex-1">
                                <Icon name="download-simple" size={16} />
                                <span>Receipt</span>
                            </button>
                            {selectedPayment.status === 'completed' && (
                                <button className="btn btn-secondary text-[var(--color-danger)]">
                                    <Icon name="arrow-u-up-left" size={16} />
                                    <span>Refund</span>
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
