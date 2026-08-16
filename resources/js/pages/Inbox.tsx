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

type Conversation = {
    id: string;
    subject: string;
    contact: {
        id: string;
        name: string;
        email: string;
        phone?: string;
    };
    channel: 'email' | 'sms' | 'whatsapp' | 'chat' | 'phone';
    status: 'open' | 'pending' | 'resolved' | 'closed';
    priority: 'low' | 'medium' | 'high' | 'urgent';
    assigned_to?: {
        id: string;
        name: string;
    };
    last_message: string;
    last_message_at: string;
    unread_count: number;
    created_at: string;
};

type InboxResponse = {
    data: Conversation[];
    summary: {
        total_conversations: number;
        open: number;
        pending: number;
        unread: number;
        avg_response_time: number; // in minutes
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Inbox() {
    useDocumentTitle('Inbox');

    // State
    const [view, setView] = useState<'all' | 'unread' | 'mine'>('all');
    const [search, setSearch] = useState('');
    const [channelFilter, setChannelFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [priorityFilter, setPriorityFilter] = useState('');
    const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('last_message_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch conversations
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['inbox', { view, search, channelFilter, statusFilter, priorityFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<InboxResponse>('/inbox/conversations', {
                params: {
                    view,
                    search,
                    channel: channelFilter || undefined,
                    status: statusFilter || undefined,
                    priority: priorityFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const conversations = data?.data ?? [];
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
        setChannelFilter('');
        setStatusFilter('');
        setPriorityFilter('');
    };

    const hasFilters = search || channelFilter || statusFilter || priorityFilter;

    // Format date
    const formatDate = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffInHours = (now.getTime() - d.getTime()) / (1000 * 60 * 60);

        if (diffInHours < 1) {
            const minutes = Math.floor(diffInHours * 60);
            return `${minutes}m ago`;
        } else if (diffInHours < 24) {
            return `${Math.floor(diffInHours)}h ago`;
        } else if (diffInHours < 48) {
            return 'Yesterday';
        } else {
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
    };

    // Format response time
    const formatResponseTime = (minutes: number) => {
        if (minutes < 60) {
            return `${Math.round(minutes)}m`;
        } else if (minutes < 1440) {
            return `${Math.round(minutes / 60)}h`;
        } else {
            return `${Math.round(minutes / 1440)}d`;
        }
    };

    // Channel icons
    const channelIcons: Record<Conversation['channel'], string> = {
        email: 'envelope',
        sms: 'chat-text',
        whatsapp: 'whatsapp-logo',
        chat: 'chats-circle',
        phone: 'phone',
    };

    // Channel labels
    const channelLabels: Record<string, string> = {
        email: 'Email',
        sms: 'SMS',
        whatsapp: 'WhatsApp',
        chat: 'Live Chat',
        phone: 'Phone',
    };

    // Status variants
    const statusVariants: Record<string, 'info' | 'warning' | 'success' | 'neutral'> = {
        open: 'info',
        pending: 'warning',
        resolved: 'success',
        closed: 'neutral',
    };

    const statusLabels = {
        open: 'Open',
        pending: 'Pending',
        resolved: 'Resolved',
        closed: 'Closed',
    };

    // Priority variants
    const priorityVariants: Record<string, 'neutral' | 'info' | 'warning' | 'danger'> = {
        low: 'neutral',
        medium: 'info',
        high: 'warning',
        urgent: 'danger',
    };

    const priorityLabels = {
        low: 'Low',
        medium: 'Medium',
        high: 'High',
        urgent: 'Urgent',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Inbox"
                    description="Manage all customer conversations in one place"
                    icon="chats-circle"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('New conversation')}
                        >
                            <Icon name="plus" size={16} />
                            <span>New conversation</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Conversations"
                        value={summary.total_conversations.toLocaleString()}
                        icon="chats-circle"
                        variant="brand"
                    />
                    <KPICard
                        label="Open"
                        value={summary.open.toLocaleString()}
                        icon="circle-notch"
                        variant="info"
                    />
                    <KPICard
                        label="Pending"
                        value={summary.pending.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                    <KPICard
                        label="Avg Response Time"
                        value={formatResponseTime(summary.avg_response_time)}
                        icon="timer"
                        variant="success"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 px-6">
                <div className="flex gap-2">
                    <ViewToggleButton
                        icon="list"
                        label="All"
                        active={view === 'all'}
                        onClick={() => setView('all')}
                    />
                    <ViewToggleButton
                        icon="envelope-open"
                        label="Unread"
                        active={view === 'unread'}
                        onClick={() => setView('unread')}
                    />
                    <ViewToggleButton
                        icon="user"
                        label="Mine"
                        active={view === 'mine'}
                        onClick={() => setView('mine')}
                    />
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search conversations, contacts, or messages..."
                    filters={
                        <>
                            <FilterSelect
                                label="Channel"
                                value={channelFilter}
                                onChange={setChannelFilter}
                                options={[
                                    { value: 'email', label: 'Email' },
                                    { value: 'sms', label: 'SMS' },
                                    { value: 'whatsapp', label: 'WhatsApp' },
                                    { value: 'chat', label: 'Live Chat' },
                                    { value: 'phone', label: 'Phone' },
                                ]}
                                placeholder="All channels"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'open', label: 'Open' },
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'resolved', label: 'Resolved' },
                                    { value: 'closed', label: 'Closed' },
                                ]}
                                placeholder="All statuses"
                            />
                            <FilterSelect
                                label="Priority"
                                value={priorityFilter}
                                onChange={setPriorityFilter}
                                options={[
                                    { value: 'low', label: 'Low' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'high', label: 'High' },
                                    { value: 'urgent', label: 'Urgent' },
                                ]}
                                placeholder="All priorities"
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
                            Failed to load conversations.
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
                            data={conversations}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'contact',
                                    label: 'Contact',
                                    render: (conv) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-full ${
                                                    conv.unread_count > 0
                                                        ? 'bg-[var(--color-brand)] text-white'
                                                        : 'bg-[var(--color-neutral-subtle)] text-[var(--color-text-muted)]'
                                                }`}
                                            >
                                                <Icon name="user" size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {conv.contact.name}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-[var(--color-text-muted)]">
                                                    {conv.subject}
                                                </p>
                                            </div>
                                            {conv.unread_count > 0 && (
                                                <div className="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-[var(--color-brand)] text-[10px] font-semibold text-white">
                                                    {conv.unread_count}
                                                </div>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'last_message',
                                    label: 'Last Message',
                                    render: (conv) => (
                                        <p className="line-clamp-2 text-sm text-[var(--color-text-body)]">
                                            {conv.last_message}
                                        </p>
                                    ),
                                },
                                {
                                    key: 'channel',
                                    label: 'Channel',
                                    render: (conv) => (
                                        <div className="flex items-center gap-2">
                                            <Icon
                                                name={channelIcons[conv.channel]}
                                                size={16}
                                                className="text-[var(--color-text-muted)]"
                                            />
                                            <span className="text-sm">
                                                {channelLabels[conv.channel]}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'priority',
                                    label: 'Priority',
                                    render: (conv) => (
                                        <StatusBadge
                                            label={priorityLabels[conv.priority]}
                                            variant={priorityVariants[conv.priority]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'assigned_to',
                                    label: 'Assigned',
                                    render: (conv) => (
                                        <span className="text-sm text-[var(--color-text-body)]">
                                            {conv.assigned_to?.name || '—'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (conv) => (
                                        <StatusBadge
                                            label={statusLabels[conv.status]}
                                            variant={statusVariants[conv.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_message_at',
                                    label: 'Last Activity',
                                    sortable: true,
                                    accessor: (conv) => formatDate(conv.last_message_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(conv) => setSelectedConversation(conv)}
                            clickable
                            getRowKey={(conv) => conv.id}
                            emptyState={
                                <EmptyState
                                    icon="chats-circle"
                                    title={
                                        hasFilters
                                            ? 'No conversations match'
                                            : view === 'unread'
                                              ? 'No unread messages'
                                              : view === 'mine'
                                                ? 'No assigned conversations'
                                                : 'No conversations yet'
                                    }
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : view === 'unread'
                                              ? 'All caught up! No unread messages at the moment.'
                                              : view === 'mine'
                                                ? 'You have no conversations assigned to you.'
                                                : 'Start a new conversation to begin communicating with customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary" onClick={() => console.log('New')}>
                                                <Icon name="plus" size={16} />
                                                <span>New conversation</span>
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
                open={!!selectedConversation}
                onClose={() => setSelectedConversation(null)}
                title={selectedConversation?.contact.name ?? ''}
                subtitle={selectedConversation?.subject ?? ''}
                size="lg"
            >
                {selectedConversation && (
                    <div className="space-y-6">
                        <DrawerSection title="Conversation Details">
                            <DrawerField
                                label="Subject"
                                value={selectedConversation.subject}
                                icon="text-align-left"
                            />
                            <DrawerField
                                label="Channel"
                                value={
                                    <div className="flex items-center gap-2">
                                        <Icon
                                            name={channelIcons[selectedConversation.channel]}
                                            size={16}
                                        />
                                        <span>{channelLabels[selectedConversation.channel]}</span>
                                    </div>
                                }
                                icon="plug"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedConversation.status]}
                                        variant={statusVariants[selectedConversation.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField
                                label="Priority"
                                value={
                                    <StatusBadge
                                        label={priorityLabels[selectedConversation.priority]}
                                        variant={priorityVariants[selectedConversation.priority]}
                                        dot
                                    />
                                }
                                icon="flag"
                            />
                        </DrawerSection>

                        <DrawerSection title="Contact Information">
                            <DrawerField
                                label="Name"
                                value={selectedConversation.contact.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Email"
                                value={selectedConversation.contact.email}
                                icon="envelope"
                            />
                            {selectedConversation.contact.phone && (
                                <DrawerField
                                    label="Phone"
                                    value={selectedConversation.contact.phone}
                                    icon="phone"
                                />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Assignment & Timeline">
                            <DrawerField
                                label="Assigned To"
                                value={selectedConversation.assigned_to?.name || 'Unassigned'}
                                icon="user-focus"
                            />
                            <DrawerField
                                label="Created"
                                value={new Date(selectedConversation.created_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Last Activity"
                                value={new Date(selectedConversation.last_message_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="clock"
                            />
                        </DrawerSection>

                        <DrawerSection title="Last Message">
                            <div className="rounded-md border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] p-4">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedConversation.last_message}
                                </p>
                            </div>
                        </DrawerSection>

                        {selectedConversation.unread_count > 0 && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon
                                        name="envelope-open"
                                        size={20}
                                        className="flex-none text-[var(--color-brand)]"
                                    />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            Unread Messages
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This conversation has {selectedConversation.unread_count} unread{' '}
                                            {selectedConversation.unread_count === 1 ? 'message' : 'messages'}.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="chat-text" size={16} />
                                <span>Reply</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="check" size={16} />
                                <span>Mark resolved</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
