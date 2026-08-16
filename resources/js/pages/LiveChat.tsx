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

type ChatSession = {
    id: string;
    visitor: {
        id?: string;
        name: string;
        email?: string;
        location?: string;
        browser?: string;
    };
    status: 'active' | 'waiting' | 'ended';
    agent?: {
        id: string;
        name: string;
    };
    started_at: string;
    ended_at?: string;
    duration?: number; // in seconds
    message_count: number;
    last_message: string;
    last_message_at: string;
    page_url: string;
    satisfaction_rating?: number;
};

type LiveChatResponse = {
    data: ChatSession[];
    summary: {
        active_chats: number;
        waiting_queue: number;
        avg_wait_time: number; // in seconds
        avg_chat_duration: number; // in seconds
        chats_today: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function LiveChat() {
    useDocumentTitle('Live Chat');

    // State
    const [view, setView] = useState<'active' | 'waiting' | 'history'>('active');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedSession, setSelectedSession] = useState<ChatSession | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('started_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch chat sessions
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['live-chat', { view, search, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<LiveChatResponse>('/live-chat/sessions', {
                params: {
                    view,
                    search,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const sessions = data?.data ?? [];
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
        setStatusFilter('');
    };

    const hasFilters = search || statusFilter;

    // Format duration
    const formatDuration = (seconds: number) => {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };

    // Format time ago
    const formatTimeAgo = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffInSeconds = (now.getTime() - d.getTime()) / 1000;

        if (diffInSeconds < 60) {
            return `${Math.floor(diffInSeconds)}s ago`;
        } else if (diffInSeconds < 3600) {
            return `${Math.floor(diffInSeconds / 60)}m ago`;
        } else if (diffInSeconds < 86400) {
            return `${Math.floor(diffInSeconds / 3600)}h ago`;
        } else {
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
    };

    // Status variants
    const statusVariants: Record<string, 'success' | 'warning' | 'neutral'> = {
        active: 'success',
        waiting: 'warning',
        ended: 'neutral',
    };

    const statusLabels = {
        active: 'Active',
        waiting: 'Waiting',
        ended: 'Ended',
    };

    // Render satisfaction stars
    const renderStars = (rating: number) => {
        return (
            <div className="flex gap-0.5">
                {[1, 2, 3, 4, 5].map((star) => (
                    <Icon
                        key={star}
                        name="star"
                        size={14}
                        weight={star <= rating ? 'fill' : 'regular'}
                        className={star <= rating ? 'text-[var(--color-warning)]' : 'text-[var(--color-text-muted)]'}
                    />
                ))}
            </div>
        );
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Live Chat"
                    description="Real-time customer support and engagement"
                    icon="chat-text"
                    actions={
                        <div className="flex gap-3">
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Settings')}
                            >
                                <Icon name="gear" size={16} />
                                <span>Settings</span>
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => console.log('Join queue')}
                            >
                                <Icon name="user-circle-check" size={16} />
                                <span>Join queue</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-5">
                    <KPICard
                        label="Active Chats"
                        value={summary.active_chats.toLocaleString()}
                        icon="chat-text"
                        variant="success"
                    />
                    <KPICard
                        label="Waiting"
                        value={summary.waiting_queue.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                    <KPICard
                        label="Avg Wait Time"
                        value={formatDuration(summary.avg_wait_time)}
                        icon="timer"
                        variant="info"
                    />
                    <KPICard
                        label="Avg Chat Duration"
                        value={formatDuration(summary.avg_chat_duration)}
                        icon="hourglass"
                        variant="brand"
                    />
                    <KPICard
                        label="Chats Today"
                        value={summary.chats_today.toLocaleString()}
                        icon="chart-bar"
                        variant="neutral"
                    />
                </div>
            )}

            {/* View Tabs */}
            <div className="mt-4 px-6">
                <div className="flex gap-2">
                    <ViewToggleButton
                        icon="chat-circle-dots"
                        label="Active"
                        active={view === 'active'}
                        onClick={() => setView('active')}
                        badge={summary?.active_chats}
                    />
                    <ViewToggleButton
                        icon="clock"
                        label="Waiting"
                        active={view === 'waiting'}
                        onClick={() => setView('waiting')}
                        badge={summary?.waiting_queue}
                    />
                    <ViewToggleButton
                        icon="clock-counter-clockwise"
                        label="History"
                        active={view === 'history'}
                        onClick={() => setView('history')}
                    />
                </div>
            </div>

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search visitors, agents, or messages..."
                    filters={
                        <>
                            {view === 'history' && (
                                <FilterSelect
                                    label="Status"
                                    value={statusFilter}
                                    onChange={setStatusFilter}
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        { value: 'waiting', label: 'Waiting' },
                                        { value: 'ended', label: 'Ended' },
                                    ]}
                                    placeholder="All statuses"
                                />
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
                            Failed to load chat sessions.
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
                            data={sessions}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'visitor',
                                    label: 'Visitor',
                                    render: (session) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-full ${
                                                    session.status === 'active'
                                                        ? 'bg-[var(--color-success)] text-white'
                                                        : session.status === 'waiting'
                                                          ? 'bg-[var(--color-warning)] text-white'
                                                          : 'bg-[var(--color-neutral-subtle)] text-[var(--color-text-muted)]'
                                                }`}
                                            >
                                                <Icon name="user" size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {session.visitor.name}
                                                </p>
                                                <div className="mt-0.5 flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                                                    {session.visitor.location && (
                                                        <>
                                                            <Icon name="map-pin" size={12} />
                                                            <span>{session.visitor.location}</span>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'last_message',
                                    label: 'Last Message',
                                    render: (session) => (
                                        <p className="line-clamp-2 text-sm text-[var(--color-text-body)]">
                                            {session.last_message}
                                        </p>
                                    ),
                                },
                                {
                                    key: 'agent',
                                    label: 'Agent',
                                    render: (session) => (
                                        <span className="text-sm text-[var(--color-text-body)]">
                                            {session.agent?.name || '—'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'duration',
                                    label: 'Duration',
                                    render: (session) => {
                                        if (session.duration) {
                                            return (
                                                <span className="tabular-nums text-sm">
                                                    {formatDuration(session.duration)}
                                                </span>
                                            );
                                        }
                                        if (session.status === 'active') {
                                            const duration = Math.floor(
                                                (new Date().getTime() - new Date(session.started_at).getTime()) / 1000,
                                            );
                                            return (
                                                <span className="tabular-nums text-sm text-[var(--color-success)]">
                                                    {formatDuration(duration)}
                                                </span>
                                            );
                                        }
                                        return <span className="text-sm">—</span>;
                                    },
                                },
                                {
                                    key: 'message_count',
                                    label: 'Messages',
                                    align: 'center',
                                    accessor: (session) => session.message_count.toString(),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (session) => (
                                        <StatusBadge
                                            label={statusLabels[session.status]}
                                            variant={statusVariants[session.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'satisfaction_rating',
                                    label: 'Rating',
                                    render: (session) =>
                                        session.satisfaction_rating ? (
                                            renderStars(session.satisfaction_rating)
                                        ) : (
                                            <span className="text-sm text-[var(--color-text-muted)]">—</span>
                                        ),
                                },
                                {
                                    key: 'last_message_at',
                                    label: 'Last Activity',
                                    sortable: true,
                                    accessor: (session) => formatTimeAgo(session.last_message_at),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(session) => setSelectedSession(session)}
                            clickable
                            getRowKey={(session) => session.id}
                            emptyState={
                                <EmptyState
                                    icon="chat-text"
                                    title={
                                        hasFilters
                                            ? 'No chats match'
                                            : view === 'active'
                                              ? 'No active chats'
                                              : view === 'waiting'
                                                ? 'No waiting visitors'
                                                : 'No chat history'
                                    }
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : view === 'active'
                                              ? 'All quiet! No active chat sessions at the moment.'
                                              : view === 'waiting'
                                                ? 'No visitors waiting for support right now.'
                                                : 'Chat history will appear here as you support customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : view === 'waiting' ? (
                                            <button className="btn btn-primary">
                                                <Icon name="user-circle-check" size={16} />
                                                <span>Join queue</span>
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
                open={!!selectedSession}
                onClose={() => setSelectedSession(null)}
                title={selectedSession?.visitor.name ?? ''}
                subtitle={selectedSession?.page_url ?? ''}
                size="lg"
            >
                {selectedSession && (
                    <div className="space-y-6">
                        <DrawerSection title="Chat Details">
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedSession.status]}
                                        variant={statusVariants[selectedSession.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField
                                label="Started"
                                value={new Date(selectedSession.started_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="clock"
                            />
                            {selectedSession.ended_at && (
                                <DrawerField
                                    label="Ended"
                                    value={new Date(selectedSession.ended_at).toLocaleString('en-US', {
                                        year: 'numeric',
                                        month: 'short',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                    icon="clock"
                                />
                            )}
                            <DrawerField
                                label="Duration"
                                value={
                                    selectedSession.duration
                                        ? formatDuration(selectedSession.duration)
                                        : formatDuration(
                                              Math.floor(
                                                  (new Date().getTime() -
                                                      new Date(selectedSession.started_at).getTime()) /
                                                      1000,
                                              ),
                                          )
                                }
                                icon="hourglass"
                            />
                            <DrawerField
                                label="Messages"
                                value={selectedSession.message_count.toString()}
                                icon="chat-text"
                            />
                        </DrawerSection>

                        <DrawerSection title="Visitor Information">
                            <DrawerField
                                label="Name"
                                value={selectedSession.visitor.name}
                                icon="user"
                            />
                            {selectedSession.visitor.email && (
                                <DrawerField
                                    label="Email"
                                    value={selectedSession.visitor.email}
                                    icon="envelope"
                                />
                            )}
                            {selectedSession.visitor.location && (
                                <DrawerField
                                    label="Location"
                                    value={selectedSession.visitor.location}
                                    icon="map-pin"
                                />
                            )}
                            {selectedSession.visitor.browser && (
                                <DrawerField
                                    label="Browser"
                                    value={selectedSession.visitor.browser}
                                    icon="browser"
                                />
                            )}
                            <DrawerField
                                label="Current Page"
                                value={selectedSession.page_url}
                                icon="link"
                            />
                        </DrawerSection>

                        {selectedSession.agent && (
                            <DrawerSection title="Assignment">
                                <DrawerField
                                    label="Assigned Agent"
                                    value={selectedSession.agent.name}
                                    icon="user-focus"
                                />
                            </DrawerSection>
                        )}

                        {selectedSession.satisfaction_rating && (
                            <DrawerSection title="Satisfaction Rating">
                                <DrawerField
                                    label="Rating"
                                    value={renderStars(selectedSession.satisfaction_rating)}
                                    icon="star"
                                />
                            </DrawerSection>
                        )}

                        <DrawerSection title="Last Message">
                            <div className="rounded-md border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] p-4">
                                <p className="text-sm text-[var(--color-text-body)]">
                                    {selectedSession.last_message}
                                </p>
                                <p className="mt-2 text-xs text-[var(--color-text-muted)]">
                                    {formatTimeAgo(selectedSession.last_message_at)}
                                </p>
                            </div>
                        </DrawerSection>

                        {selectedSession.status === 'active' && (
                            <div className="flex gap-3 pt-4">
                                <button className="btn btn-primary flex-1">
                                    <Icon name="chat-text" size={16} />
                                    <span>Open chat</span>
                                </button>
                                <button className="btn btn-secondary">
                                    <Icon name="phone-disconnect" size={16} />
                                    <span>End chat</span>
                                </button>
                            </div>
                        )}

                        {selectedSession.status === 'waiting' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-warning-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon
                                        name="clock"
                                        size={20}
                                        className="flex-none text-[var(--color-warning)]"
                                    />
                                    <div className="flex-1">
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            Visitor Waiting
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This visitor is waiting for an agent to accept the chat.
                                        </p>
                                        <button className="btn btn-primary mt-3">
                                            <Icon name="user-circle-check" size={16} />
                                            <span>Accept chat</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
