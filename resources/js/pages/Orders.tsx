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
import { useMoney } from '@/hooks/useMoney';
import { RowAction, RowActionMenu, RowActions } from '@/components/modules/RowActions';
import {
    downloadCsv,
    orderImportCsv,
    printOrderDocuments,
    type DocumentKind,
} from './orders/documents';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

type Order = {
    id: string;
    order_number: string;
    /** Short tag for the shop it came through — 'VB', or 'WALK' for the counter. */
    store_code: string | null;
    /**
     * Null for a walk-in sale, and deliberately so. Inventing a "Guest"
     * customer here would make a genuine customer of that name indistinguishable
     * from an anonymous one, and would put a row in the customer list that
     * nobody created.
     */
    customer: {
        id: string;
        name: string;
        email: string | null;
    } | null;
    /** The shop it came through, or null for a sale taken at the counter. */
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
    /** The breakdown in the currency the sale was taken in, so it adds up. */
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

    /**
     * The total twice: converted into the books, and as the shop charged it.
     *
     * Both are needed. A business reads its book in one currency, but when
     * somebody checks a row against the shop's own admin the converted figure
     * will not match — so the original is shown beneath it rather than left to
     * be puzzled over.
     */
    total_native: number;
    source_currency: string;
    source_symbol: string;
    is_converted: boolean;
    /** The books' currency every converted figure on this row is stated in. */
    currency: string;
};

