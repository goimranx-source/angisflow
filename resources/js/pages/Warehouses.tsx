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

type Warehouse = {
    id: string;
    name: string;
    code: string;
    type: 'main' | 'regional' | 'transit' | 'store';
    address: {
        street: string;
        city: string;
        state: string;
        country: string;
    };
    status: 'active' | 'inactive' | 'maintenance';
    capacity: number;
    utilization: number;
    products_count: number;
    total_stock_value: number;
    manager?: string;
    created_at: string;
};

type WarehousesResponse = {
    data: Warehouse[];
    summary: {
        total_warehouses: number;
        active_count: number;
        total_capacity: number;
        avg_utilization: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Warehouses() {
    useDocumentTitle('Warehouses');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedWarehouse, setSelectedWarehouse] = useState<Warehouse | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['warehouses', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<WarehousesResponse>('/warehouses', {
                params: {
                    search,
                    type: typeFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const warehouses = data?.data ?? [];
    const summary = data?.summary;

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
        setTypeFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || typeFilter || statusFilter;

    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
    };

    const typeLabels: Record<string, string> = {
        main: 'Main Warehouse',
        regional: 'Regional',
        transit: 'Transit Hub',
        store: 'Store',
    };

    const statusVariants: Record<string, 'success' | 'neutral' | 'warning'> = {
        active: 'success',
        inactive: 'neutral',
        maintenance: 'warning',
    };

    const statusLabels = {
        active: 'Active',
        inactive: 'Inactive',
        maintenance: 'Maintenance',
    };

    const getUtilizationColor = (utilization: number) => {
        if (utilization >= 90) return 'text-[var(--color-danger)]';
        if (utilization >= 70) return 'text-[var(--color-warning)]';
        return 'text-[var(--color-success)]';
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Warehouses"
                    description="Manage warehouse locations and inventory distribution"
                    icon="warehouse"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Add warehouse')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Add warehouse</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Warehouses"
                        value={summary.total_warehouses.toLocaleString()}
                        icon="warehouse"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_count.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Total Capacity"
                        value={`${summary.total_capacity.toLocaleString()} sq ft`}
                        icon="arrows-out"
                        variant="info"
                    />
                    <KPICard
                        label="Avg Utilization"
                        value={`${summary.avg_utilization}%`}
                        icon="chart-bar"
                        variant={summary.avg_utilization >= 80 ? 'warning' : 'success'}
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search warehouses..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'main', label: 'Main Warehouse' },
                                    { value: 'regional', label: 'Regional' },
                                    { value: 'transit', label: 'Transit Hub' },
                                    { value: 'store', label: 'Store' },
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
                                    { value: 'maintenance', label: 'Maintenance' },
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

            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load warehouses.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={warehouses}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Warehouse',
                                    render: (wh) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{wh.name}</p>
                                            <p className="mt-0.5 font-mono text-xs text-[var(--color-text-muted)]">
                                                {wh.code}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (wh) => typeLabels[wh.type],
                                },
                                {
                                    key: 'location',
                                    label: 'Location',
                                    accessor: (wh) => `${wh.address.city}, ${wh.address.state}`,
                                },
                                {
                                    key: 'products_count',
                                    label: 'Products',
                                    align: 'right',
                                    sortable: true,
                                    render: (wh) => (
                                        <span className="tabular-nums">{wh.products_count.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'utilization',
                                    label: 'Utilization',
                                    align: 'right',
                                    sortable: true,
                                    render: (wh) => (
                                        <span className={`font-semibold tabular-nums ${getUtilizationColor(wh.utilization)}`}>
                                            {wh.utilization}%
                                        </span>
                                    ),
                                },
                                {
                                    key: 'total_stock_value',
                                    label: 'Stock Value',
                                    align: 'right',
                                    sortable: true,
                                    render: (wh) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatMoney(wh.total_stock_value)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (wh) => (
                                        <StatusBadge
                                            label={statusLabels[wh.status]}
                                            variant={statusVariants[wh.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(wh) => setSelectedWarehouse(wh)}
                            clickable
                            getRowKey={(wh) => wh.id}
                            emptyState={
                                <EmptyState
                                    icon="warehouse"
                                    title={hasFilters ? 'No warehouses match' : 'No warehouses yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Add your first warehouse to manage inventory locations.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Add warehouse</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            <DetailDrawer
                open={!!selectedWarehouse}
                onClose={() => setSelectedWarehouse(null)}
                title={selectedWarehouse?.name ?? ''}
                subtitle={selectedWarehouse?.code ?? ''}
                size="md"
            >
                {selectedWarehouse && (
                    <div className="space-y-6">
                        <DrawerSection title="Warehouse Details">
                            <DrawerField label="Type" value={typeLabels[selectedWarehouse.type]} icon="tag" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedWarehouse.status]}
                                        variant={statusVariants[selectedWarehouse.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            {selectedWarehouse.manager && (
                                <DrawerField label="Manager" value={selectedWarehouse.manager} icon="user" />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Location">
                            <DrawerField
                                label="Address"
                                value={
                                    <div className="text-right">
                                        <p>{selectedWarehouse.address.street}</p>
                                        <p>
                                            {selectedWarehouse.address.city}, {selectedWarehouse.address.state}
                                        </p>
                                        <p>{selectedWarehouse.address.country}</p>
                                    </div>
                                }
                                icon="map-pin"
                            />
                        </DrawerSection>

                        <DrawerSection title="Capacity">
                            <DrawerField
                                label="Total Capacity"
                                value={`${selectedWarehouse.capacity.toLocaleString()} sq ft`}
                                icon="arrows-out"
                            />
                            <DrawerField
                                label="Utilization"
                                value={
                                    <span className={getUtilizationColor(selectedWarehouse.utilization)}>
                                        {selectedWarehouse.utilization}%
                                    </span>
                                }
                                icon="chart-bar"
                            />
                        </DrawerSection>

                        <DrawerSection title="Inventory">
                            <DrawerField
                                label="Products"
                                value={selectedWarehouse.products_count.toLocaleString()}
                                icon="package"
                            />
                            <DrawerField
                                label="Stock Value"
                                value={formatMoney(selectedWarehouse.total_stock_value)}
                                icon="currency-dollar"
                            />
                        </DrawerSection>

                        {selectedWarehouse.utilization >= 90 && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-danger-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="warning" size={20} className="flex-none text-[var(--color-danger)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">High Utilization</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This warehouse is at {selectedWarehouse.utilization}% capacity. Consider
                                            redistributing inventory.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="package" size={16} />
                                <span>View Stock</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="gear" size={16} />
                                <span>Settings</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
