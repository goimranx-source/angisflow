import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    QuickActionButton,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type JournalEntry = {
    id: string;
    entry_number: string;
    date: string;
    reference?: string;
    description: string;
    source: 'manual' | 'invoice' | 'payment' | 'order' | 'system';
    lines: {
        id: string;
        account: {
            code: string;
            name: string;
        };
        debit: number;
        credit: number;
    }[];
    total_debit: number;
    total_credit: number;
    status: 'posted' | 'draft' | 'void';
    posted_by?: {
        id: string;
        name: string;
    };
    notes?: string;
    created_at: string;
};

type JournalResponse = {
    data: JournalEntry[];
    summary: {
        total_entries: number;
        total_debits: number;
        total_credits: number;
        unbalanced_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Journal() {
    useDocumentTitle('Daily Journal');

    // State
    const [search, setSearch] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('posted');
    const [selectedEntry, setSelectedEntry] = useState<JournalEntry | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch journal entries
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['journal', { search, dateFrom, dateTo, sourceFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<JournalResponse>('/journal', {
                params: {
                    search,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    source: sourceFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const entries = data?.data ?? [];
    const summary = data?.summary;

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
        setDateFrom('');
        setDateTo('');
        setSourceFilter('');
        setStatusFilter('posted');
    };

    const hasFilters = search || dateFrom || dateTo || sourceFilter || statusFilter !== 'posted';

    // Format currency
    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Source labels
    const sourceLabels = {
        manual: 'Manual Entry',
        invoice: 'Invoice',
        payment: 'Payment',
        order: 'Order',
        system: 'System',
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'warning' | 'neutral'> = {
        posted: 'success',
        draft: 'warning',
        void: 'neutral',
    };

    const statusLabels = {
        posted: 'Posted',
        draft: 'Draft',
        void: 'Void',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Daily Journal"
                    description="Complete record of all accounting transactions"
                    icon="notebook"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export journal')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="New Entry"
                                onClick={() => console.log('Create journal entry')}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Entries"
                        value={summary.total_entries.toLocaleString()}
                        icon="notebook"
                        variant="brand"
                    />
                    <KPICard
                        label="Total Debits"
                        value={formatMoney(summary.total_debits)}
                        icon="arrow-up"
                        variant="info"
                    />
                    <KPICard
                        label="Total Credits"
                        value={formatMoney(summary.total_credits)}
                        icon="arrow-down"
                        variant="success"
                    />
                    <KPICard
                        label="Unbalanced"
                        value={summary.unbalanced_count.toLocaleString()}
                        icon="warning"
                        variant={summary.unbalanced_count > 0 ? 'danger' : 'success'}
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by entry #, description, or reference..."
                    filters={
                        <>
                            <FilterSelect
                                label="Source"
                                value={sourceFilter}
                                onChange={setSourceFilter}
                                options={[
                                    { value: 'manual', label: 'Manual' },
                                    { value: 'invoice', label: 'Invoice' },
                                    { value: 'payment', label: 'Payment' },
                                    { value: 'order', label: 'Order' },
                                    { value: 'system', label: 'System' },
                                ]}
                                placeholder="All sources"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'posted', label: 'Posted' },
                                    { value: 'draft', label: 'Draft' },
                                    { value: 'void', label: 'Void' },
                                ]}
                                placeholder="All statuses"
                            />
                            <div className="flex items-center gap-2">
                                <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                                    From:
                                </label>
                                <input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                    className="border border-[var(--color-border-light)] bg-white py-1.5 px-3 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                                    To:
                                </label>
                                <input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    className="border border-[var(--color-border-light)] bg-white py-1.5 px-3 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                />
                            </div>
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
                            Failed to load journal entries.
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
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
                                    key: 'entry_number',
                                    label: 'Entry #',
                                    sortable: true,
                                    render: (entry) => (
                                        <span className="font-mono text-xs text-[var(--color-text-muted)]">
                                            {entry.entry_number}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'date',
                                    label: 'Date',
                                    sortable: true,
                                    accessor: (e) => formatDate(e.date),
                                },
                                {
                                    key: 'description',
                                    label: 'Description',
                                    render: (entry) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {entry.description}
                                                {entry.status === 'void' && (
                                                    <span className="ml-2 text-xs font-normal text-[var(--color-danger)]">
                                                        · voided
                                                    </span>
                                                )}
                                            </p>
                                            {entry.reference && (
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    Ref: {entry.reference}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'source',
                                    label: 'Source',
                                    accessor: (e) => sourceLabels[e.source],
                                },
                                {
                                    key: 'lines_count',
                                    label: 'Lines',
                                    align: 'center',
                                    accessor: (e) => e.lines.length,
                                },
                                {
                                    key: 'total_debit',
                                    label: 'Debit',
                                    sortable: true,
                                    align: 'right',
                                    render: (e) => (
                                        <span className="font-mono text-sm tabular-nums text-[var(--color-text-main)]">
                                            {formatMoney(e.total_debit)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'total_credit',
                                    label: 'Credit',
                                    sortable: true,
                                    align: 'right',
                                    render: (e) => (
                                        <span className="font-mono text-sm tabular-nums text-[var(--color-text-main)]">
                                            {formatMoney(e.total_credit)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'balance',
                                    label: 'Balance',
                                    align: 'center',
                                    render: (e) => {
                                        const balanced = Math.abs(e.total_debit - e.total_credit) < 0.01;
                                        return balanced ? (
                                            <Icon
                                                name="check-circle"
                                                size={18}
                                                weight="fill"
                                                className="text-[var(--color-success)]"
                                            />
                                        ) : (
                                            <Icon
                                                name="warning"
                                                size={18}
                                                weight="fill"
                                                className="text-[var(--color-danger)]"
                                            />
                                        );
                                    },
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
                                    icon="notebook"
                                    title={hasFilters ? 'No entries match' : 'No journal entries yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Journal entries will be created automatically from transactions.'
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
                title={selectedEntry?.entry_number ?? ''}
                subtitle={selectedEntry ? `${selectedEntry.description} · ${formatDate(selectedEntry.date)}` : ''}
                size="lg"
            >
                {selectedEntry && (
                    <div className="space-y-6">
                        <DrawerSection title="Entry Details">
                            <DrawerField
                                label="Entry Number"
                                value={selectedEntry.entry_number}
                                icon="hash"
                            />
                            <DrawerField
                                label="Date"
                                value={formatDate(selectedEntry.date)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Description"
                                value={selectedEntry.description}
                                icon="text-align-left"
                            />
                            {selectedEntry.reference && (
                                <DrawerField
                                    label="Reference"
                                    value={selectedEntry.reference}
                                    icon="link"
                                />
                            )}
                            <DrawerField
                                label="Source"
                                value={sourceLabels[selectedEntry.source]}
                                icon="folder"
                            />
                            <DrawerField
                                label="Status"
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

                        <DrawerSection title="Journal Lines">
                            <div className="overflow-hidden rounded border border-[var(--color-border-light)]">
                                <table className="w-full text-sm">
                                    <thead className="bg-[var(--shell-hover)]">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium text-[var(--color-text-muted)]">
                                                Account
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium text-[var(--color-text-muted)]">
                                                Debit
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium text-[var(--color-text-muted)]">
                                                Credit
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[var(--color-border-light)]">
                                        {selectedEntry.lines.map((line) => (
                                            <tr key={line.id} className="hover:bg-[var(--shell-hover)]">
                                                <td className="px-3 py-2">
                                                    <div>
                                                        <p className="font-medium text-[var(--color-text-main)]">
                                                            {line.account.name}
                                                        </p>
                                                        <p className="text-xs text-[var(--color-text-muted)]">
                                                            [{line.account.code}]
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums">
                                                    {line.debit > 0 ? formatMoney(line.debit) : '—'}
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums">
                                                    {line.credit > 0 ? formatMoney(line.credit) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                        <tr className="bg-[var(--shell-hover)] font-semibold">
                                            <td className="px-3 py-2 text-[var(--color-text-main)]">
                                                Total
                                            </td>
                                            <td className="px-3 py-2 text-right font-mono tabular-nums text-[var(--color-text-main)]">
                                                {formatMoney(selectedEntry.total_debit)}
                                            </td>
                                            <td className="px-3 py-2 text-right font-mono tabular-nums text-[var(--color-text-main)]">
                                                {formatMoney(selectedEntry.total_credit)}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            {/* Balance Check */}
                            <div className="mt-3 flex items-center gap-2">
                                {Math.abs(selectedEntry.total_debit - selectedEntry.total_credit) < 0.01 ? (
                                    <>
                                        <Icon
                                            name="check-circle"
                                            size={16}
                                            weight="fill"
                                            className="text-[var(--color-success)]"
                                        />
                                        <span className="text-sm text-[var(--color-success)]">
                                            Entry is balanced
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <Icon
                                            name="warning"
                                            size={16}
                                            weight="fill"
                                            className="text-[var(--color-danger)]"
                                        />
                                        <span className="text-sm text-[var(--color-danger)]">
                                            Entry is not balanced (difference: {formatMoney(Math.abs(selectedEntry.total_debit - selectedEntry.total_credit))})
                                        </span>
                                    </>
                                )}
                            </div>
                        </DrawerSection>

                        {selectedEntry.posted_by && (
                            <DrawerSection title="Posted By">
                                <DrawerField
                                    label="User"
                                    value={selectedEntry.posted_by.name}
                                    icon="user"
                                />
                                <DrawerField
                                    label="Posted On"
                                    value={formatDate(selectedEntry.created_at)}
                                    icon="calendar-check"
                                />
                            </DrawerSection>
                        )}

                        {selectedEntry.notes && (
                            <DrawerSection title="Notes">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedEntry.notes}
                                </p>
                            </DrawerSection>
                        )}
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
