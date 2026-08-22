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
import { Modal } from '@/components/ui/Modal';
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
    is_silent: boolean;
    last_seen_at: string | null;
    cod_fee_percent: number;
    settlement_days: number;
    unmapped_count: number;
    shipments_count: number;
    has_integration: boolean;
    integration_id: string | null;
    needs_configuration: boolean;
    is_test: boolean;
};

type CouriersResponse = {
    data: CourierConnection[];
    summary: {
        total_shipments: number;
        in_transit: number;
        delivered_today: number;
        cod_outstanding: number;
        currency: string;
    };
    available_couriers: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
};

export default function Courier() {
    useDocumentTitle('Courier & Delivery');
    const queryClient = useQueryClient();
    const { format: formatMoney } = useMoney();

    const [tab, setTab] = useState<'connections' | 'shipments'>('connections');
    
    // Courier connections state
    const [selectedCourier, setSelectedCourier] = useState<CourierConnection | null>(null);
    const [showAddModal, setShowAddModal] = useState(false);
    const [courierName, setCourierName] = useState('');
    const [courierId, setCourierId] = useState('');
    const [courierCountry, setCourierCountry] = useState('');
    const [codFee, setCodFee] = useState('1.5');
    const [settlementDays, setSettlementDays] = useState('3');

    // Fetch courier connections
    const { data: couriersData, isLoading: loadingCouriers, isError: errorCouriers, refetch: refetchCouriers } = useQuery({
        queryKey: ['couriers'],
        queryFn: ({ signal }) => api.get<CouriersResponse>('/couriers', { signal }),
    });

    const couriers = couriersData?.data ?? [];
    const summary = couriersData?.summary;
    const availableCouriers = couriersData?.available_couriers ?? [];

    // Add courier mutation
    const addCourier = useMutation({
        mutationFn: (params: { courier_id?: number; courier_name?: string; country?: string; label: string; cod_fee_percent: number; settlement_days: number }) =>
            api.post('/couriers', params),
        onSuccess: () => {
            toast.success('Courier connection created.');
            setShowAddModal(false);
            setCourierName('');
            setCourierId('');
            setCourierCountry('');
            setCodFee('1.5');
            setSettlementDays('3');
            void queryClient.invalidateQueries({ queryKey: ['couriers'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Failed to create courier connection.'),
    });

    // Update courier status
    const updateCourier = useMutation({
        mutationFn: (params: { id: string; status: string }) =>
            api.put(`/couriers/${params.id}`, { status: params.status }),
        onSuccess: () => {
            toast.success('Courier status updated.');
            void queryClient.invalidateQueries({ queryKey: ['couriers'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Failed to update courier.'),
    });

    const simulateCourier = useMutation({
        mutationFn: (id: string) => api.post(`/couriers/${id}/simulate`),
        onSuccess: (result: { message?: string }) => {
            toast.success(result.message ?? 'Sandbox shipment advanced.');
            void queryClient.invalidateQueries({ queryKey: ['couriers'] });
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        },
        onError: (error: Error) => toast.error(error.message || 'Could not advance sandbox courier.'),
    });

    const handleAddCourier = () => {
        if ((!courierId && !courierName) || !courierName) {
            toast.error('Please fill all required fields.');
            return;
        }

        addCourier.mutate({
            ...(courierId === 'custom' ? { courier_name: courierName, country: courierCountry || undefined } : { courier_id: parseInt(courierId) }),
            label: courierName,
            cod_fee_percent: parseFloat(codFee),
            settlement_days: parseInt(settlementDays),
        });
    };

    const handleToggleStatus = (courier: CourierConnection) => {
        const newStatus = courier.status === 'active' ? 'paused' : 'active';
        updateCourier.mutate({ id: courier.id, status: newStatus });
    };

    const statusVariants: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
        active: 'success',
        paused: 'warning',
        broken: 'danger',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="pt-6">
                <PageHeader
                    title="Courier & Delivery"
                    description="Manage courier connections and track shipments"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => setShowAddModal(true)}
                        >
                            <Icon name="plus" size={16} />
                            <span>Add Courier</span>
                        </button>
                    }
                />
            </div>

            {/* KPI row */}
            {summary && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Shipments"
                        value={summary.total_shipments.toLocaleString()}
                        icon="package"
                        variant="brand"
                    />
                    <KPICard
                        label="In Transit"
                        value={summary.in_transit.toLocaleString()}
                        icon="truck"
                        variant="info"
                    />
                    <KPICard
                        label="Delivered Today"
                        value={summary.delivered_today.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="COD Outstanding"
                        value={formatMoney(summary.cod_outstanding)}
                        icon="currency-dollar"
                        variant="warning"
                    />
                </div>
            )}

            {/* Tabs */}
            <div className="mt-4 border-b border-[var(--shell-border)]">
                <div className="flex gap-1">
                    <button
                        type="button"
                        onClick={() => setTab('connections')}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                            tab === 'connections'
                                ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--shell-border)]'
                        }`}
                    >
                        <Icon name="plugs-connected" size={14} className="inline mr-1" />
                        Courier Connections
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('shipments')}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${
                            tab === 'shipments'
                                ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--shell-border)]'
                        }`}
                    >
                        <Icon name="package" size={14} className="inline mr-1" />
                        All Shipments
                    </button>
                </div>
            </div>

            {/* Content based on tab */}
            <div className="flex-1 overflow-auto pb-6">
                {tab === 'connections' ? (
                    <>
                        {/* Configuration notice */}
                        {couriers.some(c => c.needs_configuration) && (
                            <div className="mt-4 mx-4 bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3">
                                <Icon name="warning" size={20} className="text-amber-600 flex-shrink-0 mt-0.5" />
                                <div className="flex-1">
                                    <h4 className="text-sm font-semibold text-amber-900 mb-1">
                                        Action Required: Complete Courier Configuration
                                    </h4>
                                    <p className="text-sm text-amber-700">
                                        {couriers.filter(c => c.needs_configuration).length} courier{couriers.filter(c => c.needs_configuration).length > 1 ? 's need' : ' needs'} API configuration to start working. 
                                        Click the "Configure" button next to each courier to set up API credentials.
                                    </p>
                                </div>
                            </div>
                        )}
                        
                        {/* Courier Connections Tab */}
                        {errorCouriers ? (
                            <div className="card mt-6 p-6 text-center">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    Failed to load couriers.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => void refetchCouriers()}
                                    className="btn btn-secondary mt-4"
                                >
                                    Try again
                                </button>
                            </div>
                        ) : (
                            <div className="card mt-6 overflow-hidden">
                            <Table
                                data={couriers}
                                loading={loadingCouriers}
                                skeletonRows={5}
                                columns={[
                                    {
                                        key: 'label',
                                        label: 'Courier',
                                        sortable: false,
                                        render: (courier) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {courier.label}
                                                </p>
                                                <p className="text-xs text-[var(--color-text-muted)]">
                                                    {courier.courier.name}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        sortable: false,
                                        render: (courier) => (
                                            <StatusBadge
                                                label={courier.status.charAt(0).toUpperCase() + courier.status.slice(1)}
                                                variant={statusVariants[courier.status] ?? 'neutral'}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'shipments_count',
                                        label: 'Shipments',
                                        align: 'center',
                                        accessor: (c) => c.shipments_count.toLocaleString(),
                                    },
                                    {
                                        key: 'cod_fee',
                                        label: 'COD Fee',
                                        align: 'right',
                                        accessor: (c) => `${c.cod_fee_percent}%`,
                                    },
                                    {
                                        key: 'settlement',
                                        label: 'Settlement',
                                        align: 'center',
                                        accessor: (c) => `${c.settlement_days} days`,
                                    },
                                    {
                                        key: 'last_seen',
                                        label: 'Last Seen',
                                        sortable: false,
                                        render: (courier) =>
                                            courier.is_silent ? (
                                                <span className="text-xs text-[var(--color-danger)]">
                                                    Silent (3+ days)
                                                </span>
                                            ) : courier.last_seen_at ? (
                                                <span className="text-xs text-[var(--color-text-muted)]">
                                                    {new Date(courier.last_seen_at).toLocaleDateString()}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-[var(--color-text-muted)]">Never</span>
                                            ),
                                    },
                                    {
                                        key: 'actions',
                                        label: '',
                                        width: 'w-48',
                                        render: (courier) => (
                                            <div className="flex items-center gap-2">
                                                {courier.needs_configuration ? (
                                                    <a
                                                        href={`/settings/integrations?edit=${courier.integration_id}`}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="text-xs bg-red-100 text-red-800 px-3 py-1.5 rounded font-medium hover:bg-red-200 transition-colors"
                                                    >
                                                        Configure API
                                                    </a>
                                                ) : null}
                                                {courier.is_test ? (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            simulateCourier.mutate(courier.id);
                                                        }}
                                                        className="text-xs text-[var(--color-brand)] hover:underline"
                                                        disabled={simulateCourier.isPending}
                                                    >
                                                        Advance test
                                                    </button>
                                                ) : null}
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleToggleStatus(courier);
                                                    }}
                                                    className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-brand)] transition-colors"
                                                >
                                                    {courier.status === 'active' ? 'Pause' : 'Activate'}
                                                </button>
                                            </div>
                                        ),
                                    },
                                ]}
                                onRowClick={(courier) => setSelectedCourier(courier)}
                                clickable
                                getRowKey={(courier) => courier.id}
                                emptyState={
                                    <EmptyState
                                        icon="truck"
                                        title="No couriers connected"
                                        body="Add a courier connection to start managing shipments."
                                        action={
                                            <button className="btn btn-primary" onClick={() => setShowAddModal(true)}>
                                                Add Courier
                                            </button>
                                        }
                                    />
                                }
                            />
                        </div>
                        )}
                    </>
                ) : (
                    // Shipments Tab - Placeholder for now
                    <div className="card mt-6 p-12 text-center">
                        <Icon name="package" size={48} className="mx-auto mb-4 text-[var(--color-text-muted)]" />
                        <h3 className="text-lg font-semibold mb-2">Shipments Coming Soon</h3>
                        <p className="text-sm text-[var(--color-text-muted)]">
                            Shipment tracking and management will be available here.
                        </p>
                    </div>
                )}
            </div>

            {/* Add Courier Modal */}
            <Modal
                open={showAddModal}
                onClose={() => setShowAddModal(false)}
                title="Add Courier Connection"
            >
                <div className="space-y-4">
                    <div className="form-group">
                        <label className="label">Courier</label>
                        <select
                            className="field"
                            value={courierId}
                            onChange={(e) => setCourierId(e.target.value)}
                        >
                            <option value="">Select courier...</option>
                            {availableCouriers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                            <option value="custom">Other / international courier</option>
                        </select>
                    </div>

                    {courierId === 'custom' && (
                        <div className="form-group">
                            <label className="label">Courier name</label>
                            <input
                                type="text"
                                className="field"
                                placeholder="e.g., DHL Express, FedEx, Local carrier"
                                value={courierName}
                                onChange={(e) => setCourierName(e.target.value)}
                            />
                        </div>
                    )}

                    {courierId === 'custom' && (
                        <div className="form-group">
                            <label className="label">Country code</label>
                            <input
                                type="text"
                                className="field"
                                placeholder="e.g., US"
                                maxLength={2}
                                value={courierCountry}
                                onChange={(e) => setCourierCountry(e.target.value.toUpperCase())}
                            />
                        </div>
                    )}

                    <div className="form-group">
                        <label className="label">Label</label>
                        <input
                            type="text"
                            className="field"
                            placeholder="e.g., Pathao Main Account"
                            value={courierName}
                            onChange={(e) => setCourierName(e.target.value)}
                        />
                    </div>

                    <div className="form-group">
                        <label className="label">COD Fee (%)</label>
                        <input
                            type="number"
                            className="field"
                            placeholder="1.5"
                            step="0.1"
                            value={codFee}
                            onChange={(e) => setCodFee(e.target.value)}
                        />
                    </div>

                    <div className="form-group">
                        <label className="label">Settlement Days</label>
                        <input
                            type="number"
                            className="field"
                            placeholder="3"
                            value={settlementDays}
                            onChange={(e) => setSettlementDays(e.target.value)}
                        />
                    </div>

                    <div className="flex gap-3 pt-4">
                        <button
                            type="button"
                            className="btn btn-secondary flex-1"
                            onClick={() => setShowAddModal(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary flex-1"
                            onClick={handleAddCourier}
                            disabled={addCourier.isPending}
                        >
                            {addCourier.isPending ? 'Creating...' : 'Create Connection'}
                        </button>
                    </div>
                </div>
            </Modal>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedCourier}
                onClose={() => setSelectedCourier(null)}
                title={selectedCourier?.label ?? ''}
                subtitle={selectedCourier?.courier.name}
            >
                {selectedCourier && (
                    <div className="space-y-6">
                        <DrawerSection title="Connection Details">
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={selectedCourier.status.charAt(0).toUpperCase() + selectedCourier.status.slice(1)}
                                        variant={statusVariants[selectedCourier.status] ?? 'neutral'}
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField
                                label="Total Shipments"
                                value={selectedCourier.shipments_count.toLocaleString()}
                                icon="package"
                            />
                            <DrawerField
                                label="COD Fee"
                                value={`${selectedCourier.cod_fee_percent}%`}
                                icon="percent"
                            />
                            <DrawerField
                                label="Settlement Period"
                                value={`${selectedCourier.settlement_days} days`}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Last Activity"
                                value={
                                    selectedCourier.last_seen_at
                                        ? new Date(selectedCourier.last_seen_at).toLocaleString()
                                        : 'Never'
                                }
                                icon="clock"
                            />
                        </DrawerSection>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
