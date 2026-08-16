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
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Integration = {
    id: string;
    name: string;
    description: string;
    provider: string;
    category: 'payment' | 'shipping' | 'accounting' | 'crm' | 'marketing' | 'productivity' | 'custom';
    status: 'active' | 'inactive' | 'error';
    last_sync_at?: string;
    created_at: string;
};

type APIKey = {
    id: string;
    name: string;
    key_prefix: string;
    permissions: string[];
    last_used_at?: string;
    expires_at?: string;
    created_at: string;
};

type IntegrationsResponse = {
    data: Integration[];
    summary: {
        total_integrations: number;
        active_integrations: number;
        failed_syncs: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

type APIKeysResponse = {
    data: APIKey[];
    summary: {
        total_keys: number;
        active_keys: number;
        expired_keys: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function IntegrationsAPI() {
    useDocumentTitle('Integrations & API');

    // State
    const [view, setView] = useState<'integrations' | 'api'>('integrations');
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedIntegration, setSelectedIntegration] = useState<Integration | null>(null);
    const [selectedAPIKey, setSelectedAPIKey] = useState<APIKey | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch integrations
    const { data: integrationsData, isLoading: loadingIntegrations, isError: errorIntegrations, refetch: refetchIntegrations } = useQuery({
        queryKey: ['integrations', { search, categoryFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<IntegrationsResponse>('/integrations', {
                params: {
                    search,
                    category: categoryFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'integrations',
    });

    // Fetch API keys
    const { data: apiKeysData, isLoading: loadingAPIKeys, isError: errorAPIKeys, refetch: refetchAPIKeys } = useQuery({
        queryKey: ['api-keys', { search, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<APIKeysResponse>('/api-keys', {
                params: {
                    search,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
        enabled: view === 'api',
    });

    const integrations = integrationsData?.data ?? [];
    const integrationsSummary = integrationsData?.summary;
    const apiKeys = apiKeysData?.data ?? [];
    const apiKeysSummary = apiKeysData?.summary;
    const isLoading = view === 'integrations' ? loadingIntegrations : loadingAPIKeys;
    const isError = view === 'integrations' ? errorIntegrations : errorAPIKeys;

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
        setCategoryFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || categoryFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Format time ago
    const formatTimeAgo = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffInHours = (now.getTime() - d.getTime()) / (1000 * 60 * 60);

        if (diffInHours < 1) return 'Just now';
        if (diffInHours < 24) return `${Math.floor(diffInHours)}h ago`;
        if (diffInHours < 48) return 'Yesterday';
        return formatDate(date);
    };

    // Category metadata
    const categoryMeta: Record<Integration['category'], { label: string; icon: string; color: string }> = {
        payment: { label: 'Payment', icon: 'credit-card', color: 'text-[var(--color-success)]' },
        shipping: { label: 'Shipping', icon: 'truck', color: 'text-[var(--color-info)]' },
        accounting: { label: 'Accounting', icon: 'receipt', color: 'text-[var(--color-brand)]' },
        crm: { label: 'CRM', icon: 'users-three', color: 'text-[var(--color-warning)]' },
        marketing: { label: 'Marketing', icon: 'megaphone', color: 'text-[var(--color-danger)]' },
        productivity: { label: 'Productivity', icon: 'lightning', color: 'text-[var(--color-neutral)]' },
        custom: { label: 'Custom', icon: 'code', color: 'text-[var(--color-text-muted)]' },
    };

    // Status variants
    const statusVariants: Record<Integration['status'], 'success' | 'neutral' | 'danger'> = {
        active: 'success',
        inactive: 'neutral',
        error: 'danger',
    };

    const statusLabels = {
        active: 'Active',
        inactive: 'Inactive',
        error: 'Error',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Integrations & API"
                    description="Connect third-party services and manage API access"
                    icon="plug"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log(view === 'integrations' ? 'Add integration' : 'Create API key')}
                        >
                            <Icon name="plus" size={16} />
                            <span>{view === 'integrations' ? 'Add integration' : 'Create API key'}</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {view === 'integrations' && integrationsSummary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-3">
                    <KPICard
                        label="Total Integrations"
                        value={integrationsSummary.total_integrations.toLocaleString()}
                        icon="plug"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={integrationsSummary.active_integrations.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Failed Syncs"
                        value={integrationsSummary.failed_syncs.toLocaleString()}
                        icon="warning"
                        variant="danger"
                    />
                </div>
            )}

            {view === 'api' && apiKeysSummary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-3">
                    <KPICard
                        label="Total Keys"
                        value={apiKeysSummary.total_keys.toLocaleString()}
                        icon="key"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={apiKeysSummary.active_keys.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Expired"
                        value={apiKeysSummary.expired_keys.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 px-6">
                <div className="flex gap-2">
                    <ViewToggleButton
                        icon="plug"
                        label="Integrations"
                        active={view === 'integrations'}
                        onClick={() => setView('integrations')}
                    />
                    <ViewToggleButton
                        icon="key"
                        label="API Keys"
                        active={view === 'api'}
                        onClick={() => setView('api')}
                    />
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder={view === 'integrations' ? 'Search integrations...' : 'Search API keys...'}
                    filters={
                        <>
                            {view === 'integrations' && (
                                <>
                                    <FilterSelect
                                        label="Category"
                                        value={categoryFilter}
                                        onChange={setCategoryFilter}
                                        options={[
                                            { value: 'payment', label: 'Payment' },
                                            { value: 'shipping', label: 'Shipping' },
                                            { value: 'accounting', label: 'Accounting' },
                                            { value: 'crm', label: 'CRM' },
                                            { value: 'marketing', label: 'Marketing' },
                                            { value: 'productivity', label: 'Productivity' },
                                            { value: 'custom', label: 'Custom' },
                                        ]}
                                        placeholder="All categories"
                                    />
                                    <FilterSelect
                                        label="Status"
                                        value={statusFilter}
                                        onChange={setStatusFilter}
                                        options={[
                                            { value: 'active', label: 'Active' },
                                            { value: 'inactive', label: 'Inactive' },
                                            { value: 'error', label: 'Error' },
                                        ]}
                                        placeholder="All statuses"
                                    />
                                </>
                            )}
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
                            Failed to load {view === 'integrations' ? 'integrations' : 'API keys'}.
                        </p>
                        <button
                            type="button"
                            onClick={() => void (view === 'integrations' ? refetchIntegrations() : refetchAPIKeys())}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : view === 'integrations' ? (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={integrations}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Integration',
                                    render: (integration) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-neutral-subtle)] ${categoryMeta[integration.category].color}`}
                                            >
                                                <Icon name={categoryMeta[integration.category].icon} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {integration.name}
                                                </p>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-[var(--color-text-muted)]">
                                                    {integration.provider}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'category',
                                    label: 'Category',
                                    render: (integration) => (
                                        <span className="text-sm">
                                            {categoryMeta[integration.category].label}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (integration) => (
                                        <StatusBadge
                                            label={statusLabels[integration.status]}
                                            variant={statusVariants[integration.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_sync_at',
                                    label: 'Last Sync',
                                    sortable: true,
                                    accessor: (integration) =>
                                        integration.last_sync_at ? formatTimeAgo(integration.last_sync_at) : '—',
                                },
                                {
                                    key: 'created_at',
                                    label: 'Added',
                                    sortable: true,
                                    accessor: (integration) => formatDate(integration.created_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(integration) => setSelectedIntegration(integration)}
                            clickable
                            getRowKey={(integration) => integration.id}
                            emptyState={
                                <EmptyState
                                    icon="plug"
                                    title={hasFilters ? 'No integrations match' : 'No integrations yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Connect your first integration to sync data with external services.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Add integration</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={apiKeys}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'API Key',
                                    render: (apiKey) => (
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-brand-subtle)] text-[var(--color-brand)]">
                                                <Icon name="key" size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {apiKey.name}
                                                </p>
                                                <p className="mt-0.5 font-mono text-xs text-[var(--color-text-muted)]">
                                                    {apiKey.key_prefix}•••••••••••••
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'permissions',
                                    label: 'Permissions',
                                    render: (apiKey) => (
                                        <span className="text-sm">
                                            {apiKey.permissions.length} permissions
                                        </span>
                                    ),
                                },
                                {
                                    key: 'last_used_at',
                                    label: 'Last Used',
                                    sortable: true,
                                    accessor: (apiKey) =>
                                        apiKey.last_used_at ? formatTimeAgo(apiKey.last_used_at) : 'Never',
                                },
                                {
                                    key: 'expires_at',
                                    label: 'Expires',
                                    sortable: true,
                                    render: (apiKey) =>
                                        apiKey.expires_at ? (
                                            <span
                                                className={
                                                    new Date(apiKey.expires_at) < new Date()
                                                        ? 'text-[var(--color-danger)]'
                                                        : ''
                                                }
                                            >
                                                {formatDate(apiKey.expires_at)}
                                            </span>
                                        ) : (
                                            <span className="text-[var(--color-text-muted)]">Never</span>
                                        ),
                                },
                                {
                                    key: 'created_at',
                                    label: 'Created',
                                    sortable: true,
                                    accessor: (apiKey) => formatDate(apiKey.created_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(apiKey) => setSelectedAPIKey(apiKey)}
                            clickable
                            getRowKey={(apiKey) => apiKey.id}
                            emptyState={
                                <EmptyState
                                    icon="key"
                                    title="No API keys yet"
                                    body="Create an API key to access Angisflow programmatically."
                                    action={
                                        <button className="btn btn-primary">
                                            <Icon name="plus" size={16} />
                                            <span>Create API key</span>
                                        </button>
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/* Integration Detail Drawer */}
            <DetailDrawer
                open={!!selectedIntegration}
                onClose={() => setSelectedIntegration(null)}
                title={selectedIntegration?.name ?? ''}
                subtitle={selectedIntegration?.provider ?? ''}
                size="md"
            >
                {selectedIntegration && (
                    <div className="space-y-6">
                        <DrawerSection title="Integration Details">
                            <DrawerField
                                label="Category"
                                value={
                                    <div className="flex items-center gap-2">
                                        <Icon
                                            name={categoryMeta[selectedIntegration.category].icon}
                                            size={16}
                                            className={categoryMeta[selectedIntegration.category].color}
                                        />
                                        <span>{categoryMeta[selectedIntegration.category].label}</span>
                                    </div>
                                }
                                icon="tag"
                            />
                            <DrawerField
                                label="Provider"
                                value={selectedIntegration.provider}
                                icon="package"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedIntegration.status]}
                                        variant={statusVariants[selectedIntegration.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Description">
                            <p className="text-sm text-[var(--color-text-body)]">
                                {selectedIntegration.description}
                            </p>
                        </DrawerSection>

                        <DrawerSection title="Sync Information">
                            <DrawerField
                                label="Last Sync"
                                value={
                                    selectedIntegration.last_sync_at
                                        ? formatTimeAgo(selectedIntegration.last_sync_at)
                                        : 'Not synced yet'
                                }
                                icon="arrow-clockwise"
                            />
                            <DrawerField
                                label="Added"
                                value={formatDate(selectedIntegration.created_at)}
                                icon="calendar"
                            />
                        </DrawerSection>

                        {selectedIntegration.status === 'error' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-danger-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="warning" size={20} className="flex-none text-[var(--color-danger)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">Sync Error</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This integration encountered an error during the last sync. Check the logs
                                            and try reconnecting.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-secondary flex-1">
                                <Icon name="gear" size={16} />
                                <span>Settings</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="arrow-clockwise" size={16} />
                                <span>Sync now</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>

            {/* API Key Detail Drawer */}
            <DetailDrawer
                open={!!selectedAPIKey}
                onClose={() => setSelectedAPIKey(null)}
                title={selectedAPIKey?.name ?? ''}
                subtitle={selectedAPIKey ? `${selectedAPIKey.key_prefix}•••••••••••••` : ''}
                size="md"
            >
                {selectedAPIKey && (
                    <div className="space-y-6">
                        <DrawerSection title="API Key Details">
                            <DrawerField
                                label="Key Prefix"
                                value={
                                    <span className="font-mono text-sm">
                                        {selectedAPIKey.key_prefix}•••••••••••••
                                    </span>
                                }
                                icon="key"
                            />
                            <DrawerField
                                label="Permissions"
                                value={`${selectedAPIKey.permissions.length} permissions`}
                                icon="lock"
                            />
                        </DrawerSection>

                        <DrawerSection title={`Permissions (${selectedAPIKey.permissions.length})`}>
                            <div className="flex flex-wrap gap-2">
                                {selectedAPIKey.permissions.map((permission) => (
                                    <span
                                        key={permission}
                                        className="rounded-md border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] px-2.5 py-1 text-xs text-[var(--color-text-main)]"
                                    >
                                        {permission}
                                    </span>
                                ))}
                            </div>
                        </DrawerSection>

                        <DrawerSection title="Usage">
                            <DrawerField
                                label="Last Used"
                                value={selectedAPIKey.last_used_at ? formatTimeAgo(selectedAPIKey.last_used_at) : 'Never'}
                                icon="clock"
                            />
                            <DrawerField
                                label="Created"
                                value={formatDate(selectedAPIKey.created_at)}
                                icon="calendar"
                            />
                            {selectedAPIKey.expires_at && (
                                <DrawerField
                                    label="Expires"
                                    value={
                                        <span
                                            className={
                                                new Date(selectedAPIKey.expires_at) < new Date()
                                                    ? 'text-[var(--color-danger)]'
                                                    : ''
                                            }
                                        >
                                            {formatDate(selectedAPIKey.expires_at)}
                                        </span>
                                    }
                                    icon="warning"
                                />
                            )}
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-secondary flex-1">
                                <Icon name="copy" size={16} />
                                <span>Copy key</span>
                            </button>
                            <button className="btn btn-secondary text-[var(--color-danger)]">
                                <Icon name="trash" size={16} />
                                <span>Revoke</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
