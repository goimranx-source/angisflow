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

type AuditEntry = {
    id: string;
    event: string;
    action: 'create' | 'update' | 'delete' | 'view' | 'login' | 'logout' | 'export';
    resource_type: string;
    resource_id?: string;
    user: {
        id: string;
        name: string;
        email: string;
    };
    ip_address: string;
    user_agent: string;
    changes?: Record<string, { old: unknown; new: unknown }>;
    metadata?: Record<string, unknown>;
    created_at: string;
};

type AuditLogResponse = {
    data: AuditEntry[];
    summary: {
        total_events: number;
        events_today: number;
        unique_users: number;
        critical_events: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function AuditLog() {
    useDocumentTitle('Audit Log');

    // State
    const [search, setSearch] = useState('');
    const [actionFilter, setActionFilter] = useState('');
    const [resourceFilter, setResourceFilter] = useState('');
    const [userFilter, setUserFilter] = useState('');
    const [selectedEntry, setSelectedEntry] = useState<AuditEntry | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch audit log
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['audit-log', { search, actionFilter, resourceFilter, userFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<AuditLogResponse>('/audit-log', {
                params: {
                    search,
                    action: actionFilter || undefined,
                    resource_type: resourceFilter || undefined,
                    user: userFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const entries = data?.data ?? [];
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
        setActionFilter('');
        setResourceFilter('');
        setUserFilter('');
    };

    const hasFilters = search || actionFilter || resourceFilter || userFilter;

    // Format date with time
    const formatDateTime = (date: string) => {
        return new Date(date).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    };

    // Format time ago
    const formatTimeAgo = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffInSeconds = (now.getTime() - d.getTime()) / 1000;

        if (diffInSeconds < 60) return `${Math.floor(diffInSeconds)}s ago`;
        if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
        if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
        return `${Math.floor(diffInSeconds / 86400)}d ago`;
    };

    // Action variants and metadata
    const actionMeta: Record<AuditEntry['action'], { label: string; icon: string; variant: 'success' | 'info' | 'warning' | 'danger' | 'neutral' }> = {
        create: { label: 'Created', icon: 'plus-circle', variant: 'success' },
        update: { label: 'Updated', icon: 'pencil-simple', variant: 'info' },
        delete: { label: 'Deleted', icon: 'trash', variant: 'danger' },
        view: { label: 'Viewed', icon: 'eye', variant: 'neutral' },
        login: { label: 'Login', icon: 'sign-in', variant: 'info' },
        logout: { label: 'Logout', icon: 'sign-out', variant: 'neutral' },
        export: { label: 'Exported', icon: 'download-simple', variant: 'warning' },
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Audit Log"
                    description="Track all activities and changes in your system"
                    icon="list-magnifying-glass"
                    actions={
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => console.log('Export log')}
                        >
                            <Icon name="download-simple" size={16} />
                            <span>Export</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Events"
                        value={summary.total_events.toLocaleString()}
                        icon="list-magnifying-glass"
                        variant="brand"
                    />
                    <KPICard
                        label="Today"
                        value={summary.events_today.toLocaleString()}
                        icon="calendar"
                        variant="info"
                    />
                    <KPICard
                        label="Active Users"
                        value={summary.unique_users.toLocaleString()}
                        icon="users-three"
                        variant="success"
                    />
                    <KPICard
                        label="Critical Events"
                        value={summary.critical_events.toLocaleString()}
                        icon="warning"
                        variant="danger"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search events, users, or resources..."
                    filters={
                        <>
                            <FilterSelect
                                label="Action"
                                value={actionFilter}
                                onChange={setActionFilter}
                                options={[
                                    { value: 'create', label: 'Created' },
                                    { value: 'update', label: 'Updated' },
                                    { value: 'delete', label: 'Deleted' },
                                    { value: 'view', label: 'Viewed' },
                                    { value: 'login', label: 'Login' },
                                    { value: 'logout', label: 'Logout' },
                                    { value: 'export', label: 'Exported' },
                                ]}
                                placeholder="All actions"
                            />
                            <FilterSelect
                                label="Resource"
                                value={resourceFilter}
                                onChange={setResourceFilter}
                                options={[
                                    { value: 'user', label: 'User' },
                                    { value: 'order', label: 'Order' },
                                    { value: 'product', label: 'Product' },
                                    { value: 'customer', label: 'Customer' },
                                    { value: 'transaction', label: 'Transaction' },
                                    { value: 'document', label: 'Document' },
                                ]}
                                placeholder="All resources"
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load audit log.</p>
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
                            data={entries}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'action',
                                    label: 'Action',
                                    render: (entry) => (
                                        <div className="flex items-center gap-2">
                                            <div
                                                className={`flex h-8 w-8 flex-none items-center justify-center rounded-md bg-[var(--shell-tint)]`}
                                            >
                                                <Icon
                                                    name={actionMeta[entry.action].icon}
                                                    size={16}
                                                    className={`text-[var(--color-${actionMeta[entry.action].variant})]`}
                                                />
                                            </div>
                                            <StatusBadge
                                                label={actionMeta[entry.action].label}
                                                variant={actionMeta[entry.action].variant}
                                            />
                                        </div>
                                    ),
                                },
                                {
                                    key: 'event',
                                    label: 'Event',
                                    render: (entry) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {entry.event}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {entry.resource_type}
                                                {entry.resource_id && ` #${entry.resource_id}`}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'user',
                                    label: 'User',
                                    render: (entry) => (
                                        <div>
                                            <p className="text-sm font-medium text-[var(--color-text-main)]">
                                                {entry.user.name}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {entry.user.email}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'ip_address',
                                    label: 'IP Address',
                                    render: (entry) => (
                                        <span className="font-mono text-xs text-[var(--color-text-body)]">
                                            {entry.ip_address}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'created_at',
                                    label: 'Time',
                                    sortable: true,
                                    render: (entry) => (
                                        <div>
                                            <p className="text-sm text-[var(--color-text-main)]">
                                                {formatTimeAgo(entry.created_at)}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {formatDateTime(entry.created_at)}
                                            </p>
                                        </div>
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(entry) => setSelectedEntry(entry)}
                            clickable
                            getRowKey={(entry) => entry.id}
                            emptyState={
                                <EmptyState
                                    icon="list-magnifying-glass"
                                    title={hasFilters ? 'No events match' : 'No events yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Audit events will appear here as users interact with the system.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : null
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedEntry}
                onClose={() => setSelectedEntry(null)}
                title={selectedEntry?.event ?? ''}
                subtitle={
                    selectedEntry
                        ? `${actionMeta[selectedEntry.action].label} by ${selectedEntry.user.name}`
                        : ''
                }
                size="lg"
            >
                {selectedEntry && (
                    <div className="space-y-6">
                        <DrawerSection title="Event Details">
                            <DrawerField
                                label="Action"
                                value={
                                    <StatusBadge
                                        label={actionMeta[selectedEntry.action].label}
                                        variant={actionMeta[selectedEntry.action].variant}
                                    />
                                }
                                icon={actionMeta[selectedEntry.action].icon}
                            />
                            <DrawerField
                                label="Resource Type"
                                value={selectedEntry.resource_type}
                                icon="package"
                            />
                            {selectedEntry.resource_id && (
                                <DrawerField
                                    label="Resource ID"
                                    value={selectedEntry.resource_id}
                                    icon="hash"
                                />
                            )}
                            <DrawerField
                                label="Timestamp"
                                value={formatDateTime(selectedEntry.created_at)}
                                icon="clock"
                            />
                        </DrawerSection>

                        <DrawerSection title="User Information">
                            <DrawerField
                                label="Name"
                                value={selectedEntry.user.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Email"
                                value={selectedEntry.user.email}
                                icon="envelope"
                            />
                            <DrawerField
                                label="IP Address"
                                value={<span className="font-mono">{selectedEntry.ip_address}</span>}
                                icon="globe"
                            />
                        </DrawerSection>

                        {selectedEntry.changes && Object.keys(selectedEntry.changes).length > 0 && (
                            <DrawerSection title="Changes">
                                <div className="space-y-3">
                                    {Object.entries(selectedEntry.changes).map(([field, change]) => (
                                        <div
                                            key={field}
                                            className="rounded-lg border border-[var(--color-border-light)] bg-[var(--shell-tint)] p-3"
                                        >
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">
                                                {field}
                                            </p>
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <div>
                                                    <p className="text-xs text-[var(--color-text-muted)]">Old Value</p>
                                                    <p className="mt-1 font-mono text-sm text-[var(--color-danger)]">
                                                        {String(change.old)}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-[var(--color-text-muted)]">New Value</p>
                                                    <p className="mt-1 font-mono text-sm text-[var(--color-success)]">
                                                        {String(change.new)}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Technical Details">
                            <DrawerField
                                label="User Agent"
                                value={<span className="break-all font-mono text-xs">{selectedEntry.user_agent}</span>}
                                icon="browser"
                            />
                            <DrawerField label="Event ID" value={selectedEntry.id} icon="fingerprint" />
                        </DrawerSection>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