type OrdersResponse = {
    data: Order[];
    /** The shops this business sells through, for the filter. */
    stores: Array<{ id: string; name: string }>;
    /** Available order statuses for bulk actions (filtered to what's in use) */
    statuses: Array<{ value: string; label: string; tone: string; custom: boolean }>;
    /** All statuses with their labels and tones (for rendering any status) */
    all_statuses: Array<{ value: string; label: string; tone: string; custom: boolean }>;
    /** Available payment statuses */
    payment_statuses: Array<{ value: string; label: string }>;
    /** Available fulfillment statuses */
    fulfilment_statuses: Array<{ value: string; label: string }>;
    /** Connected couriers for dispatch */
    couriers: Array<{ id: string; label: string; slug: string }>;
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

    // State
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [paymentFilter, setPaymentFilter] = useState('');
    const [storeFilter, setStoreFilter] = useState('');
    const [tab, setTab] = useState<'all' | 'trashed' | 'archived'>('all');

    /*
     * One range, not two loose date boxes.
     *
     * The same picker the dashboard uses — presets down one side, a calendar
     * down the other — so "last month" is one click rather than two dates
     * somebody has to look up. It also makes an inverted range impossible to
     * express, which the two-input version happily allowed.
     */
    const [range, setRange] = useState<DateRange | null>(null);

    // Sent as plain dates. Built from the local parts rather than toISOString,
    // which converts to UTC and moves the boundary by a day for anyone east or
    // west of it — the classic "my last order is missing" bug.
    const asDate = (date: Date): string =>
        `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    const dateFrom = range ? asDate(range.start) : '';
    const dateTo = range ? asDate(range.end) : '';
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [selectedOrders, setSelectedOrders] = useState<string[]>([]);
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showImportModal, setShowImportModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);

    // Fetch orders
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['orders', { search, statusFilter, paymentFilter, storeFilter, dateFrom, dateTo, sortBy, sortDirection, page, perPage, tab }],
        queryFn: ({ signal }) =>
            api.get<OrdersResponse>('/orders', {
                params: {
                    search,
                    status: statusFilter || undefined,
                    payment_status: paymentFilter || undefined,
                    store: storeFilter || undefined,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                    page,
                    per_page: perPage,
                    tab,
                },
                signal,
            }),
    });

    const orders = data?.data ?? [];
    const summary = data?.summary;
    const stores = data?.stores ?? [];
    const availableStatuses = data?.statuses ?? [];
    const allStatuses = data?.all_statuses ?? [];
    const availablePaymentStatuses = data?.payment_statuses ?? [];
    const availableFulfilmentStatuses = data?.fulfilment_statuses ?? [];
    const couriers = data?.couriers ?? [];
    const meta = data?.meta;

    // Filter setters that reset pagination
    const handleSearchChange = (value: string) => {
        setSearch(value);
        setPage(1);
    };

    const handleStatusFilterChange = (value: string) => {
        setStatusFilter(value);
        setPage(1);
    };

    const handlePaymentFilterChange = (value: string) => {
        setPaymentFilter(value);
        setPage(1);
    };

    const handleStoreFilterChange = (value: string) => {
        setStoreFilter(value);
        setPage(1);
    };

    const handleRangeChange = (value: DateRange | null) => {
        setRange(value);
        setPage(1);
    };

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
        setStoreFilter('');
        setRange(null);
        setPage(1); // Reset to first page when clearing filters
    };

    const hasFilters = search || statusFilter || paymentFilter || storeFilter || dateFrom || dateTo;

    /*
     * ── Bulk actions ─────────────────────────────────────────────────────────
     *
     * Export and print are done here, from the rows already loaded, because
     * both are read-only and the data is in hand — a round trip would only add
     * a spinner.
     *
     * Bulk status change is deliberately absent rather than stubbed. Moving an
     * order to completed or cancelled moves stock and touches the ledger, so it
     * belongs behind OrderService and a write endpoint that does not exist yet.
     * A button that logs to the console and clears the selection looks like it
     * worked, which is the worst of the three options.
     */
    const selectedRows = () => orders.filter((order) => selectedOrders.includes(order.id));

    const handleBulkExport = () => {
        const rows = selectedRows();

        if (rows.length === 0) return;

        const columns = [
            'Order', 'Date', 'Customer', 'Store', 'Status', 'Payment', 'Dispatch',
            'Items', `Total (${rows[0]?.currency ?? ''})`, 'Charged', 'Currency',
        ];

        // Quoted and doubled: a customer called O'Brien or a store called
        // "Main, Downtown" would otherwise split a row into two columns.
        const cell = (value: unknown) => `"${String(value ?? '').replace(/"/g, '""')}"`;

        const csv = [
            columns.map(cell).join(','),
            ...rows.map((order) =>
                [
                    order.order_number,
                    order.date,
                    order.customer?.name ?? 'Walk-in',
                    order.store?.name ?? 'Walk-in',
                    statusLabels[order.status] ?? order.status,
                    paymentLabels[order.payment_status] ?? order.payment_status,
                    fulfilmentLabels[order.fulfilment_status] ?? order.fulfilment_status,
                    order.items_count,
                    order.total,
                    order.total_native,
                    order.source_currency,
                ].map(cell).join(','),
            ),
        ].join('\r\n');

        // A BOM, so Excel opens it as UTF-8 rather than mangling every non-Latin
        // name in the file — which for this market is most of them.
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = `orders-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const handleBulkPrint = () => {
        const rows = selectedRows();
        if (rows.length === 0) return;

        printOrders(rows);
    };

    const handlePrintOrder = (order: Order) => {
        printOrders([order]);
    };

    const printOrders = (orders: Order[]) => {
        // Create printable HTML
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Orders - ${new Date().toLocaleDateString()}</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: system-ui, -apple-system, sans-serif; padding: 20mm; font-size: 11pt; }
                    h1 { font-size: 18pt; margin-bottom: 10mm; color: #111; }
                    .meta { margin-bottom: 8mm; color: #666; font-size: 10pt; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
                    th { background: #f3f4f6; padding: 8px; text-align: left; font-weight: 600; font-size: 9pt; text-transform: uppercase; color: #374151; border-bottom: 2px solid #d1d5db; }
                    td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
                    tr:last-child td { border-bottom: none; }
                    .order-card { page-break-inside: avoid; margin-bottom: 8mm; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .order-header { background: #f9fafb; padding: 12px; border-bottom: 1px solid #e5e7eb; }
                    .order-header h2 { font-size: 14pt; color: #111; margin-bottom: 4px; }
                    .order-header .date { font-size: 10pt; color: #6b7280; }
                    .order-body { padding: 12px; }
                    .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px; }
                    .info-item { }
                    .info-label { font-size: 9pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
                    .info-value { font-size: 11pt; color: #111; font-weight: 500; }
                    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 9pt; font-weight: 600; }
                    .status-processing { background: #dbeafe; color: #1e40af; }
                    .status-completed { background: #d1fae5; color: #065f46; }
                    .status-cancelled { background: #fee2e2; color: #991b1b; }
                    .status-confirmed { background: #fef3c7; color: #92400e; }
                    .total { font-size: 14pt; font-weight: 700; color: #111; text-align: right; margin-top: 8px; }
                    @media print {
                        body { padding: 10mm; }
                        .order-card { page-break-inside: avoid; }
                    }
                </style>
            </head>
            <body>
                <h1>Orders</h1>
                <div class="meta">
                    <div>Printed: ${new Date().toLocaleString()}</div>
                    <div>Total: ${orders.length} order${orders.length === 1 ? '' : 's'}</div>
                </div>

                ${orders.map(order => `
                    <div class="order-card">
                        <div class="order-header">
                            <h2>Order #${order.order_number}</h2>
                            <div class="date">${new Date(order.date).toLocaleDateString()}</div>
                        </div>
                        <div class="order-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Customer</div>
                                    <div class="info-value">${order.customer?.name || 'Walk-in'}</div>
                                    ${order.customer?.email ? `<div style="font-size: 10pt; color: #6b7280;">${order.customer.email}</div>` : ''}
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Store</div>
                                    <div class="info-value">${order.store?.name || 'Walk-in'}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Status</div>
                                    <div class="info-value">
                                        <span class="status-badge status-${order.status}">${statusLabels[order.status] || order.status}</span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Payment</div>
                                    <div class="info-value">${paymentLabels[order.payment_status] || order.payment_status}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Fulfillment</div>
                                    <div class="info-value">${fulfilmentLabels[order.fulfilment_status] || order.fulfilment_status}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Items</div>
                                    <div class="info-value">${order.items_count}</div>
                                </div>
                            </div>
                            <div class="total">Total: ${order.source_symbol}${order.total_native.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                        </div>
                    </div>
                `).join('')}
            </body>
            </html>
        `;

        // Open print window
        const printWindow = window.open('', '_blank');
        if (printWindow) {
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            
            // Wait for content to load, then print
            printWindow.onload = () => {
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 250);
            };
        } else {
            toast.error('Please allow popups to print orders.');
        }
    };

    // Bulk update mutation
    const bulkUpdate = useMutation({
        mutationFn: (params: { order_ids: string[]; action: string; status?: string; payment_status?: string; fulfilment_status?: string }) =>
            api.post('/orders/bulk-update', params),
        onSuccess: (result) => {
            const data = result as { message: string; data: { updated: number; failed: number } };
            if (data.data.failed > 0 && data.data.updated === 0) {
                toast.error(data.message);
            } else if (data.data.failed > 0) {
                toast.warning(data.message);
            } else {
                toast.success(data.message);
            }
            setSelectedOrders([]);
            setBulkAction('');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Bulk update failed.'),
    });

    // Single order dispatch mutation
    const dispatchOrder = useMutation({
        mutationFn: (params: { orderId: string; courier_id: string; amount?: number }) =>
            api.post(`/orders/${params.orderId}/dispatch`, {
                courier_id: params.courier_id,
                amount: params.amount,
            }),
        onSuccess: (result) => {
            const data = result as { message: string };
            toast.success(data.message);
            setShowDispatchModal(false);
            setDispatchOrderId(null);
            setDispatchAmount('');
            setSelectedCourier('');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Dispatch failed.'),
    });

    const cancelDispatch = useMutation({
        mutationFn: (orderId: string) => api.post(`/orders/${orderId}/cancel-dispatch`),
        onSuccess: (result: { message?: string }) => {
            toast.success(result.message ?? 'Shipment cancelled with the courier.');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'The courier could not cancel this shipment.'),
    });

    // Bulk dispatch mutation
    const bulkDispatch = useMutation({
        mutationFn: (params: { order_ids: string[]; courier_id: string }) =>
            api.post('/orders/bulk-dispatch', params),
        onSuccess: (result) => {
            const data = result as { message: string; data: { dispatched: number; failed: number } };
            if (data.data.failed > 0 && data.data.dispatched === 0) {
                toast.error(data.message);
            } else if (data.data.failed > 0) {
                toast.warning(data.message);
            } else {
                toast.success(data.message);
            }
            setSelectedOrders([]);
            setBulkAction('');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Bulk dispatch failed.'),
    });

    const [bulkAction, setBulkAction] = useState('');

    const [showDispatchModal, setShowDispatchModal] = useState(false);
    const [dispatchOrderId, setDispatchOrderId] = useState<string | null>(null);
    const [dispatchAmount, setDispatchAmount] = useState<string>('');
    const [selectedCourier, setSelectedCourier] = useState<string>('');

    const handleApplyBulkAction = () => {
        if (!bulkAction) {
            toast.error('Please select a bulk action first.');
            return;
        }
        if (selectedOrders.length === 0) {
            toast.error('Please select at least one order.');
            return;
        }

        // Parse action (format: "action:value" or just "action")
        const [actionType, actionValue] = bulkAction.split(':');

        switch (actionType) {
            case 'status':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'update_status', status: actionValue });
                break;
            case 'payment':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'update_status', payment_status: actionValue });
                break;
            case 'fulfilment':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'update_status', fulfilment_status: actionValue });
                break;
            case 'mark_paid':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'mark_paid' });
                break;
            case 'cancel':
                if (!confirm(`Cancel ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'}?`)) return;
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'cancel' });
                break;
            case 'trash':
                if (!confirm(`Move ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'} to trash?`)) return;
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'trash' });
                break;
            case 'restore':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'restore' });
                break;
            case 'unarchive':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'unarchive' });
                break;
            case 'delete_permanently':
                if (!confirm(`⚠️ PERMANENTLY DELETE ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'}?\n\nThis action CANNOT be undone!\n\nThe order data will be completely removed from the database.`)) return;
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'delete_permanently' });
                break;
            case 'courier':
                // Dispatch to courier
                bulkDispatch.mutate({ order_ids: selectedOrders, courier_id: actionValue });
                break;
            case 'export':
                handleBulkExport();
                setSelectedOrders([]);
                setBulkAction('');
                break;
            case 'print':
                handleBulkPrint();
                setSelectedOrders([]);
                setBulkAction('');
                break;
            default:
                toast.error('Unknown action.');
        }
    };

    // Inline dispatch handler
    const handleInlineDispatch = (orderId: string, courierId: string) => {
        const order = orders.find(o => o.id === orderId);
        if (!order) return;

        // If single order, show modal for amount input
        setDispatchOrderId(orderId);
        setSelectedCourier(courierId);
        setDispatchAmount(order.total_native.toString());
        setShowDispatchModal(true);
    };

    const handleConfirmDispatch = () => {
        if (!dispatchOrderId || !selectedCourier) {
            toast.error('Please select a courier.');
            return;
        }

        const amount = dispatchAmount ? parseFloat(dispatchAmount) : undefined;
        dispatchOrder.mutate({
            orderId: dispatchOrderId,
            courier_id: selectedCourier,
            amount,
        });
    };

    // Create order
    const handleCreateOrder = () => {
        console.log('Creating order');
        // TODO: Implement create
        setShowCreateModal(false);
    };

    // Import orders
    const handleImport = async (file: File, format: string) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('format', format);

        try {
            const response = await api.post<{
                data: {
                    imported: number;
                    updated: number;
                    skipped: number;
                    failed: number;
                    total: number;
                    errors: string[];
                };
            }>('/orders/import', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            const { imported, updated, skipped, failed, total, errors } = response.data;

            // Show detailed success message
            if (failed === 0) {
                toast.success(
                    `Successfully imported ${imported} new orders${updated > 0 ? ` and updated ${updated} existing orders` : ''}!`
                );
            } else {
                toast.warning(
                    `Imported ${imported} orders, ${failed} failed. ${updated > 0 ? `Updated ${updated}.` : ''} ${
                        skipped > 0 ? `Skipped ${skipped}.` : ''
                    }`
                );
                
                // Log errors to console for debugging
                if (errors.length > 0) {
                    console.error('Import errors:', errors);
                }
            }

            void refetch();
        } catch (error: any) {
            toast.error(error.response?.data?.message || 'Failed to import orders');
            throw error;
        }
    };

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

    /*
     * The vocabulary these columns actually hold.
     *
     * Built dynamically from the backend response, which includes all statuses
     * this business uses (built-in + custom additions they've created). This
     * ensures the labels match what was configured during status mapping and
     * includes any custom statuses like "Awaiting Parts", "Follow-up", etc.
     * 
     * Uses all_statuses (complete map) for rendering, and statuses (filtered) for dropdowns.
     */
    const statusLabels: Record<string, string> = allStatuses.reduce((acc, status) => {
        acc[status.value] = status.label;
        return acc;
    }, {} as Record<string, string>);

    const statusVariants: Record<string, 'warning' | 'info' | 'brand' | 'success' | 'danger' | 'neutral'> = allStatuses.reduce((acc, status) => {
        acc[status.value] = (status.tone as 'warning' | 'info' | 'brand' | 'success' | 'danger' | 'neutral') ?? 'neutral';
        return acc;
    }, {} as Record<string, 'warning' | 'info' | 'brand' | 'success' | 'danger' | 'neutral'>);

    const paymentLabels: Record<string, string> = {
        paid: 'Paid',
        unpaid: 'Unpaid',
    };

    const paymentVariants: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
        paid: 'success',
        unpaid: 'warning',
    };

    /** One click, always the same file — the importer's own columns, so it round-trips. */
    const exportOrders = (rows: Order[]) => {
        downloadCsv(`orders-${new Date().toISOString().slice(0, 10)}.csv`, orderImportCsv(rows));
    };

    const printDocuments = (rows: Order[], kind: DocumentKind) => {
        printOrderDocuments(rows, kind, { status: statusLabels, payment: paymentLabels });
    };

    const fulfilmentLabels: Record<string, string> = {
        fulfilled: 'Dispatched',
        unfulfilled: 'Not dispatched',
    };

    const channelLabels: Record<string, string> = {
        online: 'Online',
        pos: 'POS',
        phone: 'Phone',
        api: 'API',
        manual: 'Manual',
    };

    const dispatchStatusLabels: Record<string, string> = {
        draft: 'Preparing',
        booked: 'Booked',
        picked_up: 'Picked up',
        in_transit: 'In transit',
        out_for_delivery: 'Out for delivery',
        delivered: 'Delivered',
        returned: 'Returned',
        cancelled: 'Cancelled',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="pt-6">
                <PageHeader
                    title="Orders"
                    description="Manage customer orders and fulfillment"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => setShowImportModal(true)}
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

            {/* KPI row */}
            {summary && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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

            {/* Tabs */}
            <div className="mt-4 border-b border-[var(--shell-border)]">
                <div className="flex gap-1">
                    <button
                        type="button"
                        onClick={() => {
                            setTab('all');
                            setPage(1);
                            setSelectedOrders([]); // Clear selection when switching tabs
                        }}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                            tab === 'all'
                                ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--shell-border)]'
                        }`}
                    >
                        All Orders
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setTab('archived');
                            setPage(1);
                            setSelectedOrders([]); // Clear selection when switching tabs
                        }}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                            tab === 'archived'
                                ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--shell-border)]'
                        }`}
                    >
                        <Icon name="archive" size={14} className="inline mr-1" />
                        Archived
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setTab('trashed');
                            setPage(1);
                            setSelectedOrders([]); // Clear selection when switching tabs
                        }}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                            tab === 'trashed'
                                ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--shell-border)]'
                        }`}
                    >
                        <Icon name="trash" size={14} className="inline mr-1" />
                        Trash
                    </button>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-2">
                <FilterBar
                    stacked
                    searchValue={search}
                    onSearchChange={handleSearchChange}
                    searchPlaceholder="Search by order #, customer name, or email..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={handleStatusFilterChange}
                                /*
                                 * Shows all statuses this business has configured —
                                 * the built-in ones plus any custom additions they've
                                 * created during integration mapping. So if they
                                 * added "Awaiting Parts" or "Follow-up", those
                                 * appear here with their configured labels.
                                 */
                                options={allStatuses.map(status => ({
                                    value: status.value,
                                    label: status.label + (status.custom ? ' (Custom)' : ''),
                                }))}
                                placeholder="All statuses"
                            />
                            <FilterSelect
                                label="Payment"
                                value={paymentFilter}
                                onChange={handlePaymentFilterChange}
                                options={[
                                    { value: 'paid', label: 'Paid' },
                                    { value: 'unpaid', label: 'Unpaid' },
                                ]}
                                placeholder="All payments"
                            />

                            {/*
                              Which shop, or the counter.
                              Walk-in is always offered — every business has a
                              counter even before it has a website — while the
                              shops themselves come from what is actually
                              connected.
                            */}
                            <FilterSelect
                                label="Store"
                                value={storeFilter}
                                onChange={handleStoreFilterChange}
                                options={[
                                    { value: 'walk_in', label: 'Walk-in / counter' },
                                    ...stores.map((store) => ({ value: store.id, label: store.name })),
                                ]}
                                placeholder="All stores"
                            />

                            <DateRangePicker value={range} onChange={handleRangeChange} />
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
            <div className="flex-1 overflow-auto pb-6">
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
                                tableClassName="min-w-[1360px]"
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
                                                    onChange={(checked) =>
                                                        handleSelectOrder(order.id, checked)
                                                    }
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
                                                {/*
                                                  Which shop, in the space a bare
                                                  '#' used to occupy.

                                                  The hash said nothing — every row
                                                  had one. Order numbers come from
                                                  each platform's own sequence, so
                                                  two shops can both have a 1043 and
                                                  the number alone does not say
                                                  whose. The tag does, without
                                                  costing a column.
                                                */}
                                                <span
                                                    className="flex h-8 min-w-8 shrink-0 items-center justify-center rounded-[var(--shell-radius-sm)] bg-[var(--color-brand-subtle)] px-1.5 text-[11px] font-bold tracking-wide text-[var(--color-brand)]"
                                                    title={order.store?.name ?? 'Walk-in / counter'}
                                                >
                                                    {order.store_code ?? '#'}
                                                </span>
                                                <div className="min-w-0 whitespace-nowrap">
                                                    <p className="font-semibold text-[var(--color-text-main)]">
                                                        {order.order_number}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                        {formatDate(order.date)}
                                                    </p>
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'customer',
                                        label: 'Customer',
                                        sortable: false,
                                        width: 'w-64',
                                        render: (order) => (
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--shell-muted)] text-[11px] font-bold text-[var(--color-text-muted)]">
                                                        {(order.customer?.name ?? 'W').slice(0, 1).toUpperCase()}
                                                    </span>
                                                    <p className="truncate font-medium text-[var(--color-text-main)]">
                                                        {order.customer?.name ?? 'Walk-in'}
                                                    </p>
                                                </div>
                                                <p className="mt-1 truncate pl-9 text-xs text-[var(--color-text-muted)]">
                                                    {order.customer?.email ?? 'Counter sale'}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        // Where it came from. 'Online' stops
                                        // being an answer the moment a business
                                        // runs more than one shop.
                                        key: 'store',
                                        label: 'Store',
                                        sortable: false,
                                        width: 'w-44',
                                        render: (order) =>
                                            order.store ? (
                                                <span className="text-sm text-[var(--color-text-body)]">
                                                    {order.store.name}
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 text-sm text-[var(--color-text-muted)]">
                                                    <Icon name="storefront" size={13} />
                                                    Walk-in
                                                </span>
                                            ),
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        sortable: true,
                                        width: 'w-36',
                                        // Falls back to the raw value: a status
                                        // nobody has a label for should show
                                        // itself, not an empty cell.
                                        render: (order) => (
                                            <StatusBadge
                                                label={statusLabels[order.status] ?? order.status}
                                                variant={statusVariants[order.status] ?? 'neutral'}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'payment_status',
                                        label: 'Payment',
                                        sortable: false,
                                        width: 'w-36',
                                        render: (order) => (
                                            <StatusBadge
                                                label={paymentLabels[order.payment_status] ?? order.payment_status}
                                                variant={paymentVariants[order.payment_status] ?? 'neutral'}
                                                dot
                                            />
                                        ),
                                    },
                                    {
                                        // Whether it has gone out is its own
                                        // fact, not a point on the status line —
                                        // a paid order can sit undispatched for
                                        // a week, and that is what a sales
                                        // screen needs to show.
                                        key: 'fulfilment_status',
                                        label: 'Dispatch',
                                        width: 'w-36',
                                        render: (order) => (
                                            <StatusBadge
                                                label={fulfilmentLabels[order.fulfilment_status] ?? order.fulfilment_status}
                                                variant={order.fulfilment_status === 'fulfilled' ? 'success' : 'neutral'}
                                                dot
                                            />
                                        ),
                                    },
                                    {
                                        key: 'items_count',
                                        label: 'Items',
                                        width: 'w-20',
                                        align: 'center',
                                        accessor: (o) => o.items_count,
                                    },
                                    {
                                        key: 'total',
                                        label: 'Total',
                                        sortable: true,
                                        width: 'w-40',
                                        align: 'right',
                                        /*
                                         * What the order was actually taken in,
                                         * with the books' equivalent beneath it
                                         * when the two differ.
                                         *
                                         * ── Why the shop's own figure leads ──
                                         *
                                         * Because it is the only one that is a
                                         * fact about the order. An order placed
                                         * in a shop selling in taka is a taka
                                         * order; the figure in the books is that
                                         * fact passed through a rate that will be
                                         * different tomorrow.
                                         *
                                         * It is also the number somebody is
                                         * checking against — the shop's admin,
                                         * the customer's receipt, the courier's
                                         * slip all say the native amount. Leading
                                         * with the converted one meant every row
                                         * had to be mentally translated back, and
                                         * an unconverted row showed no currency at
                                         * all, so nothing on screen said which of
                                         * the two you were looking at.
                                         *
                                         * The currency is always shown for the
                                         * same reason: a bare 440 in a business
                                         * with three shops is not an amount.
                                         */
                                        render: (o) => (
                                            <div className="text-right">
                                                <span
                                                    className="font-semibold tabular-nums"
                                                    title={`Taken in ${o.source_currency}`}
                                                >
                                                    {o.source_symbol}
                                                    {o.total_native.toLocaleString(undefined, {
                                                        minimumFractionDigits: 2,
                                                        maximumFractionDigits: 2,
                                                    })}
                                                </span>

                                                {o.is_converted && (
                                                    <span
                                                        className="mt-0.5 block text-xs tabular-nums text-[var(--color-text-muted)]"
                                                        title={`In the books, converted to ${o.currency}`}
                                                    >
                                                        ≈ {formatMoney(o.total)}
                                                    </span>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'courier',
                                        label: 'Courier',
                                        width: 'w-56',
                                        render: (order) => (
                                            <div onClick={(e) => e.stopPropagation()}>
                                                {order.dispatch ? (
                                                    <div className="min-w-0" title={order.dispatch.tracking_number ?? order.dispatch.shipment_number}>
                                                        <span className="block truncate text-sm font-semibold text-[var(--color-text-body)]">
                                                            {order.dispatch.courier.label ?? 'Courier'}
                                                        </span>
                                                        <span className="mt-0.5 block truncate text-xs text-[var(--color-text-muted)]">
                                                            {order.source_symbol}{order.dispatch.amount.toLocaleString(undefined, {
                                                                minimumFractionDigits: 2,
                                                                maximumFractionDigits: 2,
                                                            })}
                                                            <span className="mx-1">·</span>
                                                            {dispatchStatusLabels[order.dispatch.status] ?? order.dispatch.status}
                                                        </span>
                                                        {tab !== 'archived' && tab !== 'trashed' && !['delivered', 'cancelled', 'returned'].includes(order.dispatch.status) && (
                                                            <button
                                                                type="button"
                                                                className="mt-1 block text-xs font-medium text-[var(--color-danger)] hover:underline"
                                                                onClick={() => {
                                                                    if (window.confirm(`Cancel shipment for ${order.order_number}?`)) {
                                                                        cancelDispatch.mutate(order.id);
                                                                    }
                                                                }}
                                                                disabled={cancelDispatch.isPending}
                                                            >
                                                                Cancel shipment
                                                            </button>
                                                        )}
                                                    </div>
                                                ) : tab !== 'archived' && tab !== 'trashed' && couriers.length > 0 ? (
                                                    <select
                                                        className="field field-sm"
                                                        style={{ width: '100%', minWidth: '150px' }}
                                                        value=""
                                                        onChange={(e) => {
                                                            if (e.target.value) {
                                                                handleInlineDispatch(order.id, e.target.value);
                                                            }
                                                        }}
                                                    >
                                                        <option value="">Send to…</option>
                                                        {couriers.map(courier => (
                                                            <option key={courier.id} value={courier.id}>
                                                                {courier.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <span className="text-xs text-[var(--color-text-muted)]">
                                                        {tab === 'archived' || tab === 'trashed' ? 'Not dispatched' : '—'}
                                                    </span>
                                                )}
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
                            /*
                             * Grid view: the same orders as cards.
                             *
                             * Not a decorative alternative — a table asks you to
                             * read across nine columns, and when somebody is
                             * scanning for one order among a screenful, a card
                             * that puts the number, the customer and the total
                             * together is faster. Same data, same selection,
                             * same click-through to the drawer.
                             */
                            <div className="grid gap-3 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
                                {orders.length === 0 ? (
                                    <p className="col-span-full py-10 text-center text-sm text-[var(--color-text-muted)]">
                                        {hasFilters
                                            ? 'No orders match these filters.'
                                            : 'No orders yet.'}
                                    </p>
                                ) : (
                                    orders.map((order) => (
                                        <button
                                            key={order.id}
                                            type="button"
                                            onClick={() => setSelectedOrder(order)}
                                            className="card p-4 text-left transition hover:border-[var(--color-brand)]"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold" title={order.order_number}>
                                                        {order.order_number}
                                                    </p>
                                                    <p className="mt-0.5 truncate text-sm text-[var(--color-text-body)]">
                                                        {order.customer?.name ?? 'Walk-in'}
                                                    </p>
                                                </div>

                                                <StatusBadge
                                                    label={statusLabels[order.status] ?? order.status}
                                                    variant={statusVariants[order.status] ?? 'neutral'}
                                                />
                                            </div>

                                            <div className="mt-3 flex items-end justify-between gap-3 border-t border-[var(--shell-border)] pt-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-xs text-[var(--color-text-muted)]">
                                                        {order.store?.name ?? 'Walk-in'} · {formatDate(order.date)}
                                                    </p>
                                                    <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                                        {order.items_count} item{order.items_count === 1 ? '' : 's'}
                                                    </p>
                                                </div>

                                                {/* Same order as the table: what
                                                    the shop charged, then the
                                                    books' equivalent. */}
                                                <div className="text-right">
                                                    <p
                                                        className="font-semibold tabular-nums"
                                                        title={`Taken in ${order.source_currency}`}
                                                    >
                                                        {order.source_symbol}
                                                        {order.total_native.toLocaleString(undefined, {
                                                            minimumFractionDigits: 2,
                                                            maximumFractionDigits: 2,
                                                        })}
                                                    </p>
                                                    {order.is_converted && (
                                                        <p className="text-xs tabular-nums text-[var(--color-text-muted)]">
                                                            ≈ {formatMoney(order.total)}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="mt-3 flex flex-wrap gap-1.5">
                                                <StatusBadge
                                                    label={paymentLabels[order.payment_status] ?? order.payment_status}
                                                    variant={paymentVariants[order.payment_status] ?? 'neutral'}
                                                    dot
                                                />
                                                <StatusBadge
                                                    label={
                                                        fulfilmentLabels[order.fulfilment_status] ??
                                                        order.fulfilment_status
                                                    }
                                                    variant={
                                                        order.fulfilment_status === 'fulfilled' ? 'success' : 'neutral'
                                                    }
                                                    dot
                                                />
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}

                        {/* Pagination */}
                        {!isLoading && meta && meta.last_page > 1 && (
                            <div className="border-t border-[var(--shell-border)] px-4 py-3 sm:px-6">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    {/* Results info */}
                                    <div className="text-sm text-[var(--color-text-muted)]">
                                        Showing <span className="font-medium text-[var(--color-text-main)]">{((meta.current_page - 1) * meta.per_page) + 1}</span> to{' '}
                                        <span className="font-medium text-[var(--color-text-main)]">{Math.min(meta.current_page * meta.per_page, meta.total)}</span> of{' '}
                                        <span className="font-medium text-[var(--color-text-main)]">{meta.total}</span> results
                                    </div>

                                    {/* Pagination controls */}
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setPage(1)}
                                            disabled={meta.current_page === 1}
                                            className="flex h-8 w-8 items-center justify-center rounded border border-[var(--shell-border)] text-[var(--color-text-body)] transition-colors hover:bg-[var(--color-background-subtle)] disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                                            title="First page"
                                        >
                                            <Icon name="caret-double-left" size={14} />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPage(Math.max(1, meta.current_page - 1))}
                                            disabled={meta.current_page === 1}
                                            className="flex h-8 w-8 items-center justify-center rounded border border-[var(--shell-border)] text-[var(--color-text-body)] transition-colors hover:bg-[var(--color-background-subtle)] disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                                            title="Previous page"
                                        >
                                            <Icon name="caret-left" size={14} />
                                        </button>

                                        {/* Page numbers */}
                                        <div className="flex items-center gap-1">
                                            {Array.from({ length: Math.min(5, meta.last_page) }, (_, i) => {
                                                // Show pages around current page
                                                let pageNum: number;
                                                if (meta.last_page <= 5) {
                                                    pageNum = i + 1;
                                                } else if (meta.current_page <= 3) {
                                                    pageNum = i + 1;
                                                } else if (meta.current_page >= meta.last_page - 2) {
                                                    pageNum = meta.last_page - 4 + i;
                                                } else {
                                                    pageNum = meta.current_page - 2 + i;
                                                }

                                                return (
                                                    <button
                                                        key={pageNum}
                                                        type="button"
                                                        onClick={() => setPage(pageNum)}
                                                        className={`flex h-8 min-w-[2rem] items-center justify-center rounded border px-2 text-sm transition-colors ${
                                                            meta.current_page === pageNum
                                                                ? 'border-[var(--color-brand)] bg-[var(--color-brand)] text-white'
                                                                : 'border-[var(--shell-border)] text-[var(--color-text-body)] hover:bg-[var(--color-background-subtle)]'
                                                        }`}
                                                    >
                                                        {pageNum}
                                                    </button>
                                                );
                                            })}
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => setPage(Math.min(meta.last_page, meta.current_page + 1))}
                                            disabled={meta.current_page === meta.last_page}
                                            className="flex h-8 w-8 items-center justify-center rounded border border-[var(--shell-border)] text-[var(--color-text-body)] transition-colors hover:bg-[var(--color-background-subtle)] disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                                            title="Next page"
                                        >
                                            <Icon name="caret-right" size={14} />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPage(meta.last_page)}
                                            disabled={meta.current_page === meta.last_page}
                                            className="flex h-8 w-8 items-center justify-center rounded border border-[var(--shell-border)] text-[var(--color-text-body)] transition-colors hover:bg-[var(--color-background-subtle)] disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                                            title="Last page"
                                        >
                                            <Icon name="caret-double-right" size={14} />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Select all - aligned with table row checkboxes */}
                        {!isLoading && orders.length > 0 && view === 'list' && (
                            <div className="border-t border-[var(--shell-border)]" style={{ padding: '0.6875rem 1rem' }}>
                                <SelectCheckbox
                                    checked={isAllSelected}
                                    indeterminate={isSomeSelected}
                                    onChange={handleSelectAll}
                                    label={
                                        isAllSelected
                                            ? `All ${orders.length} orders selected`
                                            : isSomeSelected
                                              ? `${selectedOrders.length} of ${orders.length} selected`
                                              : `Select all ${orders.length} orders`
                                    }
                                />
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedOrders.length}
                onClearSelection={() => setSelectedOrders([])}
                className="min-w-[800px] max-w-4xl"
            >
                <select
                    value={bulkAction}
                    onChange={(e) => setBulkAction(e.target.value)}
                    className="field field-sm"
                    style={{ width: 'auto', minWidth: '220px', height: '32px' }}
                >
                    <option value="">Select action...</option>
                    
                    {tab === 'trashed' ? (
                        // TRASH TAB: Only restore and delete options
                        <>
                            <optgroup label="Trash Actions">
                                <option value="restore">Restore from Trash</option>
                                <option value="delete_permanently">Delete Permanently</option>
                            </optgroup>
                        </>
                    ) : tab === 'archived' ? (
                        // ARCHIVED TAB: Unarchive and export options
                        <>
                            <optgroup label="Archive Actions">
                                <option value="unarchive">Move to Active Orders</option>
                            </optgroup>
                            <optgroup label="Export">
                                <option value="export">Export CSV</option>
                                <option value="print">Print Orders</option>
                            </optgroup>
                        </>
                    ) : (
                        // ALL ORDERS TAB: Full options
                        <>
                            {allStatuses.length > 0 && (
                                <optgroup label="Change Order Status">
                                    {allStatuses.map(status => (
                                        <option key={status.value} value={`status:${status.value}`}>
                                            {status.label}{status.custom ? ' (Custom)' : ''}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                            
                            {availablePaymentStatuses.length > 0 && (
                                <optgroup label="Payment Status">
                                    {availablePaymentStatuses.map(status => (
                                        <option key={status.value} value={`payment:${status.value}`}>
                                            {status.label}
                                        </option>
                                    ))}
                                    <option value="mark_paid">Mark as Paid (Full Amount)</option>
                                </optgroup>
                            )}
                            
                            {availableFulfilmentStatuses.length > 0 && (
                                <optgroup label="Fulfillment Status">
                                    {availableFulfilmentStatuses.map(status => (
                                        <option key={status.value} value={`fulfilment:${status.value}`}>
                                            {status.label}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                            
                            {couriers.length > 0 && (
                                <optgroup label="Send to Courier">
                                    {couriers.map(courier => (
                                        <option key={courier.id} value={`courier:${courier.id}`}>
                                            {courier.label}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                            
                            <optgroup label="Actions">
                                <option value="cancel">Cancel Orders</option>
                                <option value="trash">Move to Trash</option>
                            </optgroup>
                            
                            <optgroup label="Export">
                                <option value="export">Export CSV</option>
                                <option value="print">Print Orders</option>
                            </optgroup>
                        </>
                    )}
                </select>
                <button
                    type="button"
                    onClick={handleApplyBulkAction}
                    disabled={!bulkAction || bulkUpdate.isPending || bulkDispatch.isPending}
                    className="btn btn-secondary"
                    style={{ height: '32px', padding: '0 12px' }}
                >
                    {(bulkUpdate.isPending || bulkDispatch.isPending) ? 'Applying...' : 'Apply'}
                </button>
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
                                        value={<StatusBadge label={statusLabels[selectedOrder.status] ?? selectedOrder.status} variant={statusVariants[selectedOrder.status] ?? 'neutral'} />}
                                        icon="circle-notch"
                                    />
                                    <DrawerField
                                        label="Payment Status"
                                        value={
                                            <div className="flex items-center gap-2">
                                                <StatusBadge 
                                                    label={paymentLabels[selectedOrder.payment_status] ?? selectedOrder.payment_status} 
                                                    variant={paymentVariants[selectedOrder.payment_status] ?? 'neutral'} 
                                                    dot 
                                                />
                                                {selectedOrder.is_cod && (
                                                    <span className="text-xs text-[var(--color-text-muted)] bg-[var(--color-background-subtle)] px-2 py-0.5 rounded">
                                                        COD
                                                    </span>
                                                )}
                                            </div>
                                        }
                                        icon="currency-dollar"
                                    />
                                    {selectedOrder.is_cod && selectedOrder.payment_status === 'unpaid' && (
                                        <div className="mt-2 p-3 bg-amber-50 border border-amber-200 rounded text-sm">
                                            <p className="text-amber-900 font-medium">COD Order - Payment Pending</p>
                                            <p className="text-amber-700 text-xs mt-1">
                                                Mark as paid once courier settles the cash collected on delivery.
                                            </p>
                                        </div>
                                    )}
                                    <DrawerField
                                        label="Channel"
                                        value={channelLabels[selectedOrder.channel]}
                                        icon="storefront"
                                    />
                                </DrawerSection>

                                <DrawerSection title="Customer">
                                    <DrawerField
                                        label="Name"
                                        value={selectedOrder.customer?.name ?? 'Walk-in'}
                                        icon="user"
                                    />
                                    <DrawerField
                                        label="Email"
                                        value={selectedOrder.customer?.email ?? '—'}
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
                                        value={
                                            <span className="font-semibold text-lg">
                                                {selectedOrder.source_symbol}
                                                {selectedOrder.total_native.toLocaleString(undefined, {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2,
                                                })}
                                                {selectedOrder.is_converted && (
                                                    <span className="ml-2 text-sm font-normal text-[var(--color-text-muted)]">
                                                        ≈ {formatMoney(selectedOrder.total)}
                                                    </span>
                                                )}
                                            </span>
                                        }
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

            {/* Dispatch Modal */}
            <QuickCreateModal
                open={showDispatchModal}
                onClose={() => {
                    setShowDispatchModal(false);
                    setDispatchOrderId(null);
                    setDispatchAmount('');
                    setSelectedCourier('');
                }}
                title="Dispatch Order to Courier"
                onSubmit={handleConfirmDispatch}
                submitLabel="Dispatch"
                submitting={dispatchOrder.isPending}
            >
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Courier <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <select
                            value={selectedCourier}
                            onChange={(e) => setSelectedCourier(e.target.value)}
                            className="field"
                            required
                            disabled={dispatchOrder.isPending}
                        >
                            <option value="">Select courier…</option>
                            {couriers.map(courier => (
                                <option key={courier.id} value={courier.id}>
                                    {courier.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Amount
                            <span className="ml-1 text-xs font-normal text-[var(--color-text-muted)]">
                                (optional)
                            </span>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            value={dispatchAmount}
                            onChange={(e) => setDispatchAmount(e.target.value)}
                            className="field"
                            placeholder="Leave empty to use order total"
                            disabled={dispatchOrder.isPending}
                        />
                        <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                            This amount will be used for COD settlement and transaction records. If left empty, the order total will be used.
                        </p>
                    </div>
                </div>
            </QuickCreateModal>

            {/* Import Modal */}
            <ImportModal
                isOpen={showImportModal}
                onClose={() => setShowImportModal(false)}
                onImport={handleImport}
                title="Import Orders"
                description="Upload a file containing orders to import them into your system"
                entityType="orders"
            />
        </div>
    );
}
