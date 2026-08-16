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
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Order = {
    id: string;
    order_number: string;
    customer: {
        id: string;
        name: string;
        email: string;
    };
    date: string;
    status: 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';
    payment_status: 'pending' | 'paid' | 'partially_paid' | 'failed' | 'refunded';
    items_count: number;
    subtotal: number;
    tax: number;
    shipping: number;
    discount: number;
    total: number;
    channel: 'online' | 'pos' | 'phone' | 'api';
    shipping_address?: {
        line1: string;
        line2?: string;
        city: string;
        state: string;
        postal_code: string;
        country: string;
    };
    notes?: string;
    created_at: string;
};

type OrdersResponse = {
    data: Order[];
    summary: {
        total_orders: number;
        total_revenue: number;
        avg_order_value: number;
        pending_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Orders() {
    useDocumentTitle('Orders');

    // State
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [paymentFilter, setPaymentFilter] = useState('');
    const [channelFilter, setChannelFilter] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [selectedOrders, setSelectedOrders] = useState<string[]>([]);
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch orders
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['orders', { search, statusFilter, paymentFilter, channelFilter, dateFrom, dateTo, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<OrdersResponse>('/orders', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    payment_status: paymentFilter || undefined,
                    channel: channelFilter || undefined,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const orders = data?.data ?? [];
    const summary = data?.summary;

    // Selection handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedOrders(orders.map((o) => o.id));
        } else {
            setSelectedOrders([]);
        }
    };

    const handleSelectOrder = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedOrders([...selectedOrders, id]);
        } else {
            setSelectedOrders(selectedOrders.filter((oid) => oid !== id));
        }
    };

    const isAllSelected = orders.length > 0 && selectedOrders.length === orders.length;
    const isSomeSelected = selectedOrders.length > 0 && selectedOrders.length < orders.length;

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
        setPaymentFilter('');
        setChannelFilter('');
        setDateFrom('');
        setDateTo('');
    };

    const hasFilters = search || statusFilter || paymentFilter || channelFilter || dateFrom || dateTo;

    // Bulk actions
    const handleBulkExport = () => {
        console.log('Exporting orders:', selectedOrders);
        // TODO: Implement export
    };

    const handleBulkPrint = () => {
        console.log('Printing orders:', selectedOrders);
        // TODO: Implement print
    };

    const handleBulkStatusUpdate = (newStatus: string) => {
        if (confirm(`Update ${selectedOrders.length} orders to ${newStatus}?`)) {
            console.log('Updating orders:', selectedOrders, 'to', newStatus);
            // TODO: Implement status update
            setSelectedOrders([]);
        }
    };

    // Create order
    const handleCreateOrder = () => {
        console.log('Creating order');
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

    // Status labels and variants
    const statusLabels = {
        pending: 'Pending',
        confirmed: 'Confirmed',
        processing: 'Processing',
        shipped: 'Shipped',
        delivered: 'Delivered',
        cancelled: 'Cancelled',
        returned: 'Returned',
    };

    const statusVariants: Record<string, 'warning' | 'info' | 'brand' | 'success' | 'danger' | 'neutral'> = {
        pending: 'warning',
        confirmed: 'info',
        processing: 'info',
        shipped: 'brand',
        delivered: 'success',
        cancelled: 'danger',
        returned: 'neutral',
    };

    const paymentLabels = {
        pending: 'Pending',
        paid: 'Paid',
        partially_paid: 'Partially Paid',
        failed: 'Failed',
        refunded: 'Refunded',
    };

    const paymentVariants: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
        pending: 'warning',
        paid: 'success',
        partially_paid: 'info',
        failed: 'danger',
        refunded: 'neutral',
    };

    const channelLabels = {
        online: 'Online',
        pos: 'POS',
        phone: 'Phone',
        api: 'API',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Orders"
                    description="Manage customer orders and fulfillment"
                    icon="shopping-cart"
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
                                label="New Order"
                                onClick={() => setShowCreateModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Orders"
                        value={summary.total_orders.toLocaleString()}
                        icon="shopping-cart"
                        variant="brand"
                    />
                    <KPICard
                        label="Total Revenue"
                        value={formatMoney(summary.total_revenue)}
                        icon="currency-dollar"
                        variant="success"
                    />
                    <KPICard
                        label="Avg Order Value"
                        value={formatMoney(summary.avg_order_value)}
                        icon="chart-line"
                        variant="info"
                    />
                    <KPICard
                        label="Pending Orders"
                        value={summary.pending_count.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by order #, customer name, or email..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'confirmed', label: 'Confirmed' },
                                    { value: 'processing', label: 'Processing' },
                                    { value: 'shipped', label: 'Shipped' },
                                    { value: 'delivered', label: 'Delivered' },
                                    { value: 'cancelled', label: 'Cancelled' },
                                    { value: 'returned', label: 'Returned' },
                                ]}
                                placeholder="All statuses"
                            />
                            <FilterSelect
                                label="Payment"
                                value={paymentFilter}
                                onChange={setPaymentFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'paid', label: 'Paid' },
                                    { value: 'partially_paid', label: 'Partially Paid' },
                                    { value: 'failed', label: 'Failed' },
                                    { value: 'refunded', label: 'Refunded' },
                                ]}
                                placeholder="All payments"
                            />
                            <FilterSelect
                                label="Channel"
                                value={channelFilter}
                                onChange={setChannelFilter}
                                options={[
                                    { value: 'online', label: 'Online' },
                                    { value: 'pos', label: 'POS' },
                                    { value: 'phone', label: 'Phone' },
                                    { value: 'api', label: 'API' },
                                ]}
                                placeholder="All channels"
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
                />
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load orders.
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
                                data={orders}
                                loading={isLoading}
                                skeletonRows={10}
                                columns={[
                                    {
                                        key: 'select',
                                        label: '',
                                        width: 'w-12',
                                        render: (order) => (
                                            <SelectCheckbox
                                                checked={selectedOrders.includes(order.id)}
                                                onChange={(checked) =>
                                                    handleSelectOrder(order.id, checked)
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        key: 'order_number',
                                        label: 'Order',
                                        sortable: true,
                                        render: (order) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {order.order_number}
                                                </p>
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {formatDate(order.date)}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'customer',
                                        label: 'Customer',
                                        render: (order) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {order.customer.name}
                                                </p>
                                                <p className="text-xs text-[var(--color-text-muted)]">
                                                    {order.customer.email}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        render: (order) => (
                                            <StatusBadge
                                                label={statusLabels[order.status]}
                                                variant={statusVariants[order.status]}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'payment_status',
                                        label: 'Payment',
                                        render: (order) => (
                                            <StatusBadge
                                                label={paymentLabels[order.payment_status]}
                                                variant={paymentVariants[order.payment_status]}
                                                dot
                                            />
                                        ),
                                    },
                                    {
                                        key: 'channel',
                                        label: 'Channel',
                                        accessor: (o) => channelLabels[o.channel],
                                    },
                                    {
                                        key: 'items_count',
                                        label: 'Items',
                                        align: 'center',
                                        accessor: (o) => o.items_count,
                                    },
                                    {
                                        key: 'total',
                                        label: 'Total',
                                        sortable: true,
                                        align: 'right',
                                        render: (o) => (
                                            <span className="font-semibold tabular-nums">
                                                {formatMoney(o.total)}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'actions',
                                        label: '',
                                        width: 'w-24',
                                        render: (order) => (
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    console.log('Print order', order.id);
                                                }}
                                                className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-brand)] transition-colors"
                                            >
                                                Print
                                            </button>
                                        ),
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
                                onRowClick={(order) => setSelectedOrder(order)}
                                clickable
                                getRowKey={(order) => order.id}
                                emptyState={
                                    <EmptyState
                                        icon="shopping-cart"
                                        title={hasFilters ? 'No orders match' : 'No orders yet'}
                                        body={
                                            hasFilters
                                                ? 'Try adjusting your filters to see more results.'
                                                : 'Create your first order to start tracking sales.'
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
                                                    Create Order
                                                </button>
                                            )
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

                {/* Select all checkbox */}
                {!isLoading && orders.length > 0 && view === 'list' && (
                    <div className="mt-3 px-6">
                        <SelectCheckbox
                            checked={isAllSelected}
                            indeterminate={isSomeSelected}
                            onChange={handleSelectAll}
                            label={
                                isAllSelected
                                    ? 'Deselect all'
                                    : isSomeSelected
                                      ? `${selectedOrders.length} selected`
                                      : 'Select all'
                            }
                        />
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedOrders.length}
                onClearSelection={() => setSelectedOrders([])}
            >
                <BulkActionButton
                    icon="printer"
                    label="Print"
                    onClick={handleBulkPrint}
                />
                <BulkActionButton
                    icon="download-simple"
                    label="Export"
                    onClick={handleBulkExport}
                />
                <BulkActionButton
                    icon="check"
                    label="Mark Confirmed"
                    onClick={() => handleBulkStatusUpdate('confirmed')}
                />
                <BulkActionButton
                    icon="truck"
                    label="Mark Shipped"
                    onClick={() => handleBulkStatusUpdate('shipped')}
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedOrder}
                onClose={() => setSelectedOrder(null)}
                title={selectedOrder?.order_number ?? ''}
                subtitle={selectedOrder ? `${selectedOrder.customer.name} · ${formatDate(selectedOrder.date)}` : ''}
                tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: selectedOrder && (
                            <div className="space-y-6">
                                <DrawerSection title="Order Details">
                                    <DrawerField
                                        label="Order Number"
                                        value={selectedOrder.order_number}
                                        icon="hash"
                                    />
                                    <DrawerField
                                        label="Date"
                                        value={formatDate(selectedOrder.date)}
                                        icon="calendar"
                                    />
                                    <DrawerField
                                        label="Status"
                                        value={<StatusBadge label={statusLabels[selectedOrder.status]} variant={statusVariants[selectedOrder.status]} />}
                                        icon="circle-notch"
                                    />
                                    <DrawerField
                                        label="Payment Status"
                                        value={<StatusBadge label={paymentLabels[selectedOrder.payment_status]} variant={paymentVariants[selectedOrder.payment_status]} dot />}
                                        icon="currency-dollar"
                                    />
                                    <DrawerField
                                        label="Channel"
                                        value={channelLabels[selectedOrder.channel]}
                                        icon="storefront"
                                    />
                                </DrawerSection>

                                <DrawerSection title="Customer">
                                    <DrawerField
                                        label="Name"
                                        value={selectedOrder.customer.name}
                                        icon="user"
                                    />
                                    <DrawerField
                                        label="Email"
                                        value={selectedOrder.customer.email}
                                        icon="envelope"
                                    />
                                </DrawerSection>

                                {selectedOrder.shipping_address && (
                                    <DrawerSection title="Shipping Address">
                                        <DrawerField
                                            label="Address"
                                            value={
                                                <div className="text-sm">
                                                    <div>{selectedOrder.shipping_address.line1}</div>
                                                    {selectedOrder.shipping_address.line2 && (
                                                        <div>{selectedOrder.shipping_address.line2}</div>
                                                    )}
                                                    <div>
                                                        {selectedOrder.shipping_address.city}, {selectedOrder.shipping_address.state} {selectedOrder.shipping_address.postal_code}
                                                    </div>
                                                    <div>{selectedOrder.shipping_address.country}</div>
                                                </div>
                                            }
                                            icon="map-pin"
                                        />
                                    </DrawerSection>
                                )}

                                <DrawerSection title="Order Summary">
                                    <DrawerField
                                        label="Items"
                                        value={selectedOrder.items_count}
                                        icon="shopping-bag"
                                    />
                                    <DrawerField
                                        label="Subtotal"
                                        value={formatMoney(selectedOrder.subtotal)}
                                        icon="receipt"
                                    />
                                    {selectedOrder.discount > 0 && (
                                        <DrawerField
                                            label="Discount"
                                            value={formatMoney(-selectedOrder.discount)}
                                            icon="tag"
                                        />
                                    )}
                                    <DrawerField
                                        label="Tax"
                                        value={formatMoney(selectedOrder.tax)}
                                        icon="scales"
                                    />
                                    <DrawerField
                                        label="Shipping"
                                        value={formatMoney(selectedOrder.shipping)}
                                        icon="truck"
                                    />
                                    <DrawerField
                                        label="Total"
                                        value={<span className="font-semibold text-lg">{formatMoney(selectedOrder.total)}</span>}
                                        icon="currency-dollar"
                                    />
                                </DrawerSection>

                                {selectedOrder.notes && (
                                    <DrawerSection title="Notes">
                                        <p className="text-sm text-[var(--color-text-body)]">
                                            {selectedOrder.notes}
                                        </p>
                                    </DrawerSection>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'items',
                        label: 'Items',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Order items coming soon</p>,
                    },
                    {
                        key: 'history',
                        label: 'History',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Order history coming soon</p>,
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
                actions={
                    <>
                        <button className="btn btn-secondary">
                            <Icon name="printer" size={16} />
                            <span>Print</span>
                        </button>
                        <button className="btn btn-secondary">
                            <Icon name="pencil-simple" size={16} />
                            <span>Edit</span>
                        </button>
                    </>
                }
            >
                <div />
            </DetailDrawer>

            {/* Create Order Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Create Order"
                onSubmit={handleCreateOrder}
                submitLabel="Create Order"
                size="lg"
            >
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Customer <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="Search for customer..."
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Order Date <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="date"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            defaultValue={new Date().toISOString().split('T')[0]}
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Channel <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <select
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        >
                            <option value="online">Online</option>
                            <option value="pos">POS</option>
                            <option value="phone">Phone</option>
                            <option value="api">API</option>
                        </select>
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Notes
                        </label>
                        <textarea
                            rows={3}
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            placeholder="Additional order notes..."
                        />
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
