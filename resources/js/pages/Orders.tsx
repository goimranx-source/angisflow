import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';

import {
    FilterBar,
    FilterSelect,
    ViewToggleButton,
    DetailDrawer,
    StatusBadge,
    BulkActions,
    SelectCheckbox,
    QuickCreateModal,
    QuickActionButton,
    KPICardSkeleton,
    KPICard,
    ImportModal,
} from '@/components/modules';
import { DateRangePicker, type DateRange } from '@/components/ui/DateRangePicker';
import { EmptyState } from '@/components/ui/EmptyState';
import { useMoney } from '@/hooks/useMoney';
import { RowAction, RowActionMenu, RowActions } from '@/components/modules/RowActions';
import { BulkActionsMenu, type BulkActionGroup } from '@/components/modules/BulkActionsMenu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { OrderHistory } from '@/pages/orders/OrderHistory';
import { OrderEditor } from './orders/OrderEditor';
import {
    downloadCsv,
    orderImportCsv,
    printOrderDocuments,
    type DocumentKind,
} from './orders/documents';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useFlyoutPosition } from '@/hooks/useFlyoutPosition';
import { api } from '@/lib/api';
import { compactCount } from '@/lib/money';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { confirm } from '@/lib/confirm';

type Order = {
    id: string;
    order_number: string;
    /** This business's own, unique across every shop and the counter. */
    reference: string | null;
    /** When it reached the customer, or null while it is still out. */
    delivered_on: string | null;
    /** Short tag for the shop it came through — 'VB', or 'WALK' for the counter. */
    store_code: string | null;
    /**
     * Set only when this order's changes have not reached the shop.
     *
     * Null is the ordinary case, so a row shows nothing rather than a tick that
     * would be noise everywhere except the one place it matters.
     */
    unsent: { since: string; error: string | null } | null;
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
    /** A fortnight of daily figures, and last week against the one before. */
    trends: {
        orders: number[];
        revenue: number[];
        /** Of each day's orders, how many are still being worked on. */
        processing: number[];
        delta: { orders: number | null; revenue: number | null };
    };
    summary: {
        total_orders: number;
        total_revenue: number;
        avg_order_value: number;
        processing_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

/**
 * How an order is named wherever it is named in full.
 *
 * ── Why the shop's number is not shown bare ──────────────────────────────────
 *
 * "(9586)" is a number from somebody's sequence and does not say whose. A
 * business selling through two shops has two orders numbered 1043, and the
 * bracket alone cannot tell them apart — which defeats the point of showing it,
 * since the reason it is there is so somebody can quote it back to the right
 * shop.
 *
 * "(VB-9586)" says which sequence. It is the same tag the Store column uses, so
 * the two read as one fact rather than as two facts about numbers.
 *
 * A counter sale has no shop and no second number, so it is just the reference.
 */
function orderName(order: Order): string {
    const own = order.reference ?? order.order_number;

    if (!order.reference || !order.store) {
        return `#${own}`;
    }

    const tag = order.store_code ?? order.store.name;

    return `#${own} (${tag}-${order.order_number})`;
}

/**
 * An order's reference, set as an identifier rather than as prose.
 *
 * ── Why it is not just the string ────────────────────────────────────────────
 *
 * `SO-2026-0022` on its own reads as a code somebody has to decide how to
 * interpret. Three things make it read as a reference instead, and they are
 * what every invoice and every order system in the world already does:
 *
 * The hash says "this is a number for this thing", so nobody has to work out
 * whether SO is a customer or a status. Same weight and colour as the rest of
 * it: a greyed hash beside a black reference reads as two things sharing a
 * cell rather than one identifier, which is the opposite of what it is for.
 *
 * Tabular figures, so the digits sit in fixed columns and a list of references
 * lines up down the page instead of ragging like ordinary text.
 *
 * And the whole thing on one line, because a reference that wraps is a
 * reference somebody reads back wrong over the phone.
 */
function OrderRef({ value }: { value: string }) {
    return (
        <span className="whitespace-nowrap font-semibold tabular-nums text-[var(--color-text-main)]">
            #{value}
        </span>
    );
}

/**
 * Which page numbers to draw, with gaps where there are too many.
 *
 * ── Why not all of them ──────────────────────────────────────────────────────
 *
 * Forty pages is forty targets in a row nobody can aim at, and it pushes the
 * first and last off the end of the bar — which are the two anybody actually
 * wants. Kept: the first, the last, the current, and one either side of it. The
 * stretches between become a gap.
 *
 * Below eight pages nothing is hidden, because a gap standing in for one number
 * is worse than the number.
 */
function pageWindow(page: number, pages: number): Array<number | 'gap'> {
    if (pages <= 7) {
        return Array.from({ length: pages }, (_, i) => i + 1);
    }

    const around = [page - 1, page, page + 1].filter((n) => n > 1 && n < pages);
    const shown = [1, ...around, pages];

    const out: Array<number | 'gap'> = [];

    for (const n of shown) {
        const last = out[out.length - 1];

        // A gap only where something is actually missing. Between 3 and 5 the
        // gap would be standing in for a single page, so 4 is drawn instead.
        if (typeof last === 'number' && n - last === 2) {
            out.push(last + 1);
        } else if (typeof last === 'number' && n - last > 2) {
            out.push('gap');
        }

        out.push(n);
    }

    return out;
}

/**
 * The pages, and a way to jump to one that is not on the bar.
 */
function Pager({
    page,
    pages,
    onGo,
}: {
    page: number;
    pages: number;
    onGo: (page: number) => void;
}) {
    const [typed, setTyped] = useState('');

    const wanted = Number(typed);

    /*
     * Locked until the number is one of the pages.
     *
     * ── Why locked rather than clamped or warned ─────────────────────────────
     *
     * Clamping puts somebody who typed 500 onto page 12 and lets them believe
     * that is where 500 was. A message under the box is read after the press,
     * which is one press too late.
     *
     * A button that will not go is the earliest possible answer: the refusal
     * happens while the number is still being typed, so a wrong one is
     * corrected before it is ever submitted. What the button cannot do is say
     * why, so it says so on its own tooltip.
     */
    const reachable =
        typed !== '' && Number.isInteger(wanted) && wanted >= 1 && wanted <= pages;

    const jump = () => {
        if (!reachable) {
            return;
        }

        onGo(wanted);
        setTyped('');
    };

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <PageStep
                icon="caret-left"
                label="Previous page"
                disabled={page === 1}
                onClick={() => onGo(Math.max(1, page - 1))}
            />

            {pageWindow(page, pages).map((slot, i) =>
                slot === 'gap' ? (
                    <span
                        key={`gap-${i}`}
                        className="px-1 text-[var(--color-text-subtle)]"
                        aria-hidden="true"
                    >
                        …
                    </span>
                ) : (
                    <button
                        key={slot}
                        type="button"
                        onClick={() => onGo(slot)}
                        aria-current={slot === page ? 'page' : undefined}
                        className={cn(
                            'h-7 min-w-7 rounded-[var(--shell-radius-sm)] border px-2 text-sm tabular-nums transition-colors',
                            slot === page
                                ? 'border-transparent bg-[var(--color-brand)] font-semibold text-[var(--color-text-on-accent)]'
                                : 'text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
                        )}
                        style={
                            slot === page ? undefined : { borderColor: 'var(--shell-border)' }
                        }
                    >
                        {slot}
                    </button>
                ),
            )}

            <PageStep
                icon="caret-right"
                label="Next page"
                disabled={page === pages}
                onClick={() => onGo(Math.min(pages, page + 1))}
            />

            {/*
              For the page that is not on the bar.

              With the middle collapsed, most pages are not reachable by
              pressing a number — and stepping to page 30 one press at a time is
              not a way to get anywhere.
            */}
            <span className="ml-1 flex items-center gap-1.5">
                <input
                    value={typed}
                    onChange={(event) => setTyped(event.target.value.replace(/\D/g, ''))}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            jump();
                        }
                    }}
                    className="field h-7 w-14 px-2 py-0 text-center text-xs tabular-nums"
                    placeholder="Page"
                    aria-label={`Go to a page, 1 to ${pages}`}
                    inputMode="numeric"
                />
                <button
                    type="button"
                    onClick={jump}
                    disabled={!reachable}
                    title={`Go to a page from 1 to ${pages}`}
                    className="btn btn-secondary !h-7 !px-2.5 !text-xs"
                >
                    Go
                </button>
            </span>
        </div>
    );
}

