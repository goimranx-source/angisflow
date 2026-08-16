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

type Invoice = {
    id: string;
    invoice_number: string;
    customer: {
        id: string;
        name: string;
        email: string;
    };
    amount: number;
    paid_amount: number;
    balance: number;
    status: 'draft' | 'sent' | 'viewed' | 'partial' | 'paid' | 'overdue' | 'cancelled';
    issue_date: string;
    due_date: string;
    items_count: number;
    created_at: string;
};

type InvoicesResponse = {
    data: Invoice[];
    summary: {
        total_invoices: number;
        total_amount: number;
        outstanding: number;
        overdue_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Invoicing() {
    useDocumentTitle('Invoicing');

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['invoices', { search, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<InvoicesResponse>('/invoices', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const invoices = data?.data ?? [];
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
        setStatusFilter('');
    };

    const hasFilters = search || statusFilter;

    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
    };

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const statusVariants: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
        draft: 'neutral',
        sent: 'info',
        viewed: 'info',
        partial: 'warning',
        paid: 'success',
        overdue: 'danger',
        cancelled: 'neutral',
    };

    const statusLabels = {
        draft: 'Draft',
        sent: 'Sent',
        viewed: 'Viewed',
        partial: 'Partial',
        paid: 'Paid',
        overdue: 'Overdue',
        cancelled: 'Cancelled',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Invoicing"
                    description="Create and manage customer invoices"
                    icon="receipt"
                    actions={
                        <button type="button" className="btn btn-primary" onClick={() => console.log('Create invoice')}>
                            <Icon name="plus" size={16} />
                            <span>Create invoice</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Invoices"
                        value={summary.total_invoices.toLocaleString()}
                        icon="receipt"
                        variant="brand"
                    />
                    <KPICard
                        label="Total Amount"
                        value={formatMoney(summary.total_amount)}
                        icon="currency-dollar"
                        variant="info"
                    />
                    <KPICard
                        label="Outstanding"
                        value={formatMoney(summary.outstanding)}
                        icon="warning"
                        variant="warning"
                    />
                    <KPICard
                        label="Overdue"
                        value={summary.overdue_count.toLocaleString()}
                        icon="clock"
                        variant="danger"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search invoices or customers..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'draft', label: 'Draft' },
                                    { value: 'sent', label: 'Sent' },
                                    { value: 'paid', label: 'Paid' },
                                    { value: 'overdue', label: 'Overdue' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load invoices.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={invoices}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'invoice_number',
                                    label: 'Invoice',
                                    render: (inv) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {inv.invoice_number}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {inv.items_count} items
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'customer',
                                    label: 'Customer',
                                    accessor: (inv) => inv.customer.name,
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    align: 'right',
                                    sortable: true,
                                    render: (inv) => (
                                        <span className="font-semibold tabular-nums">{formatMoney(inv.amount)}</span>
                                    ),
                                },
                                {
                                    key: 'balance',
                                    label: 'Balance',
                                    align: 'right',
                                    sortable: true,
                                    render: (inv) => (
                                        <span
                                            className={`font-semibold tabular-nums ${inv.balance > 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]'}`}
                                        >
                                            {formatMoney(inv.balance)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (inv) => (
                                        <StatusBadge
                                            label={statusLabels[inv.status]}
                                            variant={statusVariants[inv.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'due_date',
                                    label: 'Due Date',
                                    sortable: true,
                                    accessor: (inv) => formatDate(inv.due_date),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(inv) => setSelectedInvoice(inv)}
                            clickable
                            getRowKey={(inv) => inv.id}
                            emptyState={
                                <EmptyState
                                    icon="receipt"
                                    title={hasFilters ? 'No invoices match' : 'No invoices yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first invoice to bill customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create invoice</span>
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
                open={!!selectedInvoice}
                onClose={() => setSelectedInvoice(null)}
                title={selectedInvoice?.invoice_number ?? ''}
                subtitle={selectedInvoice?.customer.name ?? ''}
                size="md"
            >
                {selectedInvoice && (
                    <div className="space-y-6">
                        <DrawerSection title="Invoice Details">
                            <DrawerField
                                label="Customer"
                                value={selectedInvoice.customer.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Amount"
                                value={formatMoney(selectedInvoice.amount)}
                                icon="currency-dollar"
                            />
                            <DrawerField
                                label="Paid"
                                value={formatMoney(selectedInvoice.paid_amount)}
                                icon="check-circle"
                            />
                            <DrawerField
                                label="Balance"
                                value={
                                    <span
                                        className={
                                            selectedInvoice.balance > 0
                                                ? 'text-[var(--color-danger)]'
                                                : 'text-[var(--color-success)]'
                                        }
                                    >
                                        {formatMoney(selectedInvoice.balance)}
                                    </span>
                                }
                                icon="scales"
                            />
                        </DrawerSection>
                        <DrawerSection title="Dates">
                            <DrawerField
                                label="Issue Date"
                                value={formatDate(selectedInvoice.issue_date)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Due Date"
                                value={formatDate(selectedInvoice.due_date)}
                                icon="calendar-check"
                            />
                        </DrawerSection>
                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="eye" size={16} />
                                <span>View</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="download-simple" size={16} />
                                <span>Download</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
