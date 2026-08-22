import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

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
import { useMoney } from '@/hooks/useMoney';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

type CourierConnection = {
    id: string;
    label: string;
    courier: {
        id: number;
        name: string;
        slug: string;
    };
    status: 'active' | 'paused' | 'broken';
    cod_fee_percent: number;
    settlement_days: number;
    shipments_count: number;
};

type Shipment = {
    id: string;
    tracking_number: string;
    order: {
        id: string;
        number: string;
    };
    customer: {
        id: string;
        name: string;
    };
    courier: string;
    service_type: 'standard' | 'express' | 'overnight' | 'same_day';
    status: 'pending' | 'picked_up' | 'in_transit' | 'out_for_delivery' | 'delivered' | 'failed';
    origin: string;
    destination: string;
    weight: number;
    shipping_cost: number;
    estimated_delivery: string;
    created_at: string;
};

type CourierResponse = {
    data: Shipment[];
    summary: {
        total_shipments: number;
        in_transit: number;
        delivered_today: number;
        total_shipping_cost: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Courier() {
    useDocumentTitle('Courier & Delivery');
    const queryClient = useQueryClient();

    const [tab, setTab] = useState<'shipments' | 'connections'>('shipments');
    const [search, setSearch] = useState('');
    const [courierFilter, setCourierFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedShipment, setSelectedShipment] = useState<Shipment | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');
    
    // Courier connection management
    const [selectedCourier, setSelectedCourier] = useState<CourierConnection | null>(null);
    const [showAddModal, setShowAddModal] = useState(false);
    const [courierName, setCourierName] = useState('');
    const [courierId, setCourierId] = useState('');
    const [codFee, setCodFee] = useState('1.5');
    const [settlementDays, setSettlementDays] = useState('3');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['shipments', { search, courierFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<CourierResponse>('/shipments', {
                params: {
                    search,
                    courier: courierFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const shipments = data?.data ?? [];
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
        setCourierFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || courierFilter || statusFilter;

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney } = useMoney();

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const serviceTypeLabels: Record<string, string> = {
        standard: 'Standard',
        express: 'Express',
        overnight: 'Overnight',
        same_day: 'Same Day',
    };

    const statusVariants: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
        pending: 'neutral',
        picked_up: 'info',
        in_transit: 'warning',
        out_for_delivery: 'warning',
        delivered: 'success',
        failed: 'danger',
    };

    const statusLabels = {
        pending: 'Pending',
        picked_up: 'Picked Up',
        in_transit: 'In Transit',
        out_for_delivery: 'Out for Delivery',
        delivered: 'Delivered',
        failed: 'Failed',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Courier & Delivery"
                    description="Track shipments and manage delivery operations"
                    icon="truck"
                    actions={
                        <div className="flex gap-3">
                            <button className="btn btn-secondary" onClick={() => console.log('Bulk ship')}>
                                <Icon name="packages" size={16} />
                                <span>Bulk ship</span>
                            </button>
                            <button className="btn btn-primary" onClick={() => console.log('Create shipment')}>
                                <Icon name="plus" size={16} />
                                <span>Create shipment</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Shipments"
                        value={summary.total_shipments.toLocaleString()}
                        icon="truck"
                        variant="brand"
                    />
                    <KPICard
                        label="In Transit"
                        value={summary.in_transit.toLocaleString()}
                        icon="arrow-right"
                        variant="warning"
                    />
                    <KPICard
                        label="Delivered Today"
                        value={summary.delivered_today.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Shipping Cost"
                        value={formatMoney(summary.total_shipping_cost)}
                        icon="currency-dollar"
                        variant="info"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search tracking numbers or orders..."
                    filters={
                        <>
                            <FilterSelect
                                label="Courier"
                                value={courierFilter}
                                onChange={setCourierFilter}
                                options={[
                                    { value: 'fedex', label: 'FedEx' },
                                    { value: 'ups', label: 'UPS' },
                                    { value: 'dhl', label: 'DHL' },
                                    { value: 'usps', label: 'USPS' },
                                ]}
                                placeholder="All couriers"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'in_transit', label: 'In Transit' },
                                    { value: 'out_for_delivery', label: 'Out for Delivery' },
                                    { value: 'delivered', label: 'Delivered' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load shipments.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={shipments}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'tracking_number',
                                    label: 'Tracking',
                                    render: (ship) => (
                                        <div>
                                            <p className="font-mono font-medium text-[var(--color-brand)]">
                                                {ship.tracking_number}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                Order: {ship.order.number}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'customer',
                                    label: 'Customer',
                                    accessor: (ship) => ship.customer.name,
                                },
                                {
                                    key: 'courier',
                                    label: 'Courier',
                                    render: (ship) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{ship.courier}</p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {serviceTypeLabels[ship.service_type]}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'route',
                                    label: 'Route',
                                    render: (ship) => (
                                        <div className="text-sm">
                                            <p className="text-[var(--color-text-body)]">{ship.origin}</p>
                                            <Icon name="arrow-down" size={12} className="my-0.5 text-[var(--color-text-muted)]" />
                                            <p className="text-[var(--color-text-body)]">{ship.destination}</p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (ship) => (
                                        <StatusBadge
                                            label={statusLabels[ship.status]}
                                            variant={statusVariants[ship.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'estimated_delivery',
                                    label: 'ETA',
                                    sortable: true,
                                    accessor: (ship) => formatDate(ship.estimated_delivery),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(ship) => setSelectedShipment(ship)}
                            clickable
                            getRowKey={(ship) => ship.id}
                            emptyState={
                                <EmptyState
                                    icon="truck"
                                    title={hasFilters ? 'No shipments match' : 'No shipments yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first shipment to start tracking deliveries.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create shipment</span>
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
                open={!!selectedShipment}
                onClose={() => setSelectedShipment(null)}
                title={selectedShipment?.tracking_number ?? ''}
                subtitle={selectedShipment ? `Order: ${selectedShipment.order.number}` : ''}
                size="md"
            >
                {selectedShipment && (
                    <div className="space-y-6">
                        <DrawerSection title="Shipment Details">
                            <DrawerField label="Customer" value={selectedShipment.customer.name} icon="user" />
                            <DrawerField label="Courier" value={selectedShipment.courier} icon="truck" />
                            <DrawerField
                                label="Service"
                                value={serviceTypeLabels[selectedShipment.service_type]}
                                icon="rocket"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedShipment.status]}
                                        variant={statusVariants[selectedShipment.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Route">
                            <DrawerField label="Origin" value={selectedShipment.origin} icon="map-pin" />
                            <DrawerField label="Destination" value={selectedShipment.destination} icon="map-pin-line" />
                        </DrawerSection>

                        <DrawerSection title="Details">
                            <DrawerField label="Weight" value={`${selectedShipment.weight} kg`} icon="barbell" />
                            <DrawerField
                                label="Shipping Cost"
                                value={formatMoney(selectedShipment.shipping_cost)}
                                icon="currency-dollar"
                            />
                            <DrawerField
                                label="Estimated Delivery"
                                value={formatDate(selectedShipment.estimated_delivery)}
                                icon="calendar-check"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="map-trifold" size={16} />
                                <span>Track</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="printer" size={16} />
                                <span>Label</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