/**
 * One step of the pager.
 *
 * Its own component because there are four of them and they differ only by
 * which way they point — written out four times, the disabled styling drifts
 * on whichever one somebody last touched.
 */
function PageStep({
    icon,
    label,
    disabled,
    onClick,
}: {
    icon: string;
    label: string;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={label}
            aria-label={label}
            className="flex h-7 w-7 items-center justify-center rounded-[var(--shell-radius-sm)] border text-[var(--color-text-body)] transition-colors hover:bg-[var(--shell-hover)] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent"
            style={{ borderColor: 'var(--shell-border)' }}
        >
            <Icon name={icon} size={13} />
        </button>
    );
}

export default function Orders() {
    useDocumentTitle('Orders');
    const queryClient = useQueryClient();

    // State
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [paymentFilter, setPaymentFilter] = useState('');
    const [storeFilter, setStoreFilter] = useState('');
    /*
     * Which tab is open lives in the address, not in component state.
     *
     * ── Why the URL rather than useState ─────────────────────────────────────
     *
     * Because state does not survive a reload, and the tab was the one thing
     * the address never said. Somebody working through the trash was thrown
     * back to All Orders on every refresh — and worse, silently, so the next
     * thing they did was to a different set of orders than the one they
     * thought they were looking at.
     *
     * In the address it also survives the back button and a link pasted to
     * somebody else, which state in a component can never do.
     *
     * 'all' is written as the absence of the parameter rather than ?tab=all, so
     * the default view has one address instead of two.
     */
    const [params, setParams] = useSearchParams();

    const requested = params.get('tab');
    const tab: 'all' | 'trashed' | 'archived' =
        requested === 'archived' || requested === 'trashed' ? requested : 'all';

    const setTab = (next: 'all' | 'trashed' | 'archived') => {
        setParams(
            (current) => {
                const updated = new URLSearchParams(current);

                next === 'all' ? updated.delete('tab') : updated.set('tab', next);

                return updated;
            },
            // Replaced rather than pushed: a tab is a view of one page, and
            // stacking every glance at the trash into history makes Back mean
            // something different from "the page I came from".
            { replace: true },
        );
    };

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

    /*
     * The filters, behind a button.
     *
     * Three selects sat on the toolbar at all times, each costing the width of
     * its widest option for a choice made once a session — and the row read as
     * five controls of equal weight when one of them is the search.
     */
    const [filtersOpen, setFiltersOpen] = useState(false);
    const filterButton = useRef<HTMLButtonElement>(null);
    const filterPanel = useRef<HTMLDivElement>(null);

    const filterAt = useFlyoutPosition({
        open: filtersOpen,
        trigger: filterButton,
        panel: filterPanel,
    });
    const [selectedOrders, setSelectedOrders] = useState<string[]>([]);
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');

    /*
     * Whether the drawer is showing the fields that can be changed.
     *
     * Off by default: an order is read far more often than it is edited, and a
     * screen full of dropdowns invites a change nobody came to make.
     */
    /*
     * The order open in the full editor, if any.
     *
     * Held by id rather than by object: the editor fetches its own copy, so
     * what it shows is the order as it is now rather than as the list last saw
     * it.
     */
    const [editingId, setEditingId] = useState<string | null>(null);

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
    const trends = data?.trends;

    /*
     * The average basket, day by day.
     *
     * Not sent by the endpoint, because it is not a figure the database holds:
     * it is one series divided by another, and dividing them here costs nothing
     * and keeps the payload to facts.
     *
     * A day with no orders has no average -- not an average of zero. The last
     * known figure is carried forward, because the typical basket did not
     * become nothing on a quiet Sunday; nobody bought anything, which is what
     * the orders card is there to say.
     */
    const avgSeries = (() => {
        if (!trends) {
            return undefined;
        }

        let carried = 0;

        return trends.orders.map((count, day) => {
            if (count > 0) {
                carried = (trends.revenue[day] ?? 0) / count;
            }

            return Math.round(carried * 100) / 100;
        });
    })();
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
            /*
             * Read defensively, because a 2xx is not a promise about the body.
             *
             * ── Why this is not paranoia ─────────────────────────────────────
             *
             * A response can arrive successful and empty: a proxy truncating
             * it, or — as happened here — the server dying in its after-response
             * work before the body was flushed. Reading result.data.failed
             * straight off then throws "Cannot read properties of null", and
             * what the person sees is a crash rather than the change they made,
             * which did in fact happen.
             *
             * The orders are re-fetched below regardless, so the screen tells
             * the truth even when the reply did not.
             */
            const data = (result ?? null) as {
                message?: string;
                data?: { updated?: number; failed?: number; batch_id?: string | null; pushes?: number };
            } | null;

            const updated = data?.data?.updated ?? 0;
            const failed = data?.data?.failed ?? 0;
            const message = data?.message ?? 'Orders updated.';

            if (failed > 0 && updated === 0) {
                toast.error(message);
            } else if (failed > 0) {
                toast.warning(message);
            } else {
                toast.success(message);
            }

            /*
             * The toast reports the local change, which is finished. Reaching
             * the shops is not, and is the part that takes minutes on a large
             * selection — so it gets its own line that stays until it is
             * genuinely done. Without a batch there is nothing to follow, which
             * is the case when no shop is connected.
             */
            /*
             * Nudged rather than told.
             *
             * The card that reports shop-side progress lives in the layout and
             * asks the server what is running, so it needs no batch id from
             * here — only a reason to look now instead of on its next slow
             * poll. That is what makes the progress survive a reload: nothing
             * about it is held in this page.
             */
            if (data?.data?.batch_id) {
                void queryClient.invalidateQueries({ queryKey: ['pushes', 'active'] });
            }

            setSelectedOrders([]);
            setBulkAction('');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Bulk update failed.'),
    });

    /*
     * Send one order to its shop again.
     *
     * Only ever reached from the warning on a row the shop refused — the timed
     * sweep handles everything that merely never ran, and asking twice for the
     * same in-flight change would push it twice.
     */
    const retryPush = useMutation({
        mutationFn: (orderId: string) => api.post(`/orders/${orderId}/retry-push`, {}),
        onSuccess: () => {
            toast.success('Sending this order to the shop again.');
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Could not send this order again.'),
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
            // Same guard as the bulk update above: a 2xx is not a promise
            // about the body, and a crash here would hide work that happened.
            const data = (result ?? null) as { message?: string; data?: { dispatched?: number; failed?: number } } | null;
            const dispatched = data?.data?.dispatched ?? 0;
            const failed = data?.data?.failed ?? 0;
            const message = data?.message ?? 'Orders dispatched.';

            if (failed > 0 && dispatched === 0) {
                toast.error(message);
            } else if (failed > 0) {
                toast.warning(message);
            } else {
                toast.success(message);
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

    const handleApplyBulkAction = async () => {
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
                if (!await confirm(`Cancel ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'}?`)) return;
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'cancel' });
                break;
            case 'trash':
                if (!await confirm(`Move ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'} to trash?`)) return;
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'trash' });
                break;
            case 'restore':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'restore' });
                break;
            case 'unarchive':
                bulkUpdate.mutate({ order_ids: selectedOrders, action: 'unarchive' });
                break;
            case 'delete_permanently':
                if (!await confirm(`⚠️ PERMANENTLY DELETE ${selectedOrders.length} order${selectedOrders.length === 1 ? '' : 's'}?\n\nThis action CANNOT be undone!\n\nThe order data will be completely removed from the database.`)) return;
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
    const { format: formatMoney, both: money } = useMoney();

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
                    /*
                     * No description, as on every other list.
                     *
                     * "Manage customer orders and fulfillment" told somebody
                     * looking at their own orders what an order is. A line read
                     * once, by somebody who did not need it, costs every later
                     * visit a little height.
                     */
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

            {/*
              Four shapes while the figures are on their way.

              They appeared only once the data had landed, so the row above the
              table went from nothing to four cards and pushed everything under
              it down the page -- the one thing on this screen that moved after
              it had finished loading.
            */}
            {!summary && isLoading && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                </div>
            )}

            {summary && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Orders"
                        value={compactCount(summary.total_orders)}
                        valueTitle={summary.total_orders.toLocaleString()}
                        icon="shopping-cart"
                        variant="brand"
                        spark={trends?.orders}
                    />
                    {/*
                      Compacted, with the figure itself on the hover.

                      A card is a glance, and the exact amount was not being
                      read at one -- it was being truncated to "৳75,81…", which
                      is not a number at all. The precise figure is a hover
                      away rather than gone.
                    */}
                    <KPICard
                        label="Total Revenue"
                        value={money(summary.total_revenue).short}
                        valueTitle={money(summary.total_revenue).exact}
                        icon="currency-dollar"
                        variant="success"
                        spark={trends?.revenue}
                    />
                    <KPICard
                        label="Avg Order Value"
                        value={money(summary.avg_order_value).short}
                        valueTitle={money(summary.avg_order_value).exact}
                        icon="chart-line"
                        variant="info"
                        /*
                          Its own series, worked out here rather than sent.
                          
                          The average is revenue over orders, so a day with no
                          orders has no average -- not a zero. Carried forward
                          from the last day that had one, because the typical
                          basket did not become nothing on a quiet Sunday.
                        */
                        spark={avgSeries}
                    />
                    <KPICard
                        label="In Processing"
                        value={compactCount(summary.processing_count)}
                        valueTitle={summary.processing_count.toLocaleString()}
                        icon="spinner-gap"
                        variant="warning"
                        /*
                          Which days' work is still open, rather than a queue
                          length per day -- an order carries only the status it
                          has now, so nothing here knows what was in processing
                          three weeks ago. This points at the days that are
                          stuck, which is the more useful of the two on a card
                          counting open work.
                        */
                        spark={trends?.processing}
                    />
                </div>
            )}

            {/* Tabs */}
            {/*
              The page's own tabs, in the shared component.

              These were fifty lines of hand-rolled buttons carrying their own
              copy of the underline styling, which is how the application ended
              up with three different tab designs on screen at once — this one,
              the drawer's, and the storefront's, none of them agreeing.
            */}
            <Tabs
                defaultValue="all"
                value={tab}
                onValueChange={(next) => {
                    setTab(next as 'all' | 'trashed' | 'archived');
                    setPage(1);

                    // A selection made in one tab means nothing in another —
                    // the rows it referred to are not on screen any more, and a
                    // bulk action would act on records nobody can see.
                    setSelectedOrders([]);
                }}
                className="mt-4"
            >
                <TabsList>
                    <TabsTrigger value="all">All orders</TabsTrigger>
                    <TabsTrigger value="archived" icon="archive">
                        Archived
                    </TabsTrigger>
                    <TabsTrigger value="trashed" icon="trash">
                        Trash
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            {/*
              -- The way in, and the list, as one thing ----------------------

              They were two cards with a strip of page between them: a border
              and a gap drawn between a search box and the rows it searches.
              One card, divided rather than separated, the same as the
              storefront list.
            */}
            <div className="card mt-4 flex min-h-0 flex-1 flex-col">
                <FilterBar
                    compact
                    className="!rounded-none !border-0 !border-b"
                    searchValue={search}
                    onSearchChange={handleSearchChange}
                    searchPlaceholder="Search by order #, customer or email"
                    trailingSearch={
                        /* The period sits with the search: both narrow what is
                           being looked at rather than how it is shown. */
                        <DateRangePicker value={range} onChange={handleRangeChange} />
                    }
                    actions={
                        <>
                            <div className="relative">
                                <button
                                    ref={filterButton}
                                    type="button"
                                    onClick={() => setFiltersOpen(!filtersOpen)}
                                    className={cn(
                                        'btn btn-secondary hover:!border-[var(--color-brand)] hover:!bg-[var(--color-brand-hover)] hover:!text-[var(--color-text-on-accent)]',

                                        // Filters on: the button wears the colour
                                        // it takes on hover and keeps it, which
                                        // reads from across the table.
                                        hasFilters &&
                                            '!border-[var(--color-brand)] !bg-[var(--color-brand-hover)] !text-[var(--color-text-on-accent)]',
                                    )}
                                    aria-expanded={filtersOpen}
                                    aria-haspopup="dialog"
                                >
                                    <Icon name="funnel" size={14} weight="duotone" />
                                    <span>Filter</span>
                                    <Icon
                                        name="caret-down"
                                        size={12}
                                        className={cn(
                                            'transition-transform',
                                            filtersOpen && 'rotate-180',
                                        )}
                                    />
                                </button>

                                {filtersOpen &&
                                    createPortal(
                                        <>
                                            <FlyoutGuard onClose={() => setFiltersOpen(false)} />

                                            <div
                                                ref={filterPanel}
                                                data-flyout-panel
                                                className="fixed z-[var(--z-flyout-panel)] w-64 space-y-3 overflow-y-auto rounded-[var(--shell-radius)] border bg-[var(--color-card-bg)] p-3 shadow-lg"
                                                style={{
                                                    borderColor: 'var(--shell-border)',
                                                    animation:
                                                        'context-flyout-slide-up 120ms ease-out',
                                                    transformOrigin:
                                                        filterAt?.side === 'above'
                                                            ? 'bottom right'
                                                            : 'top right',
                                                    visibility: filterAt ? 'visible' : 'hidden',
                                                    top: filterAt?.top ?? 0,
                                                    left: filterAt?.left ?? 0,
                                                    maxHeight: filterAt?.maxHeight,
                                                }}
                                            >
                                                <FilterSelect
                                                    block
                                                    label="Status"
                                                    value={statusFilter}
                                                    onChange={handleStatusFilterChange}
                                                    /*
                                                     * Every status this business has
                                                     * configured -- the built-in ones and
                                                     * anything added during mapping, so
                                                     * "Awaiting parts" appears under the
                                                     * name it was given.
                                                     */
                                                    options={allStatuses.map((status) => ({
                                                        value: status.value,
                                                        label:
                                                            status.label +
                                                            (status.custom ? ' (Custom)' : ''),
                                                    }))}
                                                    placeholder="All statuses"
                                                />
                                                <FilterSelect
                                                    block
                                                    label="Payment"
                                                    value={paymentFilter}
                                                    onChange={handlePaymentFilterChange}
                                                    options={[
                                                        { value: 'paid', label: 'Paid' },
                                                        { value: 'unpaid', label: 'Unpaid' },
                                                    ]}
                                                    placeholder="All payments"
                                                />

                                                {/* Which shop, or the counter. Walk-in is
                                                    always offered -- every business has a
                                                    counter before it has a website. */}
                                                <FilterSelect
                                                    block
                                                    label="Store"
                                                    value={storeFilter}
                                                    onChange={handleStoreFilterChange}
                                                    options={[
                                                        {
                                                            value: 'walk_in',
                                                            label: 'Walk-in / counter',
                                                        },
                                                        ...stores.map((store) => ({
                                                            value: store.id,
                                                            label: store.name,
                                                        })),
                                                    ]}
                                                    placeholder="All stores"
                                                />

                                                {hasFilters && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            handleClearFilters();
                                                            setFiltersOpen(false);
                                                        }}
                                                        className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                                    >
                                                        <Icon name="x" size={14} />
                                                        <span>Clear filters</span>
                                                    </button>
                                                )}
                                            </div>
                                        </>,
                                        document.body,
                                    )}
                            </div>

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

                            {/* Asking the application what it already knows.
                                Kept at the end of the row, away from anything
                                that changes an order. */}
                            <button
                                type="button"
                                onClick={() => void refetch()}
                                className="btn btn-secondary px-2"
                                title="Refresh this list"
                                aria-label="Refresh this list"
                                disabled={isLoading}
                            >
                                <Icon
                                    name="arrow-clockwise"
                                    size={14}
                                    className={isLoading ? 'animate-spin' : undefined}
                                />
                            </button>
                        </>
                    }
                />


            {/* Content */}
            <div className="flex min-h-0 flex-1 flex-col">
                {isError ? (
                    <div className="p-6 text-center">
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
                    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
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
                                        /*
                                          Narrow, and with nothing to its right.
                                          
                                          A 48px column plus 16px of padding on
                                          each side put four times the checkbox's
                                          own width between it and the reference
                                          it selects — so the box read as
                                          belonging to the table's edge rather
                                          than to the row beside it.
                                          
                                          `!pr-0` because the padding is what
                                          most of that gap was, and the width
                                          alone would have closed up half of it.
                                          The Order column keeps its own left
                                          padding, which is the whole of the gap
                                          now.
                                        */
                                        width: 'w-8 !pr-0',
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
                                        width: 'w-40',
                                        /*
                                          This business's own number, on one line.

                                          It used to show the shop's, with the date
                                          under it. The shop's number belongs beside
                                          the shop -- it means nothing without knowing
                                          whose sequence it came from, and two shops
                                          will both have a 1043 eventually. The date
                                          has a column.
                                        */
                                        /*
                                          The reference opens the order, not the
                                          row.

                                          A whole row that answers a click makes
                                          every pixel of it a target, including
                                          the cells somebody is reading a figure
                                          out of, the gaps between controls, and
                                          the courier picker. The reference is
                                          the thing that looks like a way in, so
                                          it is the thing that is one.
                                        */
                                        render: (order) => (
                                            <button
                                                type="button"
                                                onClick={() => setSelectedOrder(order)}
                                                className="text-left transition hover:text-[var(--color-brand)]"
                                            >
                                                <OrderRef
                                                    value={order.reference ?? order.order_number}
                                                />
                                            </button>
                                        ),
                                    },
                                    {
                                        key: 'ordered_on',
                                        label: 'Order Date',
                                        sortable: true,
                                        width: 'w-32',
                                        render: (order) => (
                                            <span className="whitespace-nowrap text-[var(--color-text-body)]">
                                                {formatDate(order.date)}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'delivered_on',
                                        label: 'Delivery Date',
                                        sortable: false,
                                        width: 'w-32',
                                        /*
                                          A hyphen, not a blank.

                                          An empty cell reads as a column that failed
                                          to load. A hyphen says the order has not
                                          arrived yet, which is a fact about the
                                          order rather than about the screen.
                                        */
                                        render: (order) => (
                                            <span
                                                className={
                                                    order.delivered_on
                                                        ? 'whitespace-nowrap text-[var(--color-text-body)]'
                                                        : 'text-[var(--color-text-subtle)]'
                                                }
                                            >
                                                {order.delivered_on
                                                    ? formatDate(order.delivered_on)
                                                    : '—'}
                                            </span>
                                        ),
                                    },
                                    {

                                        key: 'customer',
                                        label: 'Customer',
                                        sortable: false,
                                        width: 'w-64',
                                        /*
                                          One line, with the address on the hover.

                                          The email under the name doubled every row's
                                          height for something read on perhaps one row
                                          in fifty -- and a table where every row is
                                          two lines fits half as many orders on a
                                          screen, which is the thing this page is for.
                                        */
                                        render: (order) => (
                                            <div
                                                className="flex min-w-0 items-center gap-2"
                                                title={
                                                    order.customer?.email ??
                                                    'Counter sale, no customer recorded'
                                                }
                                            >
                                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--shell-muted)] text-[11px] font-bold text-[var(--color-text-muted)]">
                                                    {(order.customer?.name ?? 'W').slice(0, 1).toUpperCase()}
                                                </span>
                                                <p className="truncate font-medium text-[var(--color-text-main)]">
                                                    {order.customer?.name ?? 'Walk-in'}
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
                                        /*
                                          The shop's tag, and the shop's own number
                                          for this order beside it.

                                          The number is only meaningful next to whose
                                          sequence it belongs to, so this is where it
                                          goes -- and it is the number to quote when
                                          ringing them about the order, which is the
                                          whole reason for keeping it.
                                        */
                                        render: (order) =>
                                            order.store ? (
                                                <span
                                                    className="inline-flex items-baseline gap-1.5 whitespace-nowrap text-sm"
                                                    title={order.store.name}
                                                >
                                                    <span className="font-semibold text-[var(--color-text-main)]">
                                                        {order.store_code ?? order.store.name}
                                                    </span>
                                                    <span className="text-[var(--color-text-muted)]">
                                                        ({order.order_number})
                                                    </span>
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-sm text-[var(--color-text-muted)]">
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
                                        /*
                                          ── One line, and a mark when there were two ─────

                                          An order taken in another currency showed the
                                          amount charged and, under it, what it came to in
                                          the books. Both are true and only one of them is
                                          being scanned — and the second line doubled the
                                          height of a handful of rows, so a column of
                                          figures was no longer a column of figures.

                                          The amount charged stays: it is what the customer
                                          paid and what the shop will say if asked. The
                                          converted figure moves to a mark beside it, which
                                          also does the job the second line was quietly
                                          doing — saying that this row is not in the
                                          currency the rest of the column is in.
                                        */
                                        render: (o) => (
                                            <span className="flex items-center justify-end gap-1.5 whitespace-nowrap">
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
                                                        className="inline-flex shrink-0 cursor-help text-[var(--color-text-subtle)]"
                                                        title={`Taken in ${o.source_currency} — ${formatMoney(o.total)} in the books`}
                                                    >
                                                        <Icon name="arrows-left-right" size={12} weight="duotone" />
                                                    </span>
                                                )}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'courier',
                                        label: 'Courier',
                                        width: 'w-56',
                                        render: (order) => (
                                            <div onClick={(e) => e.stopPropagation()}>
                                                {order.dispatch ? (
                                                    /*
                                                      The courier's name, and a mark holding everything else.

                                                      This cell was three stacked lines -- name, then the
                                                      charge and the shipment's state, then a red "Cancel
                                                      shipment" link -- which made every dispatched row three
                                                      times the height of an undispatched one, in a table
                                                      whose whole job is to be scanned. The charge and the
                                                      state are worth having and are not worth a line each on
                                                      every row; they are behind the mark. The cancel is an
                                                      action, so it is with the other actions.
                                                    */
                                                    <span className="flex min-w-0 items-center gap-1.5">
                                                        <span className="truncate text-sm font-medium text-[var(--color-text-body)]">
                                                            {order.dispatch.courier.label ?? 'Courier'}
                                                        </span>

                                                        {/* The title goes on the wrapper: Icon draws an
                                                            <svg> and takes no title of its own, and a
                                                            title attribute React cannot place is a
                                                            tooltip that never appears. */}
                                                        <span
                                                            className="inline-flex shrink-0 cursor-help text-[var(--color-text-subtle)]"
                                                            title={[
                                                                `${order.source_symbol}${order.dispatch.amount.toLocaleString(undefined, {
                                                                    minimumFractionDigits: 2,
                                                                    maximumFractionDigits: 2,
                                                                })} — ${dispatchStatusLabels[order.dispatch.status] ?? order.dispatch.status}`,
                                                                order.dispatch.tracking_number
                                                                    ? `Tracking ${order.dispatch.tracking_number}`
                                                                    : `Shipment ${order.dispatch.shipment_number}`,
                                                            ].join(String.fromCharCode(10))}
                                                        >
                                                            <Icon name="info" size={13} weight="duotone" />
                                                        </span>
                                                    </span>
                                                ) : tab !== 'archived' && tab !== 'trashed' && couriers.length > 0 ? (
                                                    <select
                                                        className="field text-xs"
                                                        style={{ width: '100%', minWidth: '130px' }}
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
                                        label: 'Action',
                                        width: 'w-20',
                                        /*
                                          ── One mark, not five ─────────────────

                                          Five icon buttons cost the width of
                                          five buttons on every row, in the
                                          column a scrolling table pushes off
                                          the edge first -- and none of them
                                          said what it did until it was
                                          hovered, so the row ended in a
                                          puzzle.

                                          Behind one mark they are words. It is
                                          the same menu the storefront list
                                          uses, so a row ends the same way
                                          wherever somebody is.
                                        */
                                        render: (order) => (
                                            <RowActions>
                                                <RowActionMenu
                                                    icon="dots-three-vertical"
                                                    label={`Actions for ${order.reference ?? order.order_number}`}
                                                    items={
                                                        tab === 'trashed'
                                                            ? [
                                                                  {
                                                                      key: 'edit',
                                                                      label: 'Edit',
                                                                      icon: 'note-pencil',
                                                                      onSelect: () =>
                                                                          setEditingId(order.id),
                                                                  },
                                                                  {
                                                                      key: 'restore',
                                                                      label: 'Restore',
                                                                      icon: 'arrow-counter-clockwise',
                                                                      onSelect: () =>
                                                                          bulkUpdate.mutate({
                                                                              order_ids: [order.id],
                                                                              action: 'restore',
                                                                          }),
                                                                  },
                                                                  {
                                                                      key: 'destroy',
                                                                      label: 'Delete for good',
                                                                      icon: 'trash',
                                                                      variant: 'danger' as const,
                                                                      onSelect: async () => {
                                                                          const sure = await confirm(
                                                                              `Permanently delete ${order.reference ?? order.order_number}?`,
                                                                              'It is removed from the database. This cannot be undone.',
                                                                          );

                                                                          if (sure) {
                                                                              bulkUpdate.mutate({
                                                                                  order_ids: [order.id],
                                                                                  action: 'delete_permanently',
                                                                              });
                                                                          }
                                                                      },
                                                                  },
                                                              ]
                                                            : [
                                                                  {
                                                                      key: 'preview',
                                                                      label: 'Preview',
                                                                      icon: 'eye',
                                                                      onSelect: () =>
                                                                          setSelectedOrder(order),
                                                                  },
                                                                  {
                                                                      key: 'edit',
                                                                      label: 'Edit',
                                                                      icon: 'note-pencil',
                                                                      onSelect: () =>
                                                                          setEditingId(order.id),
                                                                  },
                                                                  {
                                                                      key: 'invoice',
                                                                      label: 'Print invoice',
                                                                      icon: 'receipt',
                                                                      onSelect: () =>
                                                                          printDocuments([order], 'invoice'),
                                                                  },
                                                                  {
                                                                      key: 'receipt',
                                                                      label: 'Print receipt',
                                                                      icon: 'ticket',
                                                                      onSelect: () =>
                                                                          printDocuments([order], 'receipt'),
                                                                  },
                                                                  {
                                                                      key: 'export',
                                                                      label: 'Export as CSV',
                                                                      icon: 'download-simple',
                                                                      onSelect: () => exportOrders([order]),
                                                                  },

                                                                  /*
                                                                    Only while there is a shipment to
                                                                    call off. It used to be a red link
                                                                    in the courier cell, which made an
                                                                    action look like part of a reading
                                                                    -- and gave that column a third
                                                                    line on every dispatched row.
                                                                  */
                                                                  ...(order.dispatch &&
                                                                  !['delivered', 'cancelled', 'returned'].includes(
                                                                      order.dispatch.status,
                                                                  )
                                                                      ? [
                                                                            {
                                                                                key: 'cancel-shipment',
                                                                                label: 'Cancel shipment',
                                                                                icon: 'x-circle',
                                                                                onSelect: async () => {
                                                                                    const sure = await confirm(
                                                                                        `Cancel the shipment for ${order.reference ?? order.order_number}?`,
                                                                                        'The courier is told to stop. The order itself stays.',
                                                                                    );

                                                                                    if (sure) {
                                                                                        cancelDispatch.mutate(order.id);
                                                                                    }
                                                                                },
                                                                            },
                                                                        ]
                                                                      : []),
                                                                  {
                                                                      key: 'trash',
                                                                      label: 'Move to trash',
                                                                      icon: 'trash',
                                                                      variant: 'danger' as const,
                                                                      onSelect: async () => {
                                                                          const sure = await confirm(
                                                                              `Move ${order.reference ?? order.order_number} to trash?`,
                                                                              'It leaves the list and can be brought back from Trash.',
                                                                          );

                                                                          if (sure) {
                                                                              bulkUpdate.mutate({
                                                                                  order_ids: [order.id],
                                                                                  action: 'trash',
                                                                              });
                                                                          }
                                                                      },
                                                                  },
                                                              ]
                                                    }
                                                />
                                            </RowActions>
                                        ),
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
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

                        {/*
                          ── One footer, two jobs ────────────────────────────

                          These were two stacked bars, each with its own top
                          rule: a pagination strip, and beneath it a strip
                          holding nothing but the select-all box. Two rules and
                          two rows of padding for one line of content.

                          They belong on one line because they answer the two
                          questions asked at the bottom of a list: what have I
                          picked, and where am I in it.
                        */}
                        {!isLoading && orders.length > 0 && (
                            <div
                                className="flex flex-col gap-3 border-t px-4 py-2.5 lg:flex-row lg:items-center lg:justify-between"
                                style={{ borderColor: 'var(--shell-border)' }}
                            >
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-[var(--color-text-muted)]">
                                    {/*
                                      The box, and no words beside it.

                                      "Select all 21" spelled out what a
                                      checkbox at the foot of a table already
                                      means, in the one place on the row where
                                      there is something else to say. The count
                                      still appears — but only once something is
                                      picked, when it is a fact rather than an
                                      instruction.
                                    */}
                                    {view === 'list' && (
                                        <SelectCheckbox
                                            checked={isAllSelected}
                                            indeterminate={isSomeSelected}
                                            onChange={handleSelectAll}
                                            label={
                                                selectedOrders.length > 0
                                                    ? `${selectedOrders.length} selected`
                                                    : undefined
                                            }
                                        />
                                    )}

                                    {meta && (
                                        <>
                                            {/*
                                              The label and its control as one
                                              thing, at the gap the checkbox
                                              beside them already uses.

                                              The row's own gap was between all
                                              of them equally, so "Page size:"
                                              stood as far from its select as
                                              the select stood from the count —
                                              three items of equal weight, where
                                              there are two things and one of
                                              them is a pair.
                                            */}
                                            <span className="flex items-center gap-1.5">
                                                <span className="whitespace-nowrap">
                                                    Page size:
                                                </span>

                                                <select
                                                /* w-auto against .field's width:100%. Inside a
                                                   sentence a control has to be the width of its
                                                   own text, or it takes the line. */
                                                    /* pr-6, not pr-7: .field's
                                                       right padding is sized for
                                                       a full-height control, and
                                                       on a 28px one it left the
                                                       caret adrift from the
                                                       number it belongs to. */
                                                    className="field h-7 w-auto py-0 pl-2 pr-6 text-xs"
                                                value={perPage}
                                                onChange={(event) => {
                                                    setPerPage(Number(event.target.value));

                                                    // Page 7 of a 25-row list is
                                                    // past the end of a 100-row one.
                                                    setPage(1);
                                                }}
                                                    aria-label="Rows per page"
                                                >
                                                    {[10, 20, 25, 50, 100].map((size) => (
                                                        <option key={size} value={size}>
                                                            {size}
                                                        </option>
                                                    ))}
                                                </select>
                                            </span>

                                            <span className="whitespace-nowrap">
                                                <span className="font-medium text-[var(--color-text-main)]">
                                                    {(meta.current_page - 1) * meta.per_page + 1}
                                                </span>{' '}
                                                to{' '}
                                                <span className="font-medium text-[var(--color-text-main)]">
                                                    {Math.min(
                                                        meta.current_page * meta.per_page,
                                                        meta.total,
                                                    )}
                                                </span>{' '}
                                                of{' '}
                                                <span className="font-medium text-[var(--color-text-main)]">
                                                    {meta.total.toLocaleString()}
                                                </span>
                                            </span>
                                        </>
                                    )}
                                </div>

                                {meta && meta.last_page > 1 && (
                                    <Pager
                                        page={meta.current_page}
                                        pages={meta.last_page}
                                        onGo={setPage}
                                    />
                                )}
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
                {(() => {
                    const chosen = selectedRows();
                    const busy = bulkUpdate.isPending || bulkDispatch.isPending;
                    const count = selectedOrders.length;
                    const noun = `${count} order${count === 1 ? '' : 's'}`;

                    /*
                     * Offered only when the selection is one order.
                     *
                     * Editing is the one action with no sensible plural: there
                     * is no single form to open for fifteen orders, and the
                     * fields worth changing across a selection are already the
                     * status and payment groups below.
                     */
                    // ?? null because the index signature is checked: chosen[0] is
                    // Order | undefined, and 'not null' alone would not narrow it.
                    const only = chosen.length === 1 ? (chosen[0] ?? null) : null;

                    const single: BulkActionGroup[] =
                        only !== null
                            ? [
                                  {
                                      /*
                                        The order, named.

                                        "This order" is true of whichever row is
                                        selected and says nothing about which
                                        one — and a menu opened from a bar that
                                        floats over the table is a menu whose
                                        row is often scrolled out of sight. Both
                                        numbers, because this tool's is what the
                                        heading below acts on and the shop's is
                                        what somebody has in another window.
                                      */
                                      /* No heading. The menu's own, at the
                                         top, names the order and stays put
                                         while this list scrolls. */
                                      icon: 'note-pencil',
                                      items: [
                                          {
                                              key: 'edit',
                                              label: 'Open and edit',
                                              icon: 'note-pencil',
                                              description: `Order ${only.order_number}`,
                                              onSelect: () => {
                                                  setEditingId(only.id);
                                                  setSelectedOrders([]);
                                              },
                                          },
                                      ],
                                  },
                              ]
                            : [];

                    const documents: BulkActionGroup = {
                        label: 'Documents',
                        icon: 'file-text',
                        items: [
                            {
                                key: 'export',
                                label: 'Export as CSV',
                                icon: 'download-simple',
                                description: "The importer's own columns, so it round-trips",
                                onSelect: () => exportOrders(chosen),
                            },
                            {
                                key: 'invoice',
                                label: 'Print invoices',
                                icon: 'receipt',
                                description: 'A4, one page per order',
                                onSelect: () => printDocuments(chosen, 'invoice'),
                            },
                            {
                                key: 'receipt',
                                label: 'Print receipts',
                                icon: 'ticket',
                                description: '80mm, for a thermal printer',
                                onSelect: () => printDocuments(chosen, 'receipt'),
                            },
                        ],
                    };

                    const groups: BulkActionGroup[] =
                        tab === 'trashed'
                            ? [
                                  {
                                      label: 'Trash',
                                      icon: 'trash',
                                      items: [
                                          {
                                              key: 'restore',
                                              label: 'Restore to the order book',
                                              icon: 'arrow-counter-clockwise',
                                              onSelect: () =>
                                                  bulkUpdate.mutate({
                                                      order_ids: selectedOrders,
                                                      action: 'restore',
                                                  }),
                                          },
                                          {
                                              key: 'delete',
                                              label: 'Delete permanently',
                                              icon: 'trash',
                                              variant: 'danger',
                                              description: 'Removed from the database, not recoverable',
                                              onSelect: async () => {
                                                  if (
                                                      await confirm(
                                                          `Permanently delete ${noun}? This cannot be undone.`,
                                                      )
                                                  ) {
                                                      bulkUpdate.mutate({
                                                          order_ids: selectedOrders,
                                                          action: 'delete_permanently',
                                                      });
                                                  }
                                              },
                                          },
                                      ],
                                  },
                              ]
                            : [
                                  ...single,

                                  {
                                      label: 'Order status',
                                      /* `circle-notch` is a spinner: an
                                         incomplete ring, which beside a group
                                         of statuses reads as the fallback a
                                         missing icon leaves behind. A flag is
                                         what a state is marked with. */
                                      icon: 'flag',
                                      items: allStatuses.map((status) => ({
                                          key: `status-${status.value}`,
                                          label: `${status.label}${status.custom ? ' (Custom)' : ''}`,
                                          onSelect: () =>
                                              bulkUpdate.mutate({
                                                  order_ids: selectedOrders,
                                                  action: 'update_status',
                                                  status: status.value,
                                              }),
                                      })),
                                  },

                                  /*
                                    ── No payment status here ──────────────────

                                    A payment status set by hand is a claim
                                    about money that no money was moved to
                                    support: mark ten orders paid and the ledger
                                    still says they owe, so the badge and the
                                    books disagree and only one of them is
                                    right.

                                    It follows the payments recorded against an
                                    order and nothing else. Recording a payment
                                    is how it changes, which is also the thing
                                    somebody actually meant to do.
                                  */

                                  /*
                                    ── Nor fulfilment ──────────────────────────

                                    The same argument as the payment status
                                    above it. Whether an order has gone out is a
                                    fact about a shipment, and there is one of
                                    those in the row already -- so setting it by
                                    hand states that ten orders shipped while
                                    the courier column beside them still says
                                    nothing was dispatched.

                                    It follows the dispatch, and dispatching is
                                    in this menu a few lines down.
                                  */

                                  ...(tab !== 'archived' && couriers.length > 0
                                      ? [
                                            {
                                                label: 'Send to courier',
                                                icon: 'truck',
                                                items: couriers.map((courier) => ({
                                                    key: `courier-${courier.id}`,
                                                    label: courier.label,
                                                    /*
                                                      One order is asked about;
                                                      several are not.

                                                      Dispatching from a row
                                                      opens a box for the amount
                                                      to collect, because a COD
                                                      order goes to the courier
                                                      with a figure attached and
                                                      that figure is not always
                                                      the order's total —
                                                      part-paid, a delivery fee
                                                      agreed on the phone. From
                                                      here it went straight out
                                                      at whatever the server
                                                      assumed, so the same
                                                      action did two different
                                                      things depending on which
                                                      control was used.

                                                      For a set there is no one
                                                      figure to ask for, so that
                                                      case keeps the bulk call.
                                                    */
                                                    onSelect: () => {
                                                        if (only !== null) {
                                                            handleInlineDispatch(
                                                                only.id,
                                                                courier.id,
                                                            );

                                                            return;
                                                        }

                                                        bulkDispatch.mutate({
                                                            order_ids: selectedOrders,
                                                            courier_id: courier.id,
                                                        });
                                                    },
                                                })),
                                            },
                                        ]
                                      : []),

                                  documents,

                                  {
                                      /*
                                        No heading. Two verbs that explain
                                        themselves do not need a noun above
                                        them saying they are management.
                                      */
                                      items: [
                                          ...(tab === 'archived'
                                              ? [
                                                    {
                                                        key: 'unarchive',
                                                        label: 'Move back to active',
                                                        icon: 'arrow-u-up-left',
                                                        onSelect: () =>
                                                            bulkUpdate.mutate({
                                                                order_ids: selectedOrders,
                                                                action: 'unarchive',
                                                            }),
                                                    },
                                                ]
                                              : [
                                                    {
                                                        key: 'cancel',
                                                        label: 'Cancel orders',
                                                        icon: 'x-circle',
                                                        onSelect: async () => {
                                                            if (await confirm(`Cancel ${noun}?`)) {
                                                                bulkUpdate.mutate({
                                                                    order_ids: selectedOrders,
                                                                    action: 'cancel',
                                                                });
                                                            }
                                                        },
                                                    },
                                                ]),
                                          {
                                              key: 'trash',
                                              label: 'Move to trash',
                                              icon: 'trash',
                                              variant: 'danger' as const,
                                              /*
                                                No description. The confirmation
                                                that follows says what trashing
                                                means and what can be undone --
                                                saying it on the button as well
                                                puts the reassurance before the
                                                question it reassures about.
                                              */
                                              onSelect: async () => {
                                                  if (await confirm(`Move ${noun} to trash?`)) {
                                                      bulkUpdate.mutate({
                                                          order_ids: selectedOrders,
                                                          action: 'trash',
                                                      });
                                                  }
                                              },
                                          },
                                      ],
                                  },
                              ];

                    return (
                        <BulkActionsMenu
                            label="Actions"
                            groups={groups}
                            /*
                              Named at the top of the menu, and held there.

                              One order gets both numbers — this tool's and the
                              shop's — because that is how it is written
                              everywhere else. Several get the count, which is
                              the only honest thing to say about a set.
                            */
                            heading={only !== null ? orderName(only) : noun}
                            busy={busy}
                        />
                    );
                })()}
            </BulkActions>

            {/*
              The full editor, in a panel wide enough for two columns.

              Its own drawer rather than a mode inside the detail one: reading
              an order and editing it want different widths, and stacking them
              in the same panel meant the read view inherited a form's
              proportions or the form inherited a reading panel's.
            */}
            <DetailDrawer
                open={editingId !== null}
                onClose={() => setEditingId(null)}
                title="Edit order"
                subtitle={editingId ? orders.find((o) => o.id === editingId)?.order_number ?? '' : ''}
                size="2xl"
            >
                {editingId && (
                    <OrderEditor orderId={editingId} onClose={() => setEditingId(null)} />
                )}
            </DetailDrawer>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedOrder}
                onClose={() => setSelectedOrder(null)}
                /*
                  This tool's reference, with the shop's beside it.

                  The panel led with the shop's number, so the one screen that
                  is entirely about one order was the one place not calling it
                  what the rest of the application calls it.
                */
                title={selectedOrder ? orderName(selectedOrder) : ''}
                subtitle={selectedOrder ? `${selectedOrder.customer?.name ?? 'Walk-in'} · ${formatDate(selectedOrder.date)}` : ''}
                tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        /*
                          ── Two columns: what it is, and what state it is in ──

                          Everything was one narrow stack, so a summary nobody
                          needs to re-read sat above the customer, the address
                          and the money on every visit — and on a panel this
                          wide the stack used a third of the width and left the
                          rest blank.

                          Split the way a resource details page is split: what
                          defines the order down the left in two thirds of the
                          width, and the supporting facts — status, totals,
                          which shop — in a rail beside it.

                          The rail is second in the markup and first on a narrow
                          screen, which is where a summary belongs when there is
                          only one column to put it in.
                        */
                        content: selectedOrder && (
                            <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
                                <div className="lg:order-2 lg:col-span-1">
                                {/*
                                  What the order is, before what is in it.

                                  ── Why a card and not more label/value rows ──

                                  Somebody opening an order is nearly always
                                  checking one of three things: what state it is
                                  in, what it came to, and where it came from.
                                  Those were three rows in a list of eight, given
                                  no more weight at a glance than the channel.
                                  Here they are the card, and everything else
                                  sits under it.
                                */}
                                <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                label={statusLabels[selectedOrder.status] ?? selectedOrder.status}
                                                variant={statusVariants[selectedOrder.status] ?? 'neutral'}
                                            />
                                            <StatusBadge
                                                label={paymentLabels[selectedOrder.payment_status] ?? selectedOrder.payment_status}
                                                variant={paymentVariants[selectedOrder.payment_status] ?? 'neutral'}
                                                dot
                                            />
                                            {selectedOrder.is_cod && (
                                                <span className="rounded-full bg-[var(--shell-muted)] px-2 py-0.5 text-[11px] font-semibold tracking-wide text-[var(--color-text-muted)]">
                                                    COD
                                                </span>
                                            )}
                                        </div>

                                        <div className="text-right">
                                            {/* The amount charged, in the money it was charged in. */}
                                            <p className="text-xl font-bold tabular-nums text-[var(--color-text-main)]">
                                                {selectedOrder.source_symbol}
                                                {selectedOrder.total_native.toLocaleString(undefined, {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2,
                                                })}
                                            </p>
                                            {selectedOrder.is_converted && (
                                                <p
                                                    className="mt-0.5 text-xs tabular-nums text-[var(--color-text-muted)]"
                                                    title={`In the books, converted to ${selectedOrder.currency}`}
                                                >
                                                    ≈ {formatMoney(selectedOrder.total)}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-[var(--shell-border)] pt-3 text-xs text-[var(--color-text-muted)]">
                                        <span className="inline-flex items-center gap-1">
                                            <Icon name="storefront" size={13} />
                                            {selectedOrder.store?.name ?? 'Walk-in / counter'}
                                        </span>
                                        <span aria-hidden="true">·</span>
                                        <span className="inline-flex items-center gap-1">
                                            <Icon name="calendar" size={13} />
                                            {formatDate(selectedOrder.date)}
                                        </span>
                                        <span aria-hidden="true">·</span>
                                        <span className="inline-flex items-center gap-1">
                                            <Icon name="package" size={13} />
                                            {selectedOrder.items_count} {selectedOrder.items_count === 1 ? 'item' : 'items'}
                                        </span>
                                        <span aria-hidden="true">·</span>
                                        <span>{channelLabels[selectedOrder.channel]}</span>
                                    </div>
                                </div>
                                </div>

                                {/* ── The primary column ────────────────────
                                    What the order is: who it is for, where it
                                    goes, what was paid, and anything written
                                    about it. */}
                                <div className="space-y-5 lg:order-1 lg:col-span-2">

                                {selectedOrder.is_cod && selectedOrder.payment_status === 'unpaid' && (
                                    <div className="rounded-[var(--shell-radius)] border border-amber-200 bg-amber-50 p-3 text-sm">
                                        <p className="font-medium text-amber-900">Cash on delivery — payment pending</p>
                                        <p className="mt-1 text-xs text-amber-700">
                                            Mark as paid once the courier settles the cash collected on delivery.
                                        </p>
                                    </div>
                                )}

                                {/*
                                  Who and where, side by side.

                                  Four facts that were four full-width rows with
                                  a decorative icon apiece, each costing as much
                                  vertical space as the total. Paired, they read
                                  as what they are — the two ends of one
                                  delivery — and the money below them gets to be
                                  the thing you scroll to.
                                */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <section>
                                        <h4 className="mb-2 text-xs font-semibold text-[var(--color-text-main)]">
                                            Customer
                                        </h4>

                                        {selectedOrder.customer ? (
                                            <>
                                                <p className="text-sm font-medium text-[var(--color-text-main)]">
                                                    {selectedOrder.customer.name}
                                                </p>
                                                {selectedOrder.customer.email ? (
                                                    <a
                                                        href={`mailto:${selectedOrder.customer.email}`}
                                                        className="mt-0.5 block truncate text-sm text-[var(--color-brand)] hover:underline"
                                                        onClick={(event) => event.stopPropagation()}
                                                    >
                                                        {selectedOrder.customer.email}
                                                    </a>
                                                ) : (
                                                    <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">
                                                        No email on file
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                Walk-in — sold at the counter
                                            </p>
                                        )}
                                    </section>

                                    <section>
                                        <h4 className="mb-2 text-xs font-semibold text-[var(--color-text-main)]">
                                            Delivery
                                        </h4>

                                        {(() => {
                                            /*
                                             * Built from the parts that exist.
                                             * As a fixed template this printed
                                             * punctuation for data that was not
                                             * there — a line holding one comma
                                             * when the city and postcode were
                                             * missing.
                                             */
                                            const at = selectedOrder.shipping_address;

                                            if (!at) {
                                                return (
                                                    <p className="text-sm text-[var(--color-text-muted)]">
                                                        No delivery address
                                                    </p>
                                                );
                                            }

                                            const locality = [
                                                [at.city, at.state].filter(Boolean).join(', '),
                                                at.postal_code,
                                            ]
                                                .filter(Boolean)
                                                .join(' ');

                                            const lines = [at.line1, at.line2, locality, at.country]
                                                .map((part) => (part ?? '').trim())
                                                .filter((part) => part !== '');

                                            if (lines.length === 0) {
                                                return (
                                                    <p className="text-sm text-[var(--color-text-muted)]">
                                                        No delivery address
                                                    </p>
                                                );
                                            }

                                            return (
                                                <address className="text-sm not-italic leading-relaxed text-[var(--color-text-body)]">
                                                    {lines.map((line, index) => (
                                                        <span key={index} className="block">
                                                            {line}
                                                        </span>
                                                    ))}
                                                </address>
                                            );
                                        })()}
                                    </section>
                                </div>

                                {/*
                                  The money, in the money it was charged in.

                                  ── Why one currency and not two ──────────────

                                  Because this block was mixing them: subtotal,
                                  tax and shipping converted into the books
                                  currency, and the total in the shop's own. Four
                                  correct figures that visibly did not add up,
                                  which is the one thing a summary must never do.
                                  Everything here is now the shop's currency, and
                                  the converted figure sits once at the bottom
                                  where it is a conversion rather than a
                                  contradiction.
                                */}
                                {(() => {
                                    const n = selectedOrder.native;

                                    const money = (value: number): string =>
                                        `${n.symbol}${value.toLocaleString(undefined, {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })}`;

                                    const rows: Array<{ label: string; value: string; muted?: boolean }> = [
                                        { label: 'Subtotal', value: money(n.subtotal) },
                                        ...(n.discount > 0
                                            ? [{ label: 'Discount', value: `−${money(n.discount)}` }]
                                            : []),
                                        ...(n.shipping > 0 ? [{ label: 'Shipping', value: money(n.shipping) }] : []),
                                        ...(n.tax > 0 ? [{ label: 'Tax', value: money(n.tax) }] : []),
                                    ];

                                    // Rounded before comparing: a penny of float
                                    // drift must not invent an outstanding balance.
                                    const outstanding = Math.round((n.total - n.paid) * 100) / 100;

                                    return (
                                        <section className="rounded-[var(--shell-radius)] border border-[var(--shell-border)]">
                                            <h4 className="border-b border-[var(--shell-border)] px-4 py-2.5 text-xs font-semibold text-[var(--color-text-main)]">
                                                Payment
                                            </h4>

                                            <dl className="px-4 py-3 text-sm">
                                                {rows.map((row) => (
                                                    <div key={row.label} className="flex items-baseline justify-between py-1">
                                                        <dt className="text-[var(--color-text-muted)]">{row.label}</dt>
                                                        <dd className="tabular-nums text-[var(--color-text-body)]">
                                                            {row.value}
                                                        </dd>
                                                    </div>
                                                ))}

                                                <div className="mt-2 flex items-baseline justify-between border-t border-[var(--shell-border)] pt-2.5">
                                                    <dt className="font-semibold text-[var(--color-text-main)]">Total</dt>
                                                    <dd className="text-right">
                                                        <span className="text-base font-bold tabular-nums text-[var(--color-text-main)]">
                                                            {money(n.total)}
                                                        </span>
                                                        {selectedOrder.is_converted && (
                                                            <span
                                                                className="mt-0.5 block text-xs font-normal tabular-nums text-[var(--color-text-muted)]"
                                                                title={`In the books, converted to ${selectedOrder.currency}`}
                                                            >
                                                                ≈ {formatMoney(selectedOrder.total)}
                                                            </span>
                                                        )}
                                                    </dd>
                                                </div>

                                                {/*
                                                  Shown only when the order is
                                                  genuinely unsettled.

                                                  payment_status is the
                                                  authority, not the arithmetic:
                                                  paid_minor is often zero on an
                                                  order a shop reports as paid,
                                                  because the amount was never
                                                  recorded against it. Trusting
                                                  the subtraction alone puts
                                                  "Balance due" on orders that
                                                  are fully paid, which is the
                                                  kind of wrong that gets a
                                                  customer chased for money they
                                                  already sent.
                                                */}
                                                {selectedOrder.payment_status === 'unpaid' && outstanding > 0 && (
                                                    <div className="mt-2 flex items-baseline justify-between rounded-[var(--shell-radius-sm)] bg-amber-50 px-2 py-1.5">
                                                        <dt className="text-xs font-medium text-amber-900">
                                                            Balance due
                                                        </dt>
                                                        <dd className="text-sm font-semibold tabular-nums text-amber-900">
                                                            {money(outstanding)}
                                                        </dd>
                                                    </div>
                                                )}
                                            </dl>
                                        </section>
                                    );
                                })()}

                                {selectedOrder.notes && (
                                    <section>
                                        <h4 className="mb-2 text-xs font-semibold text-[var(--color-text-main)]">
                                            Notes
                                        </h4>
                                        <p className="whitespace-pre-line text-sm leading-relaxed text-[var(--color-text-body)]">
                                            {selectedOrder.notes}
                                        </p>
                                    </section>
                                )}
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'items',
                        label: 'Items',
                        /*
                          What was actually bought.

                          ── Why this was a placeholder and should not have been ──

                          The list already carries every line — the endpoint
                          loads them with the order precisely so this tab costs
                          no second request — and the tab said "coming soon"
                          over data that was sitting in memory. A tab that
                          promises the one thing an order is made of, and then
                          does not show it, is worse than not offering the tab.

                          Priced in the shop's own currency, like everything
                          else about this order, so the lines and the total are
                          the same arithmetic.
                        */
                        content: selectedOrder && (
                            selectedOrder.items.length === 0 ? (
                                <p className="py-8 text-center text-sm text-[var(--color-text-muted)]">
                                    No line items were imported for this order.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-[var(--shell-border)] text-[11px] uppercase tracking-wider text-[var(--color-text-muted)]">
                                                <th className="px-3 py-2 text-left font-semibold">Item</th>
                                                <th className="w-14 px-3 py-2 text-right font-semibold">Qty</th>
                                                <th className="w-28 px-3 py-2 text-right font-semibold">Unit</th>
                                                <th className="w-28 px-3 py-2 text-right font-semibold">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {selectedOrder.items.map((item, index) => (
                                                <tr
                                                    key={`${item.sku ?? item.description}-${index}`}
                                                    className="border-b border-[var(--shell-border)] last:border-0"
                                                >
                                                    <td className="px-3 py-2.5">
                                                        <p className="font-medium text-[var(--color-text-main)]">
                                                            {item.description}
                                                        </p>
                                                        {item.sku && (
                                                            <p className="mt-0.5 font-mono text-[11px] text-[var(--color-text-muted)]">
                                                                {item.sku}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums text-[var(--color-text-muted)]">
                                                        {item.quantity}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums text-[var(--color-text-body)]">
                                                        {selectedOrder.native.symbol}
                                                        {item.unit_price.toLocaleString(undefined, {
                                                            minimumFractionDigits: 2,
                                                            maximumFractionDigits: 2,
                                                        })}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right font-medium tabular-nums text-[var(--color-text-main)]">
                                                        {selectedOrder.native.symbol}
                                                        {item.total.toLocaleString(undefined, {
                                                            minimumFractionDigits: 2,
                                                            maximumFractionDigits: 2,
                                                        })}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )
                        ),
                    },
                    {
                        key: 'history',
                        label: 'History',
                        /*
                          What actually happened to this order, from the event
                          log — no longer four timestamps standing in for one.

                          Loaded by the tab rather than with the order: a
                          timeline is the least-opened panel of the three, and
                          fetching everybody's history on every row click would
                          be a query per glance for a screen most glances never
                          reach.
                        */
                        content: selectedOrder && <OrderHistory orderId={selectedOrder.id} />,
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
                /*
                 * The same four actions as the row, in the same order.
                 *
                 * ── Why identical to the table ───────────────────────────────
                 *
                 * Opening an order should not change what can be done to it, or
                 * where. Two of these were previously buttons with no onClick
                 * at all — they looked like the feature and did nothing, which
                 * is worse than not offering it.
                 *
                 * Edit is the one that differs in meaning: in a row it opens
                 * this drawer, and here that would do nothing, so it reveals the
                 * fields that can actually be changed. Until now a single
                 * order's status could only be changed by selecting it and
                 * using a bulk action, which is a strange way to edit one thing.
                 */
                actions={
                    selectedOrder && (
                        <RowActions>
                            {tab === 'trashed' ? (
                                <>
                                    <RowAction
                                        icon="arrow-counter-clockwise"
                                        label="Restore from trash"
                                        onClick={() => {
                                            bulkUpdate.mutate({
                                                order_ids: [selectedOrder.id],
                                                action: 'restore',
                                            });
                                            setSelectedOrder(null);
                                        }}
                                    />
                                    <RowAction
                                        icon="trash"
                                        label="Delete permanently"
                                        variant="danger"
                                        onClick={async () => {
                                            if (
                                                await confirm(
                                                    `Permanently delete order ${selectedOrder.order_number}? This cannot be undone.`,
                                                )
                                            ) {
                                                bulkUpdate.mutate({
                                                    order_ids: [selectedOrder.id],
                                                    action: 'delete_permanently',
                                                });
                                                setSelectedOrder(null);
                                            }
                                        }}
                                    />
                                </>
                            ) : (
                                <>
                                    <RowAction
                                        icon="note-pencil"
                                        label="Edit order"
                                        onClick={() => {
                                            setEditingId(selectedOrder.id);
                                            // Closed, not stacked: two drawers at the
                                            // same depth render in DOM order, so the
                                            // editor appeared beneath the panel that
                                            // opened it. Reading and editing are
                                            // consecutive, not simultaneous.
                                            setSelectedOrder(null);
                                        }}
                                    />

                                    <RowAction
                                        icon="download-simple"
                                        label="Export as an import-ready CSV"
                                        onClick={() => exportOrders([selectedOrder])}
                                    />

                                    <RowActionMenu
                                        icon="printer"
                                        label="Print"
                                        items={[
                                            {
                                                key: 'invoice',
                                                label: 'Invoice',
                                                icon: 'receipt',
                                                onSelect: () => printDocuments([selectedOrder], 'invoice'),
                                            },
                                            {
                                                key: 'receipt',
                                                label: 'Receipt',
                                                icon: 'ticket',
                                                onSelect: () => printDocuments([selectedOrder], 'receipt'),
                                            },
                                        ]}
                                    />

                                    <RowAction
                                        icon="trash"
                                        label="Move to trash"
                                        variant="danger"
                                        onClick={async () => {
                                            if (
                                                await confirm(
                                                    `Move order ${selectedOrder.order_number} to trash?`,
                                                )
                                            ) {
                                                bulkUpdate.mutate({
                                                    order_ids: [selectedOrder.id],
                                                    action: 'trash',
                                                });
                                                setSelectedOrder(null);
                                            }
                                        }}
                                    />
                                </>
                            )}
                        </RowActions>
                    )
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
