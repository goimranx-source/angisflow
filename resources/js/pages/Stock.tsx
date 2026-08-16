import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type StockItem = {
    id: string;
    product: {
        id: string;
        name: string;
        sku: string;
        image?: string;
    };
    warehouse?: string;
    quantity: number;
    reserved: number;
    available: number;
    reorder_level: number;
    status: 'in_stock' | 'low_stock' | 'out_of_stock' | 'overstock';
    last_restocked_at?: string;
    updated_at: string;
};

type StockResponse = {
    data: StockItem[];
    summary: {
        total_items: number;
        total_quantity: number;
        low_stock_count: number;
        out_of_stock_count: number;
        total_value: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Stock() {
    useDocumentTitle('Stock');

    // State
    const [search, setSearch] = useState('');
    const [warehouseFilter, setWarehouseFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedItem, setSelectedItem] = useState<StockItem | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('updated_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch stock
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['stock', { search, warehouseFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<StockResponse>('/stock', {
                params: {
                    search,
                    warehouse: warehouseFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const items = data?.data ?? [];
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
        setWarehouseFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || warehouseFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Format currency
    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
        in_stock: 'success',
        low_stock: 'warning',
        out_of_stock: 'danger',
        overstock: 'info',
    };

    const statusLabels = {
        in_stock: 'In Stock',
        low_stock: 'Low Stock',
        out_of_stock: 'Out of Stock',
        overstock: 'Overstock',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Stock"
                    description="Track inventory levels and manage stock across warehouses"
                    icon="package"
                    actions={
                        <div className="flex gap-3">
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Stock adjustment')}
                            >
                                <Icon name="arrows-clockwise" size={16} />
                                <span>Adjust stock</span>
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => console.log('Restock')}
                            >
                                <Icon name="plus" size={16} />
                                <span>Restock</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-5">
                    <KPICard
                        label="Total Items"
                        value={summary.total_items.toLocaleString()}
                        icon="package"
                        variant="brand"
                    />
                    <KPICard
                        label="Total Quantity"
                        value={summary.total_quantity.toLocaleString()}
                        icon="stack"
                        variant="info"
                    />
                    <KPICard
                        label="Low Stock"
                        value={summary.low_stock_count.toLocaleString()}
                        icon="warning"
                        variant="warning"
                    />
                    <KPICard
                        label="Out of Stock"
                        value={summary.out_of_stock_count.toLocaleString()}
                        icon="x-circle"
                        variant="danger"
                    />
                    <KPICard
                        label="Stock Value"
                        value={formatMoney(summary.total_value)}
                        icon="currency-dollar"
                        variant="success"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search products or SKU..."
                    filters={
                        <>
                            <FilterSelect
                                label="Warehouse"
                                value={warehouseFilter}
                                onChange={setWarehouseFilter}
                                options={[
                                    { value: 'main', label: 'Main Warehouse' },
                                    { value: 'secondary', label: 'Secondary Warehouse' },
                                    { value: 'store', label: 'Retail Store' },
                                ]}
                                placeholder="All warehouses"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'in_stock', label: 'In Stock' },
                                    { value: 'low_stock', label: 'Low Stock' },
                                    { value: 'out_of_stock', label: 'Out of Stock' },
                                    { value: 'overstock', label: 'Overstock' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load stock.</p>
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
                            data={items}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'product',
                                    label: 'Product',
                                    render: (item) => (
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-neutral-subtle)]">
                                                {item.product.image ? (
                                                    <img
                                                        src={item.product.image}
                                                        alt={item.product.name}
                                                        className="h-full w-full rounded-lg object-cover"
                                                    />
                                                ) : (
                                                    <Icon name="package" size={20} className="text-[var(--color-text-muted)]" />
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {item.product.name}
                                                </p>
                                                <p className="mt-0.5 font-mono text-xs text-[var(--color-text-muted)]">
                                                    {item.product.sku}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'warehouse',
                                    label: 'Warehouse',
                                    accessor: (item) => item.warehouse || '—',
                                },
                                {
                                    key: 'quantity',
                                    label: 'Quantity',
                                    align: 'right',
                                    sortable: true,
                                    render: (item) => (
                                        <span className="tabular-nums font-semibold">
                                            {item.quantity.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'reserved',
                                    label: 'Reserved',
                                    align: 'right',
                                    sortable: true,
                                    render: (item) => (
                                        <span className="tabular-nums text-[var(--color-warning)]">
                                            {item.reserved.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'available',
                                    label: 'Available',
                                    align: 'right',
                                    sortable: true,
                                    render: (item) => (
                                        <span className="tabular-nums font-semibold text-[var(--color-success)]">
                                            {item.available.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'reorder_level',
                                    label: 'Reorder Level',
                                    align: 'right',
                                    render: (item) => (
                                        <span className="tabular-nums text-sm text-[var(--color-text-body)]">
                                            {item.reorder_level.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (item) => (
                                        <StatusBadge
                                            label={statusLabels[item.status]}
                                            variant={statusVariants[item.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(item) => setSelectedItem(item)}
                            clickable
                            getRowKey={(item) => item.id}
                            emptyState={
                                <EmptyState
                                    icon="package"
                                    title={hasFilters ? 'No items match' : 'No stock items yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Start tracking inventory by adding products to stock.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Add to stock</span>
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
                open={!!selectedItem}
                onClose={() => setSelectedItem(null)}
                title={selectedItem?.product.name ?? ''}
                subtitle={selectedItem ? `SKU: ${selectedItem.product.sku}` : ''}
                size="md"
            >
                {selectedItem && (
                    <div className="space-y-6">
                        <DrawerSection title="Stock Details">
                            <DrawerField
                                label="Total Quantity"
                                value={selectedItem.quantity.toLocaleString()}
                                icon="stack"
                            />
                            <DrawerField
                                label="Reserved"
                                value={
                                    <span className="text-[var(--color-warning)]">
                                        {selectedItem.reserved.toLocaleString()}
                                    </span>
                                }
                                icon="lock"
                            />
                            <DrawerField
                                label="Available"
                                value={
                                    <span className="text-[var(--color-success)]">
                                        {selectedItem.available.toLocaleString()}
                                    </span>
                                }
                                icon="check-circle"
                            />
                            <DrawerField
                                label="Reorder Level"
                                value={selectedItem.reorder_level.toLocaleString()}
                                icon="warning"
                            />
                        </DrawerSection>

                        <DrawerSection title="Location">
                            {selectedItem.warehouse && (
                                <DrawerField
                                    label="Warehouse"
                                    value={selectedItem.warehouse}
                                    icon="warehouse"
                                />
                            )}
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedItem.status]}
                                        variant={statusVariants[selectedItem.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Timeline">
                            {selectedItem.last_restocked_at && (
                                <DrawerField
                                    label="Last Restocked"
                                    value={formatDate(selectedItem.last_restocked_at)}
                                    icon="arrow-clockwise"
                                />
                            )}
                            <DrawerField
                                label="Last Updated"
                                value={formatDate(selectedItem.updated_at)}
                                icon="clock"
                            />
                        </DrawerSection>

                        {selectedItem.status === 'low_stock' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-warning-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="warning" size={20} className="flex-none text-[var(--color-warning)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">Low Stock Alert</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            Stock level is below the reorder point. Consider restocking soon.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {selectedItem.status === 'out_of_stock' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-danger-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="x-circle" size={20} className="flex-none text-[var(--color-danger)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">Out of Stock</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This item is currently out of stock. Restock immediately to fulfill orders.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="plus" size={16} />
                                <span>Restock</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="arrows-clockwise" size={16} />
                                <span>Adjust</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
