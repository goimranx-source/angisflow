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
    BulkActions,
    BulkActionButton,
    SelectCheckbox,
    QuickCreateModal,
    QuickActionButton,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Customer = {
    id: string;
    name: string;
    email: string;
    phone: string;
    company?: string;
    total_spent: number;
    orders_count: number;
    status: 'active' | 'inactive';
    tags: string[];
    created_at: string;
    last_order_at?: string;
};

type CustomersResponse = {
    data: Customer[];
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Customers() {
    useDocumentTitle('Customers');

    // State
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [selectedCustomers, setSelectedCustomers] = useState<string[]>([]);
    const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch customers
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['customers', { search, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<CustomersResponse>('/customers', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const customers = data?.data ?? [];

    // Selection handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedCustomers(customers.map((c) => c.id));
        } else {
            setSelectedCustomers([]);
        }
    };

    const handleSelectCustomer = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedCustomers([...selectedCustomers, id]);
        } else {
            setSelectedCustomers(selectedCustomers.filter((cid) => cid !== id));
        }
    };

    const isAllSelected = customers.length > 0 && selectedCustomers.length === customers.length;
    const isSomeSelected = selectedCustomers.length > 0 && selectedCustomers.length < customers.length;

    // Sort handler
    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    // Bulk actions
    const handleBulkExport = () => {
        console.log('Exporting customers:', selectedCustomers);
        // TODO: Implement export
    };

    const handleBulkDelete = () => {
        if (confirm(`Delete ${selectedCustomers.length} customers?`)) {
            console.log('Deleting customers:', selectedCustomers);
            // TODO: Implement delete
            setSelectedCustomers([]);
        }
    };

    // Create customer
    const handleCreateCustomer = () => {
        console.log('Creating customer');
        // TODO: Implement create
        setShowCreateModal(false);
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

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Customers"
                    description="Manage your customer database and relationships"
                    icon="address-book"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Import')}
                            >
                                <Icon name="upload" size={16} />
                                <span>Import</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="Add Customer"
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
                    searchPlaceholder="Search customers by name, email, or phone..."
                    filters={
                        <>
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
                        </>
                    }
                    viewControls={
                        <>
                            <ViewToggleButton
                                icon="list"
                                label="List"
                                active={view === 'list'}
                                onClick={() => setView('list')}
                            />
                            <ViewToggleButton
                                icon="squares-four"
                                label="Grid"
                                active={view === 'grid'}
                                onClick={() => setView('grid')}
                            />
                        </>
                    }
                    actions={
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => console.log('Export all')}
                        >
                            <Icon name="download-simple" size={16} />
                            <span>Export</span>
                        </button>
                    }
                />
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load customers.
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
                        {view === 'list' ? (
                            <Table
                                data={customers}
                                loading={isLoading}
                                skeletonRows={10}
                                columns={[
                                    {
                                        key: 'select',
                                        label: '',
                                        width: 'w-12',
                                        render: (customer) => (
                                            <SelectCheckbox
                                                checked={selectedCustomers.includes(customer.id)}
                                                onChange={(checked) =>
                                                    handleSelectCustomer(customer.id, checked)
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        key: 'name',
                                        label: 'Customer',
                                        sortable: true,
                                        render: (customer) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {customer.name}
                                                </p>
                                                <p className="text-sm text-[var(--color-text-muted)]">
                                                    {customer.email}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'company',
                                        label: 'Company',
                                        accessor: (c) => c.company || '—',
                                    },
                                    {
                                        key: 'phone',
                                        label: 'Phone',
                                        accessor: (c) => c.phone || '—',
                                    },
                                    {
                                        key: 'total_spent',
                                        label: 'Total Spent',
                                        sortable: true,
                                        align: 'right',
                                        accessor: (c) => formatMoney(c.total_spent),
                                    },
                                    {
                                        key: 'orders_count',
                                        label: 'Orders',
                                        sortable: true,
                                        align: 'center',
                                        accessor: (c) => c.orders_count,
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        render: (c) => (
                                            <StatusBadge
                                                label={c.status === 'active' ? 'Active' : 'Inactive'}
                                                variant={c.status === 'active' ? 'success' : 'neutral'}
                                                dot
                                            />
                                        ),
                                    },
                                    {
                                        key: 'last_order_at',
                                        label: 'Last Order',
                                        sortable: true,
                                        accessor: (c) => c.last_order_at ? formatDate(c.last_order_at) : 'Never',
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
                                onRowClick={(customer) => setSelectedCustomer(customer)}
                                clickable
                                getRowKey={(customer) => customer.id}
                                emptyState={
                                    <EmptyState
                                        icon="address-book"
                                        title="No customers yet"
                                        body="Add your first customer to start building your customer base."
                                        action={
                                            <button
                                                className="btn btn-primary"
                                                onClick={() => setShowCreateModal(true)}
                                            >
                                                Add Customer
                                            </button>
                                        }
                                    />
                                }
                            />
                        ) : (
                            <div className="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
                                {/* TODO: Grid view */}
                                <p className="col-span-full text-center text-sm text-[var(--color-text-muted)]">
                                    Grid view coming soon
                                </p>
                            </div>
                        )}
                    </div>
                )}

                {/* Select all header (when using checkboxes) */}
                {!isLoading && customers.length > 0 && view === 'list' && (
                    <div className="mt-3 px-6">
                        <SelectCheckbox
                            checked={isAllSelected}
                            indeterminate={isSomeSelected}
                            onChange={handleSelectAll}
                            label={
                                isAllSelected
                                    ? 'Deselect all'
                                    : isSomeSelected
                                      ? `${selectedCustomers.length} selected`
                                      : 'Select all'
                            }
                        />
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedCustomers.length}
                onClearSelection={() => setSelectedCustomers([])}
            >
                <BulkActionButton
                    icon="download-simple"
                    label="Export"
                    onClick={handleBulkExport}
                />
                <BulkActionButton
                    icon="trash"
                    label="Delete"
                    onClick={handleBulkDelete}
                    variant="danger"
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedCustomer}
                onClose={() => setSelectedCustomer(null)}
                title={selectedCustomer?.name ?? ''}
                subtitle={selectedCustomer?.email}
                tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: (
                            <div className="space-y-6">
                                <DrawerSection title="Contact Information">
                                    <DrawerField
                                        label="Email"
                                        value={selectedCustomer?.email}
                                        icon="envelope"
                                    />
                                    <DrawerField
                                        label="Phone"
                                        value={selectedCustomer?.phone}
                                        icon="phone"
                                    />
                                    <DrawerField
                                        label="Company"
                                        value={selectedCustomer?.company}
                                        icon="buildings"
                                    />
                                </DrawerSection>
                                <DrawerSection title="Statistics">
                                    <DrawerField
                                        label="Total Spent"
                                        value={selectedCustomer ? formatMoney(selectedCustomer.total_spent) : ''}
                                        icon="currency-dollar"
                                    />
                                    <DrawerField
                                        label="Total Orders"
                                        value={selectedCustomer?.orders_count}
                                        icon="shopping-cart"
                                    />
                                    <DrawerField
                                        label="Customer Since"
                                        value={selectedCustomer ? formatDate(selectedCustomer.created_at) : ''}
                                        icon="calendar"
                                    />
                                </DrawerSection>
                            </div>
                        ),
                    },
                    {
                        key: 'orders',
                        label: 'Orders',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Orders list coming soon</p>,
                    },
                    {
                        key: 'activity',
                        label: 'Activity',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Activity timeline coming soon</p>,
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
                actions={
                    <>
                        <button className="btn btn-secondary">
                            <Icon name="pencil-simple" size={16} />
                            <span>Edit</span>
                        </button>
                    </>
                }
            >
                {/* Children prop is required even when using tabs */}
                <div />
            </DetailDrawer>

            {/* Create Customer Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Add Customer"
                onSubmit={handleCreateCustomer}
                submitLabel="Create Customer"
            >
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Name <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="John Doe"
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Email <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="email"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="john@example.com"
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Phone
                        </label>
                        <input
                            type="tel"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="+1 (555) 123-4567"
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Company
                        </label>
                        <input
                            type="text"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="Acme Inc."
                        />
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
