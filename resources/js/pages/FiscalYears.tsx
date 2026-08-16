import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    QuickCreateModal,
    QuickActionButton,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { useSession } from '@/providers/SessionProvider';

type FiscalYear = {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'closed' | 'locked';
    is_current: boolean;
    period_count: number;
    description?: string;
    created_at: string;
};

type FiscalYearsResponse = {
    data: FiscalYear[];
};

export default function FiscalYears() {
    const { tenant } = useSession();
    useDocumentTitle('Fiscal Years');

    // State
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedFiscalYear, setSelectedFiscalYear] = useState<FiscalYear | null>(null);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('start_date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Form state
    const [form, setForm] = useState({
        name: '',
        start_date: '',
        end_date: '',
        description: '',
    });

    // Fetch fiscal years
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['fiscal-years'],
        queryFn: ({ signal }) => api.get<FiscalYearsResponse>('/fiscal-years', { signal }),
        enabled: tenant?.business !== null,
    });

    const fiscalYears = data?.data ?? [];

    // Filter fiscal years
    const filtered = fiscalYears.filter((fy) => {
        const matchesSearch =
            !search ||
            fy.name.toLowerCase().includes(search.toLowerCase()) ||
            fy.description?.toLowerCase().includes(search.toLowerCase());
        const matchesStatus = !statusFilter || fy.status === statusFilter;

        return matchesSearch && matchesStatus;
    });

    // Sort fiscal years
    const sorted = [...filtered].sort((a, b) => {
        if (!sortBy || !sortDirection) return 0;

        let aVal: string = '';
        let bVal: string = '';

        switch (sortBy) {
            case 'name':
                aVal = a.name;
                bVal = b.name;
                break;
            case 'start_date':
                aVal = a.start_date;
                bVal = b.start_date;
                break;
            case 'end_date':
                aVal = a.end_date;
                bVal = b.end_date;
                break;
            default:
                return 0;
        }

        if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
        if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
        return 0;
    });

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

    // Create fiscal year
    const handleCreateFiscalYear = () => {
        console.log('Creating fiscal year:', form);
        // TODO: Implement create
        setShowCreateModal(false);
    };

    // Close fiscal year
    const handleCloseFiscalYear = (id: string, name: string) => {
        if (confirm(`Close fiscal year "${name}"? This will prevent new transactions from being posted to this period.`)) {
            console.log('Closing fiscal year:', id);
            // TODO: Implement close
        }
    };

    // Lock fiscal year
    const handleLockFiscalYear = (id: string, name: string) => {
        if (confirm(`Lock fiscal year "${name}"? This is permanent and cannot be undone.`)) {
            console.log('Locking fiscal year:', id);
            // TODO: Implement lock
        }
    };

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'info' | 'neutral'> = {
        open: 'success',
        closed: 'info',
        locked: 'neutral',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Fiscal Years"
                    description="Manage accounting periods and year-end closures"
                    icon="calendar-check"
                    actions={
                        <QuickActionButton
                            icon="plus"
                            label="Add Fiscal Year"
                            onClick={() => setShowCreateModal(true)}
                        />
                    }
                />
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search fiscal years..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'open', label: 'Open' },
                                    { value: 'closed', label: 'Closed' },
                                    { value: 'locked', label: 'Locked' },
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
                            Failed to load fiscal years.
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
                            data={sorted}
                            loading={isLoading}
                            skeletonRows={8}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Fiscal Year',
                                    sortable: true,
                                    render: (fy) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {fy.name}
                                                {fy.is_current && (
                                                    <span className="ml-2 rounded-full bg-[var(--color-brand)] px-2 py-0.5 text-xs font-semibold text-white">
                                                        Current
                                                    </span>
                                                )}
                                            </p>
                                            {fy.description && (
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {fy.description}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'start_date',
                                    label: 'Start Date',
                                    sortable: true,
                                    accessor: (fy) => formatDate(fy.start_date),
                                },
                                {
                                    key: 'end_date',
                                    label: 'End Date',
                                    sortable: true,
                                    accessor: (fy) => formatDate(fy.end_date),
                                },
                                {
                                    key: 'period_count',
                                    label: 'Periods',
                                    align: 'center',
                                    accessor: (fy) => fy.period_count,
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (fy) => (
                                        <StatusBadge
                                            label={fy.status.charAt(0).toUpperCase() + fy.status.slice(1)}
                                            variant={statusVariants[fy.status]}
                                            icon={
                                                fy.status === 'open'
                                                    ? 'lock-open'
                                                    : fy.status === 'closed'
                                                      ? 'lock'
                                                      : 'lock-key'
                                            }
                                        />
                                    ),
                                },
                                {
                                    key: 'actions',
                                    label: '',
                                    width: 'w-32',
                                    render: (fy) => (
                                        <div className="flex items-center justify-end gap-2">
                                            {fy.status === 'open' && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleCloseFiscalYear(fy.id, fy.name);
                                                    }}
                                                    className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-brand)] transition-colors"
                                                >
                                                    Close
                                                </button>
                                            )}
                                            {fy.status === 'closed' && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleLockFiscalYear(fy.id, fy.name);
                                                    }}
                                                    className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-danger)] transition-colors"
                                                >
                                                    Lock
                                                </button>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(fy) => setSelectedFiscalYear(fy)}
                            clickable
                            getRowKey={(fy) => fy.id}
                            emptyState={
                                <EmptyState
                                    icon="calendar-check"
                                    title={hasFilters ? 'No fiscal years match' : 'No fiscal years yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first fiscal year to start tracking financial periods.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button
                                                className="btn btn-primary"
                                                onClick={() => setShowCreateModal(true)}
                                            >
                                                Add Fiscal Year
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedFiscalYear}
                onClose={() => setSelectedFiscalYear(null)}
                title={selectedFiscalYear?.name ?? ''}
                subtitle={
                    selectedFiscalYear
                        ? `${formatDate(selectedFiscalYear.start_date)} - ${formatDate(selectedFiscalYear.end_date)}`
                        : ''
                }
                size="md"
                actions={
                    selectedFiscalYear && (
                        <>
                            {selectedFiscalYear.status === 'open' && (
                                <button
                                    className="btn btn-secondary"
                                    onClick={() =>
                                        handleCloseFiscalYear(selectedFiscalYear.id, selectedFiscalYear.name)
                                    }
                                >
                                    <Icon name="lock" size={16} />
                                    <span>Close Year</span>
                                </button>
                            )}
                            {selectedFiscalYear.status === 'closed' && (
                                <button
                                    className="btn btn-danger"
                                    onClick={() =>
                                        handleLockFiscalYear(selectedFiscalYear.id, selectedFiscalYear.name)
                                    }
                                >
                                    <Icon name="lock-key" size={16} />
                                    <span>Lock Year</span>
                                </button>
                            )}
                        </>
                    )
                }
            >
                {selectedFiscalYear && (
                    <div className="space-y-6">
                        <DrawerSection title="Period Details">
                            <DrawerField
                                label="Fiscal Year Name"
                                value={selectedFiscalYear.name}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Start Date"
                                value={formatDate(selectedFiscalYear.start_date)}
                                icon="calendar-check"
                            />
                            <DrawerField
                                label="End Date"
                                value={formatDate(selectedFiscalYear.end_date)}
                                icon="calendar-x"
                            />
                            <DrawerField
                                label="Number of Periods"
                                value={selectedFiscalYear.period_count}
                                icon="hash"
                            />
                        </DrawerSection>

                        <DrawerSection title="Status">
                            <DrawerField
                                label="Current Status"
                                value={
                                    <StatusBadge
                                        label={
                                            selectedFiscalYear.status.charAt(0).toUpperCase() +
                                            selectedFiscalYear.status.slice(1)
                                        }
                                        variant={statusVariants[selectedFiscalYear.status]}
                                        icon={
                                            selectedFiscalYear.status === 'open'
                                                ? 'lock-open'
                                                : selectedFiscalYear.status === 'closed'
                                                  ? 'lock'
                                                  : 'lock-key'
                                        }
                                    />
                                }
                                icon="shield-check"
                            />
                            <DrawerField
                                label="Is Current Year"
                                value={selectedFiscalYear.is_current ? 'Yes' : 'No'}
                                icon="star"
                            />
                        </DrawerSection>

                        {selectedFiscalYear.description && (
                            <DrawerSection title="Description">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedFiscalYear.description}
                                </p>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Information">
                            <div
                                className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] p-4"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            >
                                <div className="flex gap-2">
                                    <Icon name="info" size={18} className="flex-none text-[var(--color-brand)]" />
                                    <div className="text-xs text-[var(--color-text-body)]">
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            About Fiscal Year Status
                                        </p>
                                        <ul className="mt-2 space-y-1">
                                            <li>
                                                <strong>Open:</strong> Transactions can be posted to this period
                                            </li>
                                            <li>
                                                <strong>Closed:</strong> No new transactions, but can be reopened
                                            </li>
                                            <li>
                                                <strong>Locked:</strong> Permanently sealed for audit compliance
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </DrawerSection>
                    </div>
                )}
            </DetailDrawer>

            {/* Create Fiscal Year Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Add Fiscal Year"
                onSubmit={handleCreateFiscalYear}
                submitLabel="Create Fiscal Year"
                size="md"
            >
                <div className="space-y-4">
                    {/* Name */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Fiscal Year Name <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            placeholder="e.g., FY 2024, 2024-2025"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* Date Range */}
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Start Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={form.start_date}
                                onChange={(e) => setForm({ ...form, start_date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                End Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={form.end_date}
                                onChange={(e) => setForm({ ...form, end_date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>

                    {/* Description */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Description <span className="text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
                        </label>
                        <textarea
                            value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            rows={3}
                            placeholder="Additional details about this fiscal year..."
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* Info Box */}
                    <div
                        className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] p-3"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    >
                        <div className="flex gap-2">
                            <Icon name="info" size={16} className="flex-none text-[var(--color-brand)] mt-0.5" />
                            <p className="text-xs text-[var(--color-text-body)]">
                                The fiscal year will be created with <strong>Open</strong> status. Typical fiscal years run for 12 months (e.g., Jan 1 - Dec 31 or Apr 1 - Mar 31).
                            </p>
                        </div>
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
