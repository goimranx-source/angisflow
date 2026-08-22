import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

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
    ImportModal,
} from '@/components/modules';
import { DateRangePicker, type DateRange } from '@/components/ui/DateRangePicker';
import { EmptyState } from '@/components/ui/EmptyState';
import { RowAction, RowActionMenu, RowActions } from '@/components/modules/RowActions';
import { BulkActionsMenu, type BulkActionGroup } from '@/components/modules/BulkActionsMenu';
import { Dropdown, DropdownItem } from '@/components/ui/Dropdown';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import {
    downloadCsv,
    orderImportCsv,
    printOrderDocuments,
    type DocumentKind,
} from './orders/documents';

type Order = {
    id: string;
    order_number: string;
    store_code: string | null;
    native: {
        currency: string;
        symbol: string;
        subtotal: number;
        discount: number;
        tax: number;
        shipping: number;
        total: number;
        paid: number;
    };
    items: Array<{
        description: string;
        sku: string | null;
        quantity: number;
        unit_price: number;
        total: number;
    }>;
    customer: {
        id: string;
        name: string;
        email: string | null;
    } | null;
    store: { id: string; name: string } | null;
    is_walk_in: boolean;
    date: string;
    status: string;
    payment_status: 'paid' | 'unpaid';
    fulfilment_status: 'fulfilled' | 'unfulfilled';
    dispatch: {
        shipment_number: string;
        courier: { id: string | null; label: string | null };
        amount: number;
        currency: string;
        tracking_number: string | null;
        status: string;
    } | null;
    is_cod: boolean;
    items_count: number;
    subtotal: number;
    tax: number;
    shipping: number;
    discount: number;
    total: number;
    channel: 'online' | 'pos' | 'phone' | 'api';
    archived_at: string | null;
    trashed_at: string | null;
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
    const queryClient = useQueryClient();

    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [paymentFilter, setPaymentFilter] = useState('');
    const [storeFilter, setStoreFilter] = useState('');
    const [dateRange, setDateRange] = useState<DateRange | null>(null);
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [selectedOrders, setSelectedOrders] = useState<string[]>([]);
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showImportModal, setShowImportModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');
    const [page, setPage] = useState(1);
    const [tab, setTab] = useState<'all' | 'archived' | 'trashed'>('all');
    const [bulkAction, setBulkAction] = useState('');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['orders', { search, statusFilter, paymentFilter, storeFilter, dateRange, sortBy, sortDirection, page, tab }],
        queryFn: ({ signal }) =>
            api.get<OrdersResponse>('/orders', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    payment_status: paymentFilter || undefined,
                    store: storeFilter || undefined,
                    date_from: dateRange?.from || undefined,
                    date_to: dateRange?.to || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                    page,
                    archived: tab === 'archived' ? true : tab === 'trashed' ? null : false,
                    trashed: tab === 'trashed' ? true : false,
                },
                signal,
            }),
    });

    const bulkUpdate = useMutation({
        mutationFn: (payload: { order_ids: string[]; action: string }) =>
            api.post('/orders/bulk-action', payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['orders'] });
            setSelectedOrders([]);
            setBulkAction('');
            toast.success('Orders updated successfully');
        },
    });

    const bulkDispatch = useMutation({
        mutationFn: (payload: { order_ids: string[]; courier_id: string }) =>
            api.post('/orders/bulk-dispatch', payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['orders'] });
            setSelectedOrders([]);
            setBulkAction('');
            toast.success('Orders dispatched successfully');
        },
    });

    const orders = data?.data ?? [];
    const summary = data?.summary;
    const meta = data?.meta;

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
        setPaymentFilter('');
        setStoreFilter('');
        setDateRange(null);
    };

    const handleApplyBulkAction = () => {
        if (!bulkAction) return;

        if (bulkAction.startsWith('status:')) {
            bulkUpdate.mutate({ order_ids: selectedOrders, action: bulkAction });
        } else if (bulkAction.startsWith('payment:')) {
            bulkUpdate.mutate({ order_ids: selectedOrders, action: bulkAction });
        } else if (bulkAction.startsWith('courier:')) {
            bulkDispatch.mutate({ order_ids: selectedOrders, courier_id: bulkAction.split(':')[1] });
        } else {
            bulkUpdate.mutate({ order_ids: selectedOrders, action: bulkAction });
        }
    };

    const hasFilters = search || statusFilter || paymentFilter || storeFilter || dateRange;

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const exportOrders = (ordersToExport: Order[]) => {
        downloadCsv(
            ordersToExport.map((o) => ({
                order_number: o.order_number,
                ordered_on: o.date,
                status: o.status,
                payment_status: o.payment_status,
                customer_name: o.customer?.name ?? 'Walk-in',
                customer_email: o.customer?.email ?? '',
                total: o.native.total,
            })),
            `orders-export-${new Date().toISOString().split('T')[0]}.csv`,
        );
    };

    const printDocuments = (ordersToPrint: Order[], kind: DocumentKind) => {
        printOrderDocuments(ordersToPrint, kind);
    };

    // Fetch available statuses
    const [allStatuses, setAllStatuses] = useState<Array<{ value: string; label: string; custom?: boolean }>>([
        { value: 'pending', label: 'Pending' },
        { value: 'processing', label: 'Processing' },
        { value: 'completed', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
    ]);

    const [availablePaymentStatuses] = useState<Array<{ value: string; label: string }>>([
        { value: 'paid', label: 'Paid' },
        { value: 'unpaid', label: 'Unpaid' },
    ]);

    const [couriers] = useState<Array<{ id: string; label: string }>>([
        { id: 'courier1', label: 'SteadFast Courier' },
        { id: 'courier2', label: 'Local Delivery' },
    ]);

    // Business currency (default to USD, can be set from business settings)
    const businessCurrency = 'USD';
    const businessCurrencySymbol = '$';

    // Calculate KPIs based on current tab
    const calculateKPIs = () => {
        let totalOrders = 0;
        let totalRevenue = 0;
        let avgOrderValue = 0;
        let pendingCount = 0;

        orders.forEach((order) => {
            totalOrders++;
            totalRevenue += order.native.total;

            if (order.status !== 'completed' && order.status !== 'cancelled') {
                pendingCount++;
            }
        });

        avgOrderValue = totalOrders > 0 ? totalRevenue / totalOrders : 0;

        return {
            total_orders: totalOrders,
            total_revenue: totalRevenue,
            avg_order_value: avgOrderValue,
            pending_count: pendingCount,
        };
    };

    const kpis = calculateKPIs();

    return (
        <div className="flex h-full flex-col bg-[var(--color-site-bg)]">
            {/* Header */}
            <div className="border-b border-[var(--color-border-light)] bg-white px-6 pt-6 pb-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-[var(--color-text-main)]">Orders</h1>
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">Manage customer orders and fulfillment</p>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={() => setShowImportModal(true)} className="btn btn-secondary flex items-center gap-2">
                            <Icon name="download" size={16} />
                            Import
                        </button>
                        <button onClick={() => exportOrders(orders)} className="btn btn-secondary flex items-center gap-2">
                            <Icon name="upload" size={16} />
                            Export
                        </button>
                        <button onClick={() => setShowCreateModal(true)} className="btn btn-primary flex items-center gap-2">
                            <Icon name="plus" size={16} />
                            New Order
                        </button>
                    </div>
                </div>
            </div>

            <div className="flex flex-1 flex-col overflow-hidden">
                {/* KPI Cards - Full width */}
                <div className="border-b border-[var(--color-border-light)] bg-white px-6 py-4">
                    <div className="grid grid-cols-4 gap-4">
                        <KPICard
                            label="Total Orders"
                            value={kpis.total_orders}
                            icon="shopping-cart"
                        />
                        <KPICard
                            label="Total Revenue"
                            value={`${businessCurrencySymbol}${(kpis.total_revenue / 100).toFixed(2)}`}
                            icon="currency-dollar"
                        />
                        <KPICard
                            label="Avg Order Value"
                            value={`${businessCurrencySymbol}${(kpis.avg_order_value / 100).toFixed(2)}`}
                            icon="chart-bar"
                        />
                        <KPICard
                            label={tab === 'trashed' ? 'Trashed Orders' : tab === 'archived' ? 'Archived Orders' : 'Processing Orders'}
                            value={tab === 'trashed' ? kpis.total_orders : tab === 'archived' ? kpis.total_orders : kpis.pending_count}
                            icon={tab === 'trashed' ? 'trash' : tab === 'archived' ? 'archive' : 'hourglass'}
                        />
                    </div>
                </div>

                {/* Tabs and Filters */}
                <div className="border-b border-[var(--color-border-light)] bg-white px-6 py-4 space-y-4">
                    {/* Tabs */}

                    {/* Tabs */}
                    <div className="flex gap-4">
                        {['all', 'archived', 'trashed'].map((t) => (
                            <button
                                key={t}
                                onClick={() => { setTab(t as 'all' | 'archived' | 'trashed'); setPage(1); }}
                                className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                                    tab === t
                                        ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                        : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]'
                                }`}
                            >
                                {t === 'all' ? 'All Orders' : t === 'archived' ? 'Archived' : 'Trash'}
                            </button>
                        ))}
                    </div>

                    {/* Search and Filters */}
                    <div className="flex items-end gap-3">
                        <input
                            type="text"
                            placeholder="Search by order #, customer name, or email..."
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                            className="field flex-1"
                        />
                        <select
                            value={statusFilter}
                            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
                            className="field w-40"
                        >
                            <option value="">All statuses</option>
                            {allStatuses.map((s) => (
                                <option key={s.value} value={s.value}>{s.label}</option>
                            ))}
                        </select>
                        <select
                            value={paymentFilter}
                            onChange={(e) => { setPaymentFilter(e.target.value); setPage(1); }}
                            className="field w-40"
                        >
                            <option value="">All payments</option>
                            {availablePaymentStatuses.map((s) => (
                                <option key={s.value} value={s.value}>{s.label}</option>
                            ))}
                        </select>
                        <select
                            value={storeFilter}
                            onChange={(e) => { setStoreFilter(e.target.value); setPage(1); }}
                            className="field w-40"
                        >
                            <option value="">All stores</option>
                            <option value="walk_in">Walk-in / counter</option>
                        </select>
                        <DateRangePicker
                            value={dateRange}
                            onChange={(range) => { setDateRange(range); setPage(1); }}
                        />
                        {hasFilters && (
                            <button
                                onClick={handleClearFilters}
                                className="btn btn-secondary"
                            >
                                Clear
                            </button>
                        )}
                    </div>

                    {/* View Toggle */}
                    <div className="flex justify-end gap-2">
                        <ViewToggleButton
                            currentView={view}
                            onViewChange={setView}
                        />
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-auto pb-6">
                    {isError ? (
                        <div className="card mt-6 p-6 text-center">
                            <p className="text-sm text-[var(--color-text-body)]">Failed to load orders.</p>
                            <button onClick={() => void refetch()} className="btn btn-secondary mt-4">
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
                                            headerRender: () => (
                                                <div onClick={(e) => e.stopPropagation()}>
                                                    <SelectCheckbox
                                                        checked={isAllSelected}
                                                        indeterminate={isSomeSelected}
                                                        onChange={handleSelectAll}
                                                    />
                                                </div>
                                            ),
                                            render: (order) => (
                                                <div onClick={(e) => e.stopPropagation()}>
                                                    <SelectCheckbox
                                                        checked={selectedOrders.includes(order.id)}
                                                        onChange={(checked) => handleSelectOrder(order.id, checked)}
                                                    />
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'order_number',
                                            label: 'Order',
                                            sortable: true,
                                            width: 'w-48',
                                            render: (order) => (
                                                <div className="flex items-center gap-2.5">
                                                    <span className="flex h-8 min-w-8 shrink-0 items-center justify-center rounded-[var(--shell-radius-sm)] bg-[var(--color-brand-subtle)] px-1.5 text-[11px] font-bold tracking-wide text-[var(--color-brand)]" title={order.store?.name ?? 'Walk-in / counter'}>
                                                        {order.store_code ?? '#'}
                                                    </span>
                                                    <div className="min-w-0 whitespace-nowrap">
                                                        <p className="font-semibold text-[var(--color-text-main)]">{order.order_number}</p>
                                                        <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">{formatDate(order.date)}</p>
                                                    </div>
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'customer',
                                            label: 'Customer',
                                            width: 'w-64',
                                            render: (order) => (
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--shell-muted)] text-[11px] font-bold text-[var(--color-text-muted)]">
                                                            {(order.customer?.name ?? 'W').slice(0, 1).toUpperCase()}
                                                        </span>
                                                        <p className="truncate font-medium text-[var(--color-text-main)]">{order.customer?.name ?? 'Walk-in'}</p>
                                                    </div>
                                                    <p className="mt-1 truncate pl-9 text-xs text-[var(--color-text-muted)]">{order.customer?.email ?? 'Counter sale'}</p>
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'status',
                                            label: 'Status',
                                            width: 'w-32',
                                            render: (order) => (
                                                <StatusBadge
                                                    status={order.status}
                                                    label={order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                                                />
                                            ),
                                        },
                                        {
                                            key: 'payment',
                                            label: 'Payment',
                                            width: 'w-32',
                                            render: (order) => (
                                                <div className="flex items-center gap-2">
                                                    <Icon
                                                        name={order.payment_status === 'paid' ? 'check-circle' : 'clock'}
                                                        size={16}
                                                        className={order.payment_status === 'paid' ? 'text-green-500' : 'text-yellow-500'}
                                                    />
                                                    <span className="text-sm capitalize">{order.payment_status}</span>
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'dispatch',
                                            label: 'Dispatch',
                                            width: 'w-40',
                                            render: (order) => (
                                                <div className="text-sm">
                                                    {order.dispatch?.status ? (
                                                        <span className={order.dispatch.status === 'delivered' ? 'text-green-600' : 'text-blue-600'}>
                                                            {order.dispatch.status.charAt(0).toUpperCase() + order.dispatch.status.slice(1)}
                                                        </span>
                                                    ) : (
                                                        <span className="text-[var(--color-text-muted)]">Not dispatched</span>
                                                    )}
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'items',
                                            label: 'Items',
                                            width: 'w-16',
                                            render: (order) => <span className="text-sm">{order.items_count}</span>,
                                        },
                                        {
                                            key: 'total',
                                            label: 'Total',
                                            sortable: true,
                                            width: 'w-32',
                                            render: (order) => (
                                                <div className="text-right">
                                                    <p className="font-semibold text-[var(--color-text-main)]">
                                                        {order.native.symbol}{(order.native.total / 100).toFixed(2)}
                                                    </p>
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'actions',
                                            label: 'Actions',
                                            width: 'w-32',
                                            render: (order) =>
                                                tab === 'trashed' ? (
                                                    <RowActions>
                                                        <RowAction
                                                            icon="note-pencil"
                                                            label="Edit order"
                                                            onClick={() => setSelectedOrder(order)}
                                                        />
                                                        <RowAction
                                                            icon="arrow-counter-clockwise"
                                                            label="Restore from trash"
                                                            onClick={() =>
                                                                bulkUpdate.mutate({
                                                                    order_ids: [order.id],
                                                                    action: 'restore',
                                                                })
                                                            }
                                                        />
                                                        <RowAction
                                                            icon="trash"
                                                            label="Delete permanently"
                                                            variant="danger"
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        `Permanently delete order ${order.order_number}? This cannot be undone.`,
                                                                    )
                                                                ) {
                                                                    bulkUpdate.mutate({
                                                                        order_ids: [order.id],
                                                                        action: 'delete_permanently',
                                                                    });
                                                                }
                                                            }}
                                                        />
                                                    </RowActions>
                                                ) : (
                                                    <RowActions>
                                                        <RowAction
                                                            icon="note-pencil"
                                                            label="Edit order"
                                                            onClick={() => setSelectedOrder(order)}
                                                        />

                                                        <RowAction
                                                            icon="download-simple"
                                                            label="Export as an import-ready CSV"
                                                            onClick={() => exportOrders([order])}
                                                        />

                                                        <RowActionMenu
                                                            icon="printer"
                                                            label="Print"
                                                            items={[
                                                                {
                                                                    key: 'invoice',
                                                                    label: 'Invoice',
                                                                    icon: 'receipt',
                                                                    onSelect: () => printDocuments([order], 'invoice'),
                                                                },
                                                                {
                                                                    key: 'receipt',
                                                                    label: 'Receipt',
                                                                    icon: 'ticket',
                                                                    onSelect: () => printDocuments([order], 'receipt'),
                                                                },
                                                            ]}
                                                        />

                                                        <RowAction
                                                            icon="trash"
                                                            label="Move to trash"
                                                            variant="danger"
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        `Move order ${order.order_number} to trash?`,
                                                                    )
                                                                ) {
                                                                    bulkUpdate.mutate({
                                                                        order_ids: [order.id],
                                                                        action: 'trash',
                                                                    });
                                                                }
                                                            }}
                                                        />
                                                    </RowActions>
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
                                <div className="grid gap-4 p-4">
                                    {orders.map((order) => (
                                        <div
                                            key={order.id}
                                            onClick={() => setSelectedOrder(order)}
                                            className="cursor-pointer rounded-lg border border-[var(--color-border-light)] p-4 transition-colors hover:bg-[var(--color-site-bg)]"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <p className="font-semibold text-[var(--color-text-main)]">{order.order_number}</p>
                                                    <p className="text-sm text-[var(--color-text-muted)]">{order.customer?.name ?? 'Walk-in'}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-semibold text-[var(--color-text-main)]">
                                                        {order.native.symbol}{(order.native.total / 100).toFixed(2)}
                                                    </p>
                                                    <StatusBadge status={order.status} label={order.status} />
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedOrders.length}
                onClearSelection={() => setSelectedOrders([])}
            >
                <BulkActionsMenu
                    trigger={(props) => (
                        <button
                            {...props}
                            className="flex items-center gap-2 rounded-[var(--shell-radius-sm)] border border-[var(--color-border-light)] bg-white px-3 py-1.5 text-sm font-medium text-[var(--color-text-body)] transition-colors hover:bg-[var(--shell-hover)] disabled:opacity-50 disabled:cursor-not-allowed"
                            style={{ height: '32px' }}
                        >
                            <Icon name="list" size={16} />
                            <span>Actions</span>
                            <Icon name="caret-down" size={12} />
                        </button>
                    )}
                    groups={
                        tab === 'trashed'
                            ? [
                                  {
                                      label: 'Trash Actions',
                                      icon: 'trash',
                                      items: [
                                          {
                                              key: 'restore',
                                              label: 'Restore from Trash',
                                              icon: 'arrow-counter-clockwise',
                                              onSelect: () => {
                                                  bulkUpdate.mutate({ order_ids: selectedOrders, action: 'restore' });
                                              },
                                          },
                                          {
                                              key: 'delete',
                                              label: 'Delete Permanently',
                                              icon: 'trash',
                                              variant: 'danger',
                                              onSelect: () => {
                                                  if (window.confirm(`Permanently delete ${selectedOrders.length} orders? This cannot be undone.`)) {
                                                      bulkUpdate.mutate({ order_ids: selectedOrders, action: 'delete_permanently' });
                                                  }
                                              },
                                          },
                                      ],
                                  },
                              ]
                            : tab === 'archived'
                              ? [
                                    {
                                        label: 'Document',
                                        icon: 'document',
                                        items: [
                                            {
                                                key: 'export',
                                                label: 'Export as CSV',
                                                icon: 'download-simple',
                                                description: 'Import-ready CSV file',
                                                onSelect: () => exportOrders(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean)),
                                            },
                                            {
                                                key: 'print-invoices',
                                                label: 'Print Invoices',
                                                icon: 'receipt',
                                                description: 'Print on A4 paper',
                                                onSelect: () => printDocuments(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean), 'invoice'),
                                            },
                                            {
                                                key: 'print-receipts',
                                                label: 'Print Receipts',
                                                icon: 'ticket',
                                                description: 'Print on thermal paper',
                                                onSelect: () => printDocuments(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean), 'receipt'),
                                            },
                                        ],
                                    },
                                    {
                                        label: 'Order Status',
                                        icon: 'gear',
                                        items: allStatuses.map((status) => ({
                                            key: `status-${status.value}`,
                                            label: status.label,
                                            onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: `status:${status.value}` }),
                                        })),
                                    },
                                    {
                                        label: 'Archive Actions',
                                        icon: 'archive',
                                        items: [
                                            {
                                                key: 'unarchive',
                                                label: 'Move to Active Orders',
                                                icon: 'arrow-u-up-left',
                                                onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: 'unarchive' }),
                                            },
                                            {
                                                key: 'trash',
                                                label: 'Move to Trash',
                                                icon: 'trash',
                                                variant: 'danger',
                                                onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: 'trash' }),
                                            },
                                        ],
                                    },
                                ]
                              : [
                                    {
                                        label: 'Document',
                                        icon: 'document',
                                        items: [
                                            {
                                                key: 'export',
                                                label: 'Export as CSV',
                                                icon: 'download-simple',
                                                description: 'Import-ready CSV file',
                                                onSelect: () => exportOrders(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean)),
                                            },
                                            {
                                                key: 'print-invoices',
                                                label: 'Print Invoices',
                                                icon: 'receipt',
                                                description: 'Print on A4 paper',
                                                onSelect: () => printDocuments(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean), 'invoice'),
                                            },
                                            {
                                                key: 'print-receipts',
                                                label: 'Print Receipts',
                                                icon: 'ticket',
                                                description: 'Print on thermal paper',
                                                onSelect: () => printDocuments(selectedOrders.map(id => orders.find(o => o.id === id)!).filter(Boolean), 'receipt'),
                                            },
                                        ],
                                    },
                                    {
                                        label: 'Order Status',
                                        icon: 'gear',
                                        items: allStatuses.map((status) => ({
                                            key: `status-${status.value}`,
                                            label: status.label,
                                            onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: `status:${status.value}` }),
                                        })),
                                    },
                                    {
                                        label: 'Payment Status',
                                        icon: 'currency-dollar',
                                        items: availablePaymentStatuses.map((status) => ({
                                            key: `payment-${status.value}`,
                                            label: status.label,
                                            onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: `payment:${status.value}` }),
                                        })),
                                    },
                                    couriers.length > 0
                                        ? {
                                              label: 'Send to Courier',
                                              icon: 'truck',
                                              items: couriers.map((courier) => ({
                                                  key: `courier-${courier.id}`,
                                                  label: courier.label,
                                                  onSelect: () => bulkDispatch.mutate({ order_ids: selectedOrders, courier_id: courier.id }),
                                              })),
                                          }
                                        : null,
                                    {
                                        label: 'Actions',
                                        icon: 'bolt',
                                        items: [
                                            {
                                                key: 'archive',
                                                label: 'Archive Orders',
                                                icon: 'archive',
                                                onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: 'archive' }),
                                            },
                                            {
                                                key: 'trash',
                                                label: 'Move to Trash',
                                                icon: 'trash',
                                                variant: 'danger',
                                                onSelect: () => bulkUpdate.mutate({ order_ids: selectedOrders, action: 'trash' }),
                                            },
                                        ],
                                    },
                                ].filter(Boolean) as BulkActionGroup[]
                    }
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedOrder}
                onClose={() => setSelectedOrder(null)}
                title={selectedOrder?.order_number ?? ''}
                subtitle={selectedOrder ? `${selectedOrder.customer?.name ?? 'Walk-in'} · ${formatDate(selectedOrder.date)}` : ''}
                tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: selectedOrder && (
                            <div className="space-y-5">
                                <div style={{ backgroundColor: 'var(--color-site-bg)' }} className="rounded-lg p-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div>
                                            <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                                                Status
                                            </span>
                                            <p className="mt-1 text-sm font-medium text-[var(--color-text-main)]">
                                                {selectedOrder.status.charAt(0).toUpperCase() + selectedOrder.status.slice(1)}
                                            </p>
                                        </div>
                                        {selectedOrder.payment_status === 'paid' && (
                                            <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                                                <Icon name="check-circle" size={14} />
                                                Paid
                                            </span>
                                        )}
                                        <div className="ml-auto text-right">
                                            <span className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                                                Total
                                            </span>
                                            <p className="mt-1 text-lg font-bold text-[var(--color-text-main)]">
                                                {selectedOrder.native.symbol}{(selectedOrder.native.total / 100).toFixed(2)}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-3 border-t border-[var(--color-border-light)] pt-3 text-xs text-[var(--color-text-muted)]">
                                        <span>{selectedOrder.store?.name ?? 'Walk-in'}</span>
                                        <span>·</span>
                                        <span>{selectedOrder.items_count} items</span>
                                        <span>·</span>
                                        <span>{selectedOrder.channel}</span>
                                    </div>
                                </div>

                                <DrawerSection title="Customer">
                                    {selectedOrder.customer ? (
                                        <>
                                            <DrawerField label="Name" value={selectedOrder.customer.name} icon="user" />
                                            <DrawerField label="Email" value={selectedOrder.customer.email ?? '—'} icon="envelope" />
                                        </>
                                    ) : (
                                        <p className="text-sm text-[var(--color-text-muted)]">Walk-in / counter sale</p>
                                    )}
                                </DrawerSection>

                                <DrawerSection title="Delivery">
                                    <DrawerField
                                        label="Address"
                                        value={selectedOrder.shipping_address ? `${selectedOrder.shipping_address.line1}, ${selectedOrder.shipping_address.city}` : '—'}
                                        icon="map-pin"
                                    />
                                </DrawerSection>

                                <DrawerSection title="Payment Summary">
                                    <div className="space-y-2 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-[var(--color-text-muted)]">Subtotal</span>
                                            <span>{selectedOrder.native.symbol}{(selectedOrder.native.subtotal / 100).toFixed(2)}</span>
                                        </div>
                                        {selectedOrder.native.discount > 0 && (
                                            <div className="flex justify-between text-red-600">
                                                <span>Discount</span>
                                                <span>−{selectedOrder.native.symbol}{(selectedOrder.native.discount / 100).toFixed(2)}</span>
                                            </div>
                                        )}
                                        <div className="flex justify-between">
                                            <span className="text-[var(--color-text-muted)]">Shipping</span>
                                            <span>{selectedOrder.native.symbol}{(selectedOrder.native.shipping / 100).toFixed(2)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-[var(--color-text-muted)]">Tax</span>
                                            <span>{selectedOrder.native.symbol}{(selectedOrder.native.tax / 100).toFixed(2)}</span>
                                        </div>
                                        <div className="flex justify-between border-t border-[var(--color-border-light)] pt-2 font-semibold">
                                            <span>Total</span>
                                            <span>{selectedOrder.native.symbol}{(selectedOrder.native.total / 100).toFixed(2)}</span>
                                        </div>
                                    </div>
                                </DrawerSection>

                                {selectedOrder.notes && (
                                    <DrawerSection title="Notes">
                                        <p className="text-sm text-[var(--color-text-body)]">{selectedOrder.notes}</p>
                                    </DrawerSection>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'items',
                        label: 'Items',
                        content: selectedOrder && (
                            <div className="space-y-4">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-[var(--color-border-light)]">
                                            <th className="px-3 py-2 text-left font-medium uppercase tracking-wide">Item</th>
                                            <th className="w-16 px-3 py-2 text-right font-medium uppercase tracking-wide">Qty</th>
                                            <th className="w-28 px-3 py-2 text-right font-medium uppercase tracking-wide">Unit price</th>
                                            <th className="w-28 px-3 py-2 text-right font-medium uppercase tracking-wide">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selectedOrder.items.map((item, idx) => (
                                            <tr key={idx} className="border-b border-[var(--color-border-light)] hover:bg-[var(--color-site-bg)]">
                                                <td className="px-3 py-3">
                                                    <p className="font-medium">{item.description}</p>
                                                    {item.sku && (
                                                        <p className="mt-1 font-mono text-xs text-[var(--color-text-muted)]">{item.sku}</p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-right text-[var(--color-text-muted)]">{item.quantity}</td>
                                                <td className="px-3 py-3 text-right">{selectedOrder.native.symbol}{(item.unit_price / 100).toFixed(2)}</td>
                                                <td className="px-3 py-3 text-right font-medium">{selectedOrder.native.symbol}{(item.total / 100).toFixed(2)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ),
                    },
                    {
                        key: 'history',
                        label: 'History',
                        content: selectedOrder && (
                            <div className="text-center text-sm text-[var(--color-text-muted)]">
                                <p>Created {formatDate(selectedOrder.created_at)}</p>
                            </div>
                        ),
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
            />

            {/* Modals */}
            <QuickCreateModal
                isOpen={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                onSubmit={() => setShowCreateModal(false)}
            />

            <ImportModal
                isOpen={showImportModal}
                onClose={() => setShowImportModal(false)}
                onImport={() => setShowImportModal(false)}
            />
        </div>
    );
}
