import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    ViewToggleButton,
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

type LedgerEntry = {
    id: string;
    contact: {
        id: string;
        name: string;
        type: 'customer' | 'vendor';
    };
    invoice_number?: string;
    bill_number?: string;
    date: string;
    due_date: string;
    amount: number;
    paid: number;
    balance: number;
    status: 'pending' | 'partial' | 'paid' | 'overdue';
    days_overdue?: number;
    created_at: string;
};

type ReceivableResponse = {
    data: LedgerEntry[];
    summary: {
        total_receivable: number;
        current: number;
        overdue: number;
        overdue_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

type PayableResponse = {
    data: LedgerEntry[];
    summary: {
        total_payable: number;
        current: number;
        overdue: number;
        overdue_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Ledgers() {
    useDocumentTitle('Receivable & Payable');

    // State
    const [view, setView] = useState<'receivable' | 'payable'>('receivable');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedEntry, setSelectedEntry] = useState<LedgerEntry | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('due_date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('asc');

    // Fetch receivables
    const { data: receivableData, isLoading: loadingReceivable, isError: errorReceivable, refetch: refetchReceivable } = useQuery({
        queryKey: ['receivable', { search, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<ReceivableResponse>('/ledger/receivable', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'receivable',
    });

    // Fetch payables
    const { data: payableData, isLoading: loadingPayable, isError: errorPayable, refetch: refetchPayable } = useQuery({
        queryKey: ['payable', { search, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<PayableResponse>('/ledger/payable', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'payable',
    });

    const entries = view === 'receivable' ? (receivableData?.data ?? []) : (payableData?.data ?? []);
    const summary = view === 'receivable' ? receivableData?.summary : payableData?.summary;
    const isLoading = view === 'receivable' ? loadingReceivable : loadingPayable;
    const isError = view === 'receivable' ? errorReceivable : errorPayable;

    // Sort handler
    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    // Clear filters
    const handleClearFilters = () => {
        setSearch('');
        setStatusFilter('');
    };

    const hasFilters = search || statusFilter;

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney } = useMoney();

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Status variants
    const statusVariants: Record<string, 'warning' | 'info' | 'success' | 'danger'> = {
        pending: 'warning',
        partial: 'info',
        paid: 'success',
        overdue: 'danger',
    };

    const statusLabels = {
        pending: 'Pending',
        partial: 'Partially Paid',
        paid: 'Paid',
        overdue: 'Overdue',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Receivable & Payable"
                    description="Track amounts owed to you and amounts you owe"
                    icon="scales"
                    actions={
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => console.log('Export')}
                        >
                            <Icon name="download-simple" size={16} />
                            <span>Export</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label={view === 'receivable' ? 'Total Receivable' : 'Total Payable'}
                        value={formatMoney('total_receivable' in summary ? summary.total_receivable : summary.total_payable)}
                        icon="currency-dollar"
                        variant="brand"
                    />
                    <KPICard
                        label="Current"
                        value={formatMoney(summary.current)}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Overdue"
                        value={formatMoney(summary.overdue)}
                        icon="warning"
                        variant="danger"
                    />
                    <KPICard
                        label="Overdue Count"
                        value={summary.overdue_count.toLocaleString()}
                        icon="exclamation-mark"
                        variant="warning"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 px-6">
                <div className="flex gap-2">
                    <ViewToggleButton
                        icon="arrow-down-left"
                        label="Receivable (AR)"
                        active={view === 'receivable'}
                        onClick={() => setView('receivable')}
                    />
                    <ViewToggleButton
                        icon="arrow-up-right"
                        label="Payable (AP)"
                        active={view === 'payable'}
                        onClick={() => setView('payable')}
                    />
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder={
                        view === 'receivable'
                            ? 'Search customers or invoice numbers...'
                            : 'Search vendors or bill numbers...'
                    }
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'partial', label: 'Partially Paid' },
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

            {/* Content */}
            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load {view === 'receivable' ? 'receivables' : 'payables'}.
                        </p>
                        <button
                            type="button"
                            onClick={() => void (view === 'receivable' ? refetchReceivable() : refetchPayable())}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={entries}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'contact',
                                    label: view === 'receivable' ? 'Customer' : 'Vendor',
                                    render: (entry) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {entry.contact.name}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {view === 'receivable'
                                                    ? `Invoice: ${entry.invoice_number || '—'}`
                                                    : `Bill: ${entry.bill_number || '—'}`}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'date',
                                    label: 'Date',
                                    sortable: true,
                                    accessor: (e) => formatDate(e.date),
                                },
                                {
                                    key: 'due_date',
                                    label: 'Due Date',
                                    sortable: true,
                                    render: (e) => (
                                        <div>
                                            <p className="text-sm text-[var(--color-text-main)]">
                                                {formatDate(e.due_date)}
                                            </p>
                                            {e.days_overdue && e.days_overdue > 0 && (
                                                <p className="mt-0.5 text-xs text-[var(--color-danger)]">
                                                    {e.days_overdue} days overdue
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    sortable: true,
                                    align: 'right',
                                    render: (e) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatMoney(e.amount)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'paid',
                                    label: 'Paid',
                                    sortable: true,
                                    align: 'right',
                                    render: (e) => (
                                        <span className="tabular-nums text-[var(--color-success)]">
                                            {formatMoney(e.paid)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'balance',
                                    label: 'Balance',
                                    sortable: true,
                                    align: 'right',
                                    render: (e) => (
                                        <span className={`font-semibold tabular-nums ${e.balance > 0 ? 'text-[var(--color-danger)]' : ''}`}>
                                            {formatMoney(e.balance)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (e) => (
                                        <StatusBadge
                                            label={statusLabels[e.status]}
                                            variant={statusVariants[e.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(entry) => setSelectedEntry(entry)}
                            clickable
                            getRowKey={(entry) => entry.id}
                            emptyState={
                                <EmptyState
                                    icon={view === 'receivable' ? 'arrow-down-left' : 'arrow-up-right'}
                                    title={
                                        hasFilters
                                            ? 'No entries match'
                                            : view === 'receivable'
                                              ? 'No receivables yet'
                                              : 'No payables yet'
                                    }
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : view === 'receivable'
                                              ? 'Receivables will appear when you create invoices for customers.'
                                              : 'Payables will appear when you receive bills from vendors.'
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

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedEntry}
                onClose={() => setSelectedEntry(null)}
                title={selectedEntry?.contact.name ?? ''}
                subtitle={
                    selectedEntry
                        ? view === 'receivable'
                            ? `Invoice: ${selectedEntry.invoice_number || '—'}`
                            : `Bill: ${selectedEntry.bill_number || '—'}`
                        : ''
                }
                size="md"
            >
                {selectedEntry && (
                    <div className="space-y-6">
                        <DrawerSection title="Entry Details">
                            <DrawerField
                                label={view === 'receivable' ? 'Customer' : 'Vendor'}
                                value={selectedEntry.contact.name}
                                icon="user"
                            />
                            <DrawerField
                                label={view === 'receivable' ? 'Invoice Number' : 'Bill Number'}
                                value={
                                    view === 'receivable'
                                        ? selectedEntry.invoice_number || '—'
                                        : selectedEntry.bill_number || '—'
                                }
                                icon="hash"
                            />
                            <DrawerField
                                label="Date"
                                value={formatDate(selectedEntry.date)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Due Date"
                                value={formatDate(selectedEntry.due_date)}
                                icon="calendar-check"
                            />
                            {selectedEntry.days_overdue && selectedEntry.days_overdue > 0 && (
                                <DrawerField
                                    label="Days Overdue"
                                    value={
                                        <span className="text-[var(--color-danger)]">
                                            {selectedEntry.days_overdue} days
                                        </span>
                                    }
                                    icon="warning"
                                />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Amounts">
                            <DrawerField
                                label="Total Amount"
                                value={formatMoney(selectedEntry.amount)}
                                icon="currency-dollar"
                            />
                            <DrawerField
                                label="Amount Paid"
                                value={
                                    <span className="text-[var(--color-success)]">
                                        {formatMoney(selectedEntry.paid)}
                                    </span>
                                }
                                icon="check-circle"
                            />
                            <DrawerField
                                label="Outstanding Balance"
                                value={
                                    <span className={`font-semibold ${selectedEntry.balance > 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]'}`}>
                                        {formatMoney(selectedEntry.balance)}
                                    </span>
                                }
                                icon="scales"
                            />
                        </DrawerSection>

                        <DrawerSection title="Status">
                            <DrawerField
                                label="Payment Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedEntry.status]}
                                        variant={statusVariants[selectedEntry.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        {selectedEntry.balance > 0 && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-warning-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon
                                        name="warning"
                                        size={20}
                                        className="flex-none text-[var(--color-warning)]"
                                    />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            {view === 'receivable' ? 'Payment Due' : 'Payment Required'}
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            {view === 'receivable'
                                                ? `This customer owes ${formatMoney(selectedEntry.balance)}. Consider sending a payment reminder.`
                                                : `You owe ${formatMoney(selectedEntry.balance)} to this vendor. Ensure timely payment to maintain good relationships.`}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
