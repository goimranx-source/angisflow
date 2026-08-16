import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

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

type Account = {
    id: string;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
    subtype: string | null;
    description: string | null;
    normal_balance: 'debit' | 'credit';
    is_postable: boolean;
    is_system: boolean;
    is_active: boolean;
    parent_code: string | null;
    balance?: number;
};

type AccountsResponse = {
    data: Account[];
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;
const TYPE_LABELS: Record<Account['type'], string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const TYPE_ICONS: Record<Account['type'], string> = {
    asset: 'bank',
    liability: 'currency-circle-dollar',
    equity: 'scales',
    revenue: 'arrow-down-left',
    expense: 'arrow-up-right',
};

export default function Accounts() {
    const { tenant } = useSession();
    useDocumentTitle('Chart of Accounts');

    // State
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('active');
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(null);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('code');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('asc');

    // Form state
    const [form, setForm] = useState({
        code: '',
        name: '',
        type: 'asset' as 'asset' | 'liability' | 'equity' | 'revenue' | 'expense',
        subtype: '',
        description: '',
        normal_balance: 'debit' as 'debit' | 'credit',
        parent_code: '',
    });

    // Fetch accounts
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['accounts'],
        queryFn: ({ signal }) => api.get<AccountsResponse>('/accounts?all=1', { signal }),
        enabled: tenant?.business !== null,
    });

    const accounts = data?.data ?? [];

    // Filter accounts
    const filtered = accounts.filter((account) => {
        const matchesSearch =
            !search ||
            account.code.toLowerCase().includes(search.toLowerCase()) ||
            account.name.toLowerCase().includes(search.toLowerCase());
        const matchesType = !typeFilter || account.type === typeFilter;
        const matchesStatus =
            statusFilter === '' ||
            (statusFilter === 'active' && account.is_active) ||
            (statusFilter === 'inactive' && !account.is_active);

        return matchesSearch && matchesType && matchesStatus;
    });

    // Sort accounts
    const sorted = [...filtered].sort((a, b) => {
        if (!sortBy || !sortDirection) return 0;

        let aVal: string | number = '';
        let bVal: string | number = '';

        switch (sortBy) {
            case 'code':
                aVal = a.code;
                bVal = b.code;
                break;
            case 'name':
                aVal = a.name;
                bVal = b.name;
                break;
            case 'type':
                aVal = a.type;
                bVal = b.type;
                break;
            default:
                return 0;
        }

        if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
        if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
        return 0;
    });

    // Group by type for display
    const byType = TYPE_ORDER.reduce<Record<Account['type'], Account[]>>((acc, type) => {
        acc[type] = sorted.filter((a) => a.type === type);
        return acc;
    }, {} as Record<Account['type'], Account[]>);

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
        setStatusFilter('active');
    };

    const hasFilters = search || typeFilter || statusFilter !== 'active';

    // Create account
    const handleCreateAccount = () => {
        console.log('Creating account:', form);
        // TODO: Implement create
        setShowCreateModal(false);
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Chart of Accounts"
                    description="Organize how money is classified and tracked in your books"
                    icon="tree-structure"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="Add Account"
                                onClick={() => setShowCreateModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by account code or name..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'asset', label: 'Assets' },
                                    { value: 'liability', label: 'Liabilities' },
                                    { value: 'equity', label: 'Equity' },
                                    { value: 'revenue', label: 'Revenue' },
                                    { value: 'expense', label: 'Expenses' },
                                ]}
                                placeholder="All types"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
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
                            Failed to load accounts.
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : isLoading ? (
                    <div className="mt-6 space-y-4">
                        {Array.from({ length: 5 }, (_, i) => (
                            <div
                                key={i}
                                className="card h-40 animate-pulse"
                                style={{ background: 'var(--shell-hover)' }}
                            />
                        ))}
                    </div>
                ) : sorted.length === 0 ? (
                    <div className="card mt-6">
                        <EmptyState
                            icon="tree-structure"
                            title={hasFilters ? 'No accounts match' : 'No accounts yet'}
                            body={
                                hasFilters
                                    ? 'Try adjusting your filters to see more accounts.'
                                    : 'Set up your chart of accounts to start tracking finances.'
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
                                        Add Account
                                    </button>
                                )
                            }
                        />
                    </div>
                ) : (
                    <div className="mt-6 space-y-6">
                        {TYPE_ORDER.map((type) => {
                            const rows = byType[type];
                            if (rows.length === 0) return null;

                            return (
                                <div key={type} className="card overflow-hidden">
                                    {/* Section Header */}
                                    <div className="flex items-center gap-3 border-b border-[var(--color-border-light)] bg-[var(--shell-hover)] px-4 py-3">
                                        <Icon
                                            name={TYPE_ICONS[type]}
                                            size={18}
                                            className="text-[var(--color-text-muted)]"
                                        />
                                        <h3 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-main)]">
                                            {TYPE_LABELS[type]}
                                        </h3>
                                        <span className="ml-auto text-xs text-[var(--color-text-muted)]">
                                            {rows.length} account{rows.length !== 1 ? 's' : ''}
                                        </span>
                                    </div>

                                    {/* Accounts Table */}
                                    <Table
                                        data={rows}
                                        columns={[
                                            {
                                                key: 'code',
                                                label: 'Code',
                                                width: 'w-28',
                                                sortable: true,
                                                render: (account) => (
                                                    <span className="font-mono text-xs text-[var(--color-text-muted)]">
                                                        {account.code}
                                                    </span>
                                                ),
                                            },
                                            {
                                                key: 'name',
                                                label: 'Account Name',
                                                sortable: true,
                                                render: (account) => (
                                                    <div>
                                                        <p
                                                            className={`text-[var(--color-text-main)] ${
                                                                !account.is_postable ? 'font-semibold' : ''
                                                            }`}
                                                        >
                                                            {account.name}
                                                        </p>
                                                        {account.subtype && (
                                                            <p className="mt-0.5 text-xs capitalize text-[var(--color-text-muted)]">
                                                                {account.subtype.replace(/_/g, ' ')}
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'normal_balance',
                                                label: 'Balance',
                                                width: 'w-24',
                                                align: 'center',
                                                render: (account) => (
                                                    <StatusBadge
                                                        label={account.normal_balance === 'debit' ? 'Debit' : 'Credit'}
                                                        variant={account.normal_balance === 'debit' ? 'info' : 'success'}
                                                        size="sm"
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'postable',
                                                label: 'Type',
                                                width: 'w-32',
                                                render: (account) => (
                                                    <span className="text-xs text-[var(--color-text-muted)]">
                                                        {account.is_postable ? 'Detail' : 'Header'}
                                                    </span>
                                                ),
                                            },
                                            {
                                                key: 'status',
                                                label: 'Status',
                                                width: 'w-24',
                                                render: (account) =>
                                                    account.is_active ? (
                                                        <StatusBadge label="Active" variant="success" dot size="sm" />
                                                    ) : (
                                                        <StatusBadge label="Inactive" variant="neutral" dot size="sm" />
                                                    ),
                                            },
                                        ]}
                                        sortBy={sortBy}
                                        sortDirection={sortDirection}
                                        onSort={handleSort}
                                        onRowClick={(account) => setSelectedAccount(account)}
                                        clickable
                                        getRowKey={(account) => account.id}
                                        compact
                                    />
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedAccount}
                onClose={() => setSelectedAccount(null)}
                title={selectedAccount?.name ?? ''}
                subtitle={`Code: ${selectedAccount?.code}`}
                size="md"
            >
                {selectedAccount && (
                    <div className="space-y-6">
                        <DrawerSection title="Account Details">
                            <DrawerField
                                label="Account Code"
                                value={selectedAccount.code}
                                icon="hash"
                            />
                            <DrawerField
                                label="Account Type"
                                value={TYPE_LABELS[selectedAccount.type]}
                                icon="tag"
                            />
                            {selectedAccount.subtype && (
                                <DrawerField
                                    label="Subtype"
                                    value={selectedAccount.subtype.replace(/_/g, ' ')}
                                    icon="bookmark"
                                />
                            )}
                            <DrawerField
                                label="Normal Balance"
                                value={
                                    <StatusBadge
                                        label={selectedAccount.normal_balance === 'debit' ? 'Debit' : 'Credit'}
                                        variant={selectedAccount.normal_balance === 'debit' ? 'info' : 'success'}
                                    />
                                }
                                icon="scales"
                            />
                        </DrawerSection>

                        <DrawerSection title="Classification">
                            <DrawerField
                                label="Account Level"
                                value={selectedAccount.is_postable ? 'Detail Account' : 'Header Account'}
                                icon="tree-structure"
                            />
                            {selectedAccount.parent_code && (
                                <DrawerField
                                    label="Parent Account"
                                    value={selectedAccount.parent_code}
                                    icon="arrow-up"
                                />
                            )}
                            <DrawerField
                                label="System Account"
                                value={selectedAccount.is_system ? 'Yes' : 'No'}
                                icon="shield-check"
                            />
                        </DrawerSection>

                        {selectedAccount.description && (
                            <DrawerSection title="Description">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedAccount.description}
                                </p>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Status">
                            <DrawerField
                                label="Active Status"
                                value={
                                    <StatusBadge
                                        label={selectedAccount.is_active ? 'Active' : 'Inactive'}
                                        variant={selectedAccount.is_active ? 'success' : 'neutral'}
                                        dot
                                    />
                                }
                                icon="check-circle"
                            />
                        </DrawerSection>
                    </div>
                )}
            </DetailDrawer>

            {/* Create Account Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Add Account"
                onSubmit={handleCreateAccount}
                submitLabel="Create Account"
                size="md"
            >
                <div className="space-y-4">
                    {/* Account Code */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Account Code <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.code}
                            onChange={(e) => setForm({ ...form, code: e.target.value })}
                            placeholder="e.g., 1010, 5100"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm font-mono focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* Account Name */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Account Name <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            placeholder="e.g., Cash in Bank, Office Expenses"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    {/* Account Type */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Account Type <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <select
                            value={form.type}
                            onChange={(e) => setForm({ ...form, type: e.target.value as any })}
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>

                    {/* Normal Balance */}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Normal Balance <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <select
                            value={form.normal_balance}
                            onChange={(e) => setForm({ ...form, normal_balance: e.target.value as any })}
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <option value="debit">Debit</option>
                            <option value="credit">Credit</option>
                        </select>
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
                            placeholder="What this account is used for..."
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
