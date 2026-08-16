import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    BulkActions,
    BulkActionButton,
    SelectCheckbox,
    QuickCreateModal,
    QuickActionButton,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Transaction = {
    id: string;
    reference: string;
    date: string;
    description: string;
    type: 'income' | 'expense' | 'transfer' | 'adjustment';
    amount: number;
    debit_account: {
        id: string;
        code: string;
        name: string;
    };
    credit_account: {
        id: string;
        code: string;
        name: string;
    };
    status: 'posted' | 'void';
    source: 'manual' | 'order' | 'invoice' | 'system';
    recorded_by?: {
        id: string;
        name: string;
    };
    attachments: {
        id: string;
        name: string;
        url: string;
        type: string;
    }[];
    notes?: string;
    created_at: string;
};

type TransactionsResponse = {
    data: Transaction[];
    summary: {
        income: number;
        expense: number;
        net: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Transactions() {
    useDocumentTitle('Transactions');

    // State
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('posted');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [selectedTransactions, setSelectedTransactions] = useState<string[]>([]);
    const [selectedTransaction, setSelectedTransaction] = useState<Transaction | null>(null);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Form state for create modal
    const [form, setForm] = useState({
        type: 'income' as 'income' | 'expense' | 'transfer' | 'adjustment',
        date: new Date().toISOString().split('T')[0],
        amount: '',
        description: '',
        debit_account_id: '',
        credit_account_id: '',
        notes: '',
    });

    // Fetch transactions
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['transactions', { search, typeFilter, statusFilter, dateFrom, dateTo, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<TransactionsResponse>('/transactions', {
                params: {
                    search,
                    type: typeFilter || undefined,
                    status: statusFilter || undefined,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const transactions = data?.data ?? [];
    const summary = data?.summary;

    // Selection handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedTransactions(transactions.map((t) => t.id));
        } else {
            setSelectedTransactions([]);
        }
    };

    const handleSelectTransaction = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedTransactions([...selectedTransactions, id]);
        } else {
            setSelectedTransactions(selectedTransactions.filter((tid) => tid !== id));
        }
    };

    const isAllSelected = transactions.length > 0 && selectedTransactions.length === transactions.length;
    const isSomeSelected = selectedTransactions.length > 0 && selectedTransactions.length < transactions.length;

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
        setTypeFilter('');
        setStatusFilter('posted');
        setDateFrom('');
        setDateTo('');
    };

    const hasFilters = search || typeFilter || statusFilter !== 'posted' || dateFrom || dateTo;

    // Bulk actions
    const handleBulkExport = () => {
        console.log('Exporting transactions:', selectedTransactions);
        // TODO: Implement export
    };

    const handleBulkVoid = () => {
        if (confirm(`Void ${selectedTransactions.length} transactions? They will be marked as voided but kept for audit.`)) {
            console.log('Voiding transactions:', selectedTransactions);
            // TODO: Implement void
            setSelectedTransactions([]);
        }
    };

    // Create transaction
    const handleCreateTransaction = () => {
        console.log('Creating transaction:', form);
        // TODO: Implement create
        setShowCreateModal(false);
    };

    // Void single transaction
    const handleVoidTransaction = (id: string, reference: string) => {
        if (confirm(`Void transaction ${reference}?`)) {
            console.log('Voiding transaction:', id);
            // TODO: Implement void
        }
    };

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

    // Transaction type labels
    const typeLabels = {
        income: 'Income',
        expense: 'Expense',
        transfer: 'Transfer',
        adjustment: 'Adjustment',
    };

    // Transaction type variants
    const typeVariants: Record<string, 'success' | 'danger' | 'info' | 'neutral'> = {
        income: 'success',
        expense: 'danger',
        transfer: 'info',
        adjustment: 'neutral',
    };

    // Transaction type icons
    const typeIcons = {
        income: 'arrow-down-left',
        expense: 'arrow-up-right',
        transfer: 'arrows-left-right',
        adjustment: 'scales',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Transactions"
                    description="Every movement of money — income, expenses, transfers and adjustments"
                    icon="receipt"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export all')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="New Transaction"
                                onClick={() => setShowCreateModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-3">
                    <KPICard
                        label="Total Income"
                        value={formatMoney(summary.income)}
                        icon="arrow-down-left"
                        variant="success"
                    />
                    <KPICard
                        label="Total Expense"
                        value={formatMoney(summary.expense)}
                        icon="arrow-up-right"
                        variant="danger"
                    />
                    <KPICard
                        label="Net"
                        value={formatMoney(summary.net)}
                        icon="equals"
                        variant={summary.net >= 0 ? 'success' : 'danger'}
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by description, reference, or account..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'income', label: 'Income' },
                                    { value: 'expense', label: 'Expense' },
                                    { value: 'transfer', label: 'Transfer' },
                                    { value: 'adjustment', label: 'Adjustment' },
                                ]}
                                placeholder="All types"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'posted', label: 'Posted' },
                                    { value: 'void', label: 'Voided' },
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
                            Failed to load transactions.
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
                            data={transactions}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'select',
                                    label: '',
                                    width: 'w-12',
                                    render: (transaction) => (
                                        <SelectCheckbox
                                            checked={selectedTransactions.includes(transaction.id)}
                                            onChange={(checked) =>
                                                handleSelectTransaction(transaction.id, checked)
                                            }
                                        />
                                    ),
                                },
                                {
                                    key: 'reference',
                                    label: 'Reference',
                                    sortable: true,
                                    render: (transaction) => (
                                        <span className="font-mono text-xs text-[var(--color-text-muted)]">
                                            {transaction.reference}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'date',
                                    label: 'Date',
                                    sortable: true,
                                    accessor: (t) => formatDate(t.date),
                                },
                                {
                                    key: 'description',
                                    label: 'Description',
                                    render: (transaction) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {transaction.description}
                                                {transaction.status === 'void' && (
                                                    <span className="ml-2 text-xs font-normal text-[var(--color-danger)]">
                                                        · voided
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                                                {transaction.recorded_by && (
                                                    <span className="flex items-center gap-1">
                                                        <Icon name="user" size={12} />
                                                        {transaction.recorded_by.name}
                                                    </span>
                                                )}
                                                {transaction.attachments.length > 0 && (
                                                    <span className="flex items-center gap-1">
                                                        <Icon name="paperclip" size={12} />
                                                        {transaction.attachments.length} attachment{transaction.attachments.length > 1 ? 's' : ''}
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    render: (t) => (
                                        <StatusBadge
                                            label={typeLabels[t.type]}
                                            variant={typeVariants[t.type]}
                                            icon={typeIcons[t.type]}
                                        />
                                    ),
                                },
                                {
                                    key: 'accounts',
                                    label: 'Accounts',
                                    render: (transaction) => (
                                        <div className="text-xs text-[var(--color-text-muted)]">
                                            <div className="flex items-center gap-1" title="Debit">
                                                <Icon name="arrow-up" size={12} />
                                                {transaction.debit_account.name}
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-1" title="Credit">
                                                <Icon name="arrow-down" size={12} />
                                                {transaction.credit_account.name}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    sortable: true,
                                    align: 'right',
                                    render: (t) => (
                                        <span
                                            className="font-semibold tabular-nums"
                                            style={{
                                                color:
                                                    t.type === 'income'
                                                        ? 'var(--color-success)'
                                                        : t.type === 'expense'
                                                          ? 'var(--color-danger)'
                                                          : 'var(--color-text-main)',
                                            }}
                                        >
                                            {formatMoney(t.amount)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'actions',
                                    label: '',
                                    width: 'w-20',
                                    render: (transaction) =>
                                        transaction.status !== 'void' && (
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleVoidTransaction(transaction.id, transaction.reference);
                                                }}
                                                className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-danger)] transition-colors"
                                            >
                                                Void
                                            </button>
                                        ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(transaction) => setSelectedTransaction(transaction)}
                            clickable
                            getRowKey={(transaction) => transaction.id}
                            emptyState={
                                <EmptyState
                                    icon="receipt"
                                    title={hasFilters ? 'No transactions match' : 'No transactions yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Record your first transaction to start tracking your finances.'
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
                                                Add Transaction
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}

                {/* Select all checkbox */}
                {!isLoading && transactions.length > 0 && (
                    <div className="mt-3 px-6">
                        <SelectCheckbox
                            checked={isAllSelected}
                            indeterminate={isSomeSelected}
                            onChange={handleSelectAll}
                            label={
                                isAllSelected
                                    ? 'Deselect all'
                                    : isSomeSelected
                                      ? `${selectedTransactions.length} selected`
                                      : 'Select all'
                            }
                        />
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedTransactions.length}
                onClearSelection={() => setSelectedTransactions([])}
            >
                <BulkActionButton
                    icon="download-simple"
                    label="Export"
                    onClick={handleBulkExport}
                />
                <BulkActionButton
                    icon="prohibit"
                    label="Void"
                    onClick={handleBulkVoid}
                    variant="danger"
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedTransaction}
                onClose={() => setSelectedTransaction(null)}
                title={selectedTransaction?.description ?? ''}
                subtitle={`${selectedTransaction?.reference} · ${selectedTransaction ? formatDate(selectedTransaction.date) : ''}`}
                size="lg"
                actions={
                    selectedTransaction?.status !== 'void' && (
                        <button
                            className="btn btn-danger"
                            onClick={() =>
                                selectedTransaction &&
                                handleVoidTransaction(selectedTransaction.id, selectedTransaction.reference)
                            }
                        >
                            <Icon name="prohibit" size={16} />
                            <span>Void</span>
                        </button>
                    )
                }
            >
                {selectedTransaction && (
                    <div className="space-y-6">
                        <DrawerSection title="Transaction Details">
                            <DrawerField
                                label="Reference"
                                value={selectedTransaction.reference}
                                icon="hash"
                            />
                            <DrawerField
                                label="Date"
                                value={formatDate(selectedTransaction.date)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Type"
                                value={<StatusBadge label={typeLabels[selectedTransaction.type]} variant={typeVariants[selectedTransaction.type]} />}
                                icon="tag"
                            />
                            <DrawerField
                                label="Amount"
                                value={formatMoney(selectedTransaction.amount)}
                                icon="currency-dollar"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={selectedTransaction.status === 'posted' ? 'Posted' : 'Voided'}
                                        variant={selectedTransaction.status === 'posted' ? 'success' : 'neutral'}
                                    />
                                }
                                icon="check-circle"
                            />
                        </DrawerSection>

                        <DrawerSection title="Accounting">
                            <DrawerField
                                label="Debit Account"
                                value={`[${selectedTransaction.debit_account.code}] ${selectedTransaction.debit_account.name}`}
                                icon="arrow-up"
                            />
                            <DrawerField
                                label="Credit Account"
                                value={`[${selectedTransaction.credit_account.code}] ${selectedTransaction.credit_account.name}`}
                                icon="arrow-down"
                            />
                        </DrawerSection>

                        {selectedTransaction.notes && (
                            <DrawerSection title="Notes">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedTransaction.notes}
                                </p>
                            </DrawerSection>
                        )}

                        {selectedTransaction.attachments.length > 0 && (
                            <DrawerSection title="Attachments">
                                <div className="space-y-2">
                                    {selectedTransaction.attachments.map((file) => (
                                        <a
                                            key={file.id}
                                            href={file.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-2 text-sm text-[var(--color-brand)] hover:underline"
                                        >
                                            <Icon
                                                name={file.type.startsWith('image') ? 'image' : 'file-pdf'}
                                                size={16}
                                            />
                                            {file.name}
                                        </a>
                                    ))}
                                </div>
                            </DrawerSection>
                        )}

                        {selectedTransaction.recorded_by && (
                            <DrawerSection title="Recorded By">
                                <DrawerField
                                    label="User"
                                    value={selectedTransaction.recorded_by.name}
                                    icon="user"
                                />
                                <DrawerField
                                    label="Date & Time"
                                    value={new Date(selectedTransaction.created_at).toLocaleString()}
                                    icon="clock"
                                />
                            </DrawerSection>
                        )}
                    </div>
                )}
            </DetailDrawer>

            {/* Create Transaction Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="New Transaction"
                onSubmit={handleCreateTransaction}
                submitLabel="Post Transaction"
                size="lg"
            >
                <div className="space-y-4">
                    {/* Type Selector */}
                    <div>
                        <label className="mb-2 block text-sm font-medium text-[var(--color-text-main)]">
                            Transaction Type <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {(['income', 'expense', 'transfer', 'adjustment'] as const).map((type) => (
                                <button
                                    key={type}
                                    type="button"
                                    onClick={() => setForm({ ...form, type })}
                                    className={`flex flex-col items-center gap-2 border px-3 py-3 text-sm font-medium transition-colors ${
                                        form.type === type
                                            ? 'border-[var(--color-brand)] bg-[var(--color-brand)] text-white'
                                            : 'border-[var(--color-border-light)] bg-white text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]'
                                    }`}
                                    style={{ borderRadius: 'var(--shell-radius)' }}
                                >
                                    <Icon name={typeIcons[type]} size={20} weight="bold" />
                                    {typeLabels[type]}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Date and Amount */}
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Date <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="date"
                                value={form.date}
                                onChange={(e) => setForm({ ...form, date: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Amount <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={form.amount}
                                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                placeholder="0.00"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>

                    {/* Description */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Description <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            placeholder="What was this transaction for?"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* Accounts */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Debit Account <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <select
                                value={form.debit_account_id}
                                onChange={(e) => setForm({ ...form, debit_account_id: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            >
                                <option value="">Select account</option>
                                {/* TODO: Load accounts from API */}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Credit Account <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <select
                                value={form.credit_account_id}
                                onChange={(e) => setForm({ ...form, credit_account_id: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            >
                                <option value="">Select account</option>
                                {/* TODO: Load accounts from API */}
                            </select>
                        </div>
                    </div>

                    {/* Notes */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Notes <span className="text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
                        </label>
                        <textarea
                            value={form.notes}
                            onChange={(e) => setForm({ ...form, notes: e.target.value })}
                            rows={3}
                            placeholder="Additional details about this transaction..."
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* File Upload Placeholder */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Attachments <span className="text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
                        </label>
                        <div
                            className="flex items-center justify-center border-2 border-dashed border-[var(--color-border-light)] bg-[var(--shell-hover)] px-6 py-8 text-center transition-colors hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-subtle)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <div>
                                <Icon name="upload-simple" size={24} className="mx-auto text-[var(--color-text-muted)]" />
                                <p className="mt-2 text-sm text-[var(--color-text-body)]">
                                    Click to upload or drag and drop
                                </p>
                                <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                    Receipts, invoices, or other proof (PDF, JPG, PNG up to 10MB)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
