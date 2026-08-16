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

type Channel = {
    id: string;
    name: string;
    type: 'email' | 'sms' | 'whatsapp' | 'facebook' | 'instagram' | 'twitter' | 'webhook' | 'api';
    status: 'active' | 'inactive' | 'error';
    is_default: boolean;
    config: {
        identifier?: string; // email, phone number, account ID
        provider?: string;
    };
    stats: {
        messages_today: number;
        messages_this_month: number;
        avg_response_time: number; // in minutes
    };
    created_at: string;
    last_message_at?: string;
};

type ChannelsResponse = {
    data: Channel[];
    summary: {
        total_channels: number;
        active_channels: number;
        total_messages_today: number;
        total_messages_month: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Channels() {
    useDocumentTitle('Channels');

    // State
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedChannel, setSelectedChannel] = useState<Channel | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch channels
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['channels', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<ChannelsResponse>('/channels', {
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

    const channels = data?.data ?? [];
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
        setTypeFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || typeFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Format response time
    const formatResponseTime = (minutes: number) => {
        if (minutes < 60) {
            return `${Math.round(minutes)}m`;
        } else {
            return `${Math.round(minutes / 60)}h`;
        }
    };

    // Channel icons
    const channelIcons: Record<Channel['type'], string> = {
        email: 'envelope',
        sms: 'chat-text',
        whatsapp: 'whatsapp-logo',
        facebook: 'facebook-logo',
        instagram: 'instagram-logo',
        twitter: 'twitter-logo',
        webhook: 'webhooks',
        api: 'code',
    };

    // Channel labels
    const channelLabels: Record<string, string> = {
        email: 'Email',
        sms: 'SMS',
        whatsapp: 'WhatsApp',
        facebook: 'Facebook',
        instagram: 'Instagram',
        twitter: 'Twitter',
        webhook: 'Webhook',
        api: 'API',
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'neutral' | 'danger'> = {
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
                    title="Channels"
                    description="Connect and manage communication channels"
                    icon="plug"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Add channel')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Add channel</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Channels"
                        value={summary.total_channels.toLocaleString()}
                        icon="plug"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_channels.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Messages Today"
                        value={summary.total_messages_today.toLocaleString()}
                        icon="chat-text"
                        variant="info"
                    />
                    <KPICard
                        label="Messages This Month"
                        value={summary.total_messages_month.toLocaleString()}
                        icon="chart-line"
                        variant="neutral"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search channels..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'email', label: 'Email' },
                                    { value: 'sms', label: 'SMS' },
                                    { value: 'whatsapp', label: 'WhatsApp' },
                                    { value: 'facebook', label: 'Facebook' },
                                    { value: 'instagram', label: 'Instagram' },
                                    { value: 'twitter', label: 'Twitter' },
                                    { value: 'webhook', label: 'Webhook' },
                                    { value: 'api', label: 'API' },
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
                                    { value: 'error', label: 'Error' },
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
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load channels.
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
                        <Table
                            data={channels}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Channel',
                                    render: (channel) => (
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-brand-subtle)] text-[var(--color-brand)]">
                                                <Icon name={channelIcons[channel.type]} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-medium text-[var(--color-text-main)]">
                                                        {channel.name}
                                                    </p>
                                                    {channel.is_default && (
                                                        <span className="rounded bg-[var(--color-brand-subtle)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--color-brand)]">
                                                            Default
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {channelLabels[channel.type]}
                                                    {channel.config.identifier && ` • ${channel.config.identifier}`}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'messages_today',
                                    label: 'Today',
                                    align: 'right',
                                    sortable: true,
                                    render: (channel) => (
                                        <span className="tabular-nums font-medium">
                                            {channel.stats.messages_today.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'messages_month',
                                    label: 'This Month',
                                    align: 'right',
                                    sortable: true,
                                    render: (channel) => (
                                        <span className="tabular-nums">
                                            {channel.stats.messages_this_month.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'avg_response_time',
                                    label: 'Avg Response',
                                    align: 'right',
                                    sortable: true,
                                    render: (channel) => (
                                        <span className="tabular-nums text-sm">
                                            {formatResponseTime(channel.stats.avg_response_time)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (channel) => (
                                        <StatusBadge
                                            label={statusLabels[channel.status]}
                                            variant={statusVariants[channel.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_message_at',
                                    label: 'Last Message',
                                    sortable: true,
                                    accessor: (channel) =>
                                        channel.last_message_at ? formatDate(channel.last_message_at) : '—',
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(channel) => setSelectedChannel(channel)}
                            clickable
                            getRowKey={(channel) => channel.id}
                            emptyState={
                                <EmptyState
                                    icon="plug"
                                    title={hasFilters ? 'No channels match' : 'No channels yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Connect your first communication channel to start receiving messages from customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary" onClick={() => console.log('Add')}>
                                                <Icon name="plus" size={16} />
                                                <span>Add channel</span>
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
                open={!!selectedChannel}
                onClose={() => setSelectedChannel(null)}
                title={selectedChannel?.name ?? ''}
                subtitle={selectedChannel ? channelLabels[selectedChannel.type] : ''}
                size="md"
            >
                {selectedChannel && (
                    <div className="space-y-6">
                        <DrawerSection title="Channel Details">
                            <DrawerField
                                label="Type"
                                value={
                                    <div className="flex items-center gap-2">
                                        <Icon name={channelIcons[selectedChannel.type]} size={16} />
                                        <span>{channelLabels[selectedChannel.type]}</span>
                                    </div>
                                }
                                icon="tag"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedChannel.status]}
                                        variant={statusVariants[selectedChannel.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            {selectedChannel.is_default && (
                                <DrawerField
                                    label="Default Channel"
                                    value="Yes"
                                    icon="star"
                                />
                            )}
                            {selectedChannel.config.identifier && (
                                <DrawerField
                                    label="Identifier"
                                    value={selectedChannel.config.identifier}
                                    icon="hash"
                                />
                            )}
                            {selectedChannel.config.provider && (
                                <DrawerField
                                    label="Provider"
                                    value={selectedChannel.config.provider}
                                    icon="package"
                                />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Statistics">
                            <DrawerField
                                label="Messages Today"
                                value={selectedChannel.stats.messages_today.toLocaleString()}
                                icon="chat-text"
                            />
                            <DrawerField
                                label="Messages This Month"
                                value={selectedChannel.stats.messages_this_month.toLocaleString()}
                                icon="chart-line"
                            />
                            <DrawerField
                                label="Average Response Time"
                                value={formatResponseTime(selectedChannel.stats.avg_response_time)}
                                icon="timer"
                            />
                        </DrawerSection>

                        <DrawerSection title="Timeline">
                            <DrawerField
                                label="Created"
                                value={new Date(selectedChannel.created_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="calendar"
                            />
                            {selectedChannel.last_message_at && (
                                <DrawerField
                                    label="Last Message"
                                    value={new Date(selectedChannel.last_message_at).toLocaleString('en-US', {
                                        year: 'numeric',
                                        month: 'short',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                    icon="clock"
                                />
                            )}
                        </DrawerSection>

                        {selectedChannel.status === 'error' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-danger-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon
                                        name="warning"
                                        size={20}
                                        className="flex-none text-[var(--color-danger)]"
                                    />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            Connection Error
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This channel is experiencing connection issues. Check your configuration and try reconnecting.
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
                                <span>Test connection</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
