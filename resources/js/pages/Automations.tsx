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

type Automation = {
    id: string;
    name: string;
    description: string;
    trigger: {
        type: 'message_received' | 'keyword' | 'schedule' | 'event' | 'condition';
        value: string;
    };
    actions: Array<{
        type: 'send_message' | 'assign_agent' | 'tag' | 'webhook' | 'notification';
        config: Record<string, unknown>;
    }>;
    status: 'active' | 'inactive' | 'draft';
    execution_count: number;
    success_rate: number; // percentage
    last_executed_at?: string;
    created_by: {
        id: string;
        name: string;
    };
    created_at: string;
};

type AutomationsResponse = {
    data: Automation[];
    summary: {
        total_automations: number;
        active_automations: number;
        total_executions: number;
        avg_success_rate: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Automations() {
    useDocumentTitle('Automations');

    // State
    const [search, setSearch] = useState('');
    const [triggerFilter, setTriggerFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedAutomation, setSelectedAutomation] = useState<Automation | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('execution_count');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch automations
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['automations', { search, triggerFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<AutomationsResponse>('/automations', {
                params: {
                    search,
                    trigger: triggerFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const automations = data?.data ?? [];
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
        setTriggerFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || triggerFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Trigger labels & icons
    const triggerMeta: Record<Automation['trigger']['type'], { label: string; icon: string; color: string }> = {
        message_received: { label: 'Message Received', icon: 'envelope', color: 'text-[var(--color-info)]' },
        keyword: { label: 'Keyword Match', icon: 'text-aa', color: 'text-[var(--color-brand)]' },
        schedule: { label: 'Schedule', icon: 'clock', color: 'text-[var(--color-warning)]' },
        event: { label: 'Event', icon: 'lightning', color: 'text-[var(--color-success)]' },
        condition: { label: 'Condition', icon: 'funnel', color: 'text-[var(--color-text-muted)]' },
    };

    // Action type labels
    const actionTypeLabels: Record<Automation['actions'][number]['type'], string> = {
        send_message: 'Send Message',
        assign_agent: 'Assign Agent',
        tag: 'Add Tag',
        webhook: 'Webhook',
        notification: 'Notification',
    };

    // Status variants
    const statusVariants: Record<Automation['status'], 'success' | 'neutral' | 'info'> = {
        active: 'success',
        inactive: 'neutral',
        draft: 'info',
    };

    const statusLabels = {
        active: 'Active',
        inactive: 'Inactive',
        draft: 'Draft',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Automations"
                    description="Automate responses and workflows to save time"
                    icon="flow-arrow"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Create automation')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Create automation</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Automations"
                        value={summary.total_automations.toLocaleString()}
                        icon="flow-arrow"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_automations.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Total Executions"
                        value={summary.total_executions.toLocaleString()}
                        icon="arrow-clockwise"
                        variant="info"
                    />
                    <KPICard
                        label="Avg Success Rate"
                        value={`${Math.round(summary.avg_success_rate)}%`}
                        icon="chart-line"
                        variant="warning"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search automations..."
                    filters={
                        <>
                            <FilterSelect
                                label="Trigger"
                                value={triggerFilter}
                                onChange={setTriggerFilter}
                                options={[
                                    { value: 'message_received', label: 'Message Received' },
                                    { value: 'keyword', label: 'Keyword Match' },
                                    { value: 'schedule', label: 'Schedule' },
                                    { value: 'event', label: 'Event' },
                                    { value: 'condition', label: 'Condition' },
                                ]}
                                placeholder="All triggers"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                    { value: 'draft', label: 'Draft' },
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
                            Failed to load automations.
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
                            data={automations}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Automation',
                                    render: (automation) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--shell-tint)] ${triggerMeta[automation.trigger.type].color}`}
                                            >
                                                <Icon name={triggerMeta[automation.trigger.type].icon} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {automation.name}
                                                </p>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-[var(--color-text-muted)]">
                                                    {automation.description}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'trigger',
                                    label: 'Trigger',
                                    render: (automation) => (
                                        <div>
                                            <p className="text-sm font-medium text-[var(--color-text-main)]">
                                                {triggerMeta[automation.trigger.type].label}
                                            </p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {automation.trigger.value}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'actions',
                                    label: 'Actions',
                                    render: (automation) => (
                                        <div className="flex flex-wrap gap-1">
                                            {automation.actions.slice(0, 2).map((action, idx) => (
                                                <span
                                                    key={idx}
                                                    className="rounded bg-[var(--color-brand-subtle)] px-2 py-0.5 text-xs text-[var(--color-brand)]"
                                                >
                                                    {actionTypeLabels[action.type]}
                                                </span>
                                            ))}
                                            {automation.actions.length > 2 && (
                                                <span className="text-xs text-[var(--color-text-muted)]">
                                                    +{automation.actions.length - 2}
                                                </span>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'execution_count',
                                    label: 'Executions',
                                    align: 'right',
                                    sortable: true,
                                    render: (automation) => (
                                        <span className="tabular-nums font-medium">
                                            {automation.execution_count.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'success_rate',
                                    label: 'Success Rate',
                                    align: 'right',
                                    sortable: true,
                                    render: (automation) => (
                                        <div className="flex items-center justify-end gap-2">
                                            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--shell-tint)]">
                                                <div
                                                    className={`h-full ${
                                                        automation.success_rate >= 80
                                                            ? 'bg-[var(--color-success)]'
                                                            : automation.success_rate >= 50
                                                              ? 'bg-[var(--color-warning)]'
                                                              : 'bg-[var(--color-danger)]'
                                                    }`}
                                                    style={{ width: `${automation.success_rate}%` }}
                                                />
                                            </div>
                                            <span className="w-10 text-right text-xs tabular-nums">
                                                {Math.round(automation.success_rate)}%
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (automation) => (
                                        <StatusBadge
                                            label={statusLabels[automation.status]}
                                            variant={statusVariants[automation.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_executed_at',
                                    label: 'Last Run',
                                    sortable: true,
                                    accessor: (automation) =>
                                        automation.last_executed_at ? formatDate(automation.last_executed_at) : '—',
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(automation) => setSelectedAutomation(automation)}
                            clickable
                            getRowKey={(automation) => automation.id}
                            emptyState={
                                <EmptyState
                                    icon="flow-arrow"
                                    title={hasFilters ? 'No automations match' : 'No automations yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first automation to save time with repetitive tasks.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary" onClick={() => console.log('Create')}>
                                                <Icon name="plus" size={16} />
                                                <span>Create automation</span>
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
                open={!!selectedAutomation}
                onClose={() => setSelectedAutomation(null)}
                title={selectedAutomation?.name ?? ''}
                subtitle={selectedAutomation?.description ?? ''}
                size="lg"
            >
                {selectedAutomation && (
                    <div className="space-y-6">
                        <DrawerSection title="Automation Details">
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedAutomation.status]}
                                        variant={statusVariants[selectedAutomation.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField
                                label="Executions"
                                value={selectedAutomation.execution_count.toLocaleString()}
                                icon="arrow-clockwise"
                            />
                            <DrawerField
                                label="Success Rate"
                                value={
                                    <div className="flex items-center gap-2">
                                        <div className="h-2 w-24 overflow-hidden rounded-full bg-[var(--shell-tint)]">
                                            <div
                                                className={`h-full ${
                                                    selectedAutomation.success_rate >= 80
                                                        ? 'bg-[var(--color-success)]'
                                                        : selectedAutomation.success_rate >= 50
                                                          ? 'bg-[var(--color-warning)]'
                                                          : 'bg-[var(--color-danger)]'
                                                }`}
                                                style={{ width: `${selectedAutomation.success_rate}%` }}
                                            />
                                        </div>
                                        <span className="font-semibold">
                                            {Math.round(selectedAutomation.success_rate)}%
                                        </span>
                                    </div>
                                }
                                icon="chart-line"
                            />
                        </DrawerSection>

                        <DrawerSection title="Trigger">
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--shell-tint)] p-4">
                                <div className="flex items-start gap-3">
                                    <div
                                        className={`flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-white ${triggerMeta[selectedAutomation.trigger.type].color}`}
                                    >
                                        <Icon name={triggerMeta[selectedAutomation.trigger.type].icon} size={20} />
                                    </div>
                                    <div className="flex-1">
                                        <p className="font-semibold text-[var(--color-text-main)]">
                                            {triggerMeta[selectedAutomation.trigger.type].label}
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            {selectedAutomation.trigger.value}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </DrawerSection>

                        <DrawerSection title={`Actions (${selectedAutomation.actions.length})`}>
                            <div className="space-y-3">
                                {selectedAutomation.actions.map((action, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-start gap-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--shell-tint)] p-3"
                                    >
                                        <div className="flex h-8 w-8 flex-none items-center justify-center rounded-md bg-[var(--color-brand-subtle)] text-xs font-semibold text-[var(--color-brand)]">
                                            {idx + 1}
                                        </div>
                                        <div className="flex-1">
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {actionTypeLabels[action.type]}
                                            </p>
                                            <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                                Action will be executed when trigger fires
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </DrawerSection>

                        <DrawerSection title="Metadata">
                            <DrawerField
                                label="Created By"
                                value={selectedAutomation.created_by.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Created"
                                value={new Date(selectedAutomation.created_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="calendar"
                            />
                            {selectedAutomation.last_executed_at && (
                                <DrawerField
                                    label="Last Executed"
                                    value={new Date(selectedAutomation.last_executed_at).toLocaleString('en-US', {
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

                        {selectedAutomation.status === 'draft' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-info-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon
                                        name="info"
                                        size={20}
                                        className="flex-none text-[var(--color-info)]"
                                    />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">Draft Mode</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This automation is in draft mode and won't execute until activated.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="pencil-simple" size={16} />
                                <span>Edit automation</span>
                            </button>
                            <button className="btn btn-secondary">
                                {selectedAutomation.status === 'active' ? (
                                    <>
                                        <Icon name="pause" size={16} />
                                        <span>Pause</span>
                                    </>
                                ) : (
                                    <>
                                        <Icon name="play" size={16} />
                                        <span>Activate</span>
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
