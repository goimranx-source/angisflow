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

type MessageTemplate = {
    id: string;
    name: string;
    category: 'greeting' | 'support' | 'sales' | 'followup' | 'notification' | 'feedback' | 'other';
    channel: 'email' | 'sms' | 'whatsapp' | 'chat' | 'all';
    subject?: string;
    body: string;
    variables: string[]; // e.g., ['customer_name', 'order_number']
    is_active: boolean;
    usage_count: number;
    last_used_at?: string;
    created_by: {
        id: string;
        name: string;
    };
    created_at: string;
};

type TemplatesResponse = {
    data: MessageTemplate[];
    summary: {
        total_templates: number;
        active_templates: number;
        total_usage: number;
        most_used_template: string;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function MessageTemplates() {
    useDocumentTitle('Message Templates');

    // State
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [channelFilter, setChannelFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedTemplate, setSelectedTemplate] = useState<MessageTemplate | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('usage_count');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    // Fetch templates
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['message-templates', { search, categoryFilter, channelFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<TemplatesResponse>('/message-templates', {
                params: {
                    search,
                    category: categoryFilter || undefined,
                    channel: channelFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const templates = data?.data ?? [];
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
        setCategoryFilter('');
        setChannelFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || categoryFilter || channelFilter || statusFilter;

    // Format date
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    // Category labels & icons
    const categoryMeta: Record<MessageTemplate['category'], { label: string; icon: string; color: string }> = {
        greeting: { label: 'Greeting', icon: 'hand-waving', color: 'text-[var(--color-info)]' },
        support: { label: 'Support', icon: 'lifebuoy', color: 'text-[var(--color-brand)]' },
        sales: { label: 'Sales', icon: 'shopping-bag', color: 'text-[var(--color-success)]' },
        followup: { label: 'Follow-up', icon: 'arrows-clockwise', color: 'text-[var(--color-warning)]' },
        notification: { label: 'Notification', icon: 'bell', color: 'text-[var(--color-neutral)]' },
        feedback: { label: 'Feedback', icon: 'star', color: 'text-[var(--color-warning)]' },
        other: { label: 'Other', icon: 'dots-three', color: 'text-[var(--color-text-muted)]' },
    };

    // Channel labels
    const channelLabels: Record<MessageTemplate['channel'], string> = {
        email: 'Email',
        sms: 'SMS',
        whatsapp: 'WhatsApp',
        chat: 'Chat',
        all: 'All Channels',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Message Templates"
                    description="Create reusable message templates for faster responses"
                    icon="chat-text"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Create template')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Create template</span>
                        </button>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Templates"
                        value={summary.total_templates.toLocaleString()}
                        icon="chat-text"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_templates.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Total Usage"
                        value={summary.total_usage.toLocaleString()}
                        icon="arrow-up-right"
                        variant="info"
                    />
                    <KPICard
                        label="Most Used"
                        value={summary.most_used_template}
                        icon="medal"
                        variant="warning"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search templates..."
                    filters={
                        <>
                            <FilterSelect
                                label="Category"
                                value={categoryFilter}
                                onChange={setCategoryFilter}
                                options={[
                                    { value: 'greeting', label: 'Greeting' },
                                    { value: 'support', label: 'Support' },
                                    { value: 'sales', label: 'Sales' },
                                    { value: 'followup', label: 'Follow-up' },
                                    { value: 'notification', label: 'Notification' },
                                    { value: 'feedback', label: 'Feedback' },
                                    { value: 'other', label: 'Other' },
                                ]}
                                placeholder="All categories"
                            />
                            <FilterSelect
                                label="Channel"
                                value={channelFilter}
                                onChange={setChannelFilter}
                                options={[
                                    { value: 'email', label: 'Email' },
                                    { value: 'sms', label: 'SMS' },
                                    { value: 'whatsapp', label: 'WhatsApp' },
                                    { value: 'chat', label: 'Chat' },
                                    { value: 'all', label: 'All Channels' },
                                ]}
                                placeholder="All channels"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
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
                            Failed to load message templates.
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
                            data={templates}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Template',
                                    render: (template) => (
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-[var(--color-neutral-subtle)] ${categoryMeta[template.category].color}`}
                                            >
                                                <Icon name={categoryMeta[template.category].icon} size={20} />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {template.name}
                                                </p>
                                                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                    {categoryMeta[template.category].label} • {channelLabels[template.channel]}
                                                </p>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'body',
                                    label: 'Preview',
                                    render: (template) => (
                                        <div className="max-w-md">
                                            {template.subject && (
                                                <p className="mb-1 text-xs font-semibold text-[var(--color-text-muted)]">
                                                    Subject: {template.subject}
                                                </p>
                                            )}
                                            <p className="line-clamp-2 text-sm text-[var(--color-text-body)]">
                                                {template.body}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'variables',
                                    label: 'Variables',
                                    render: (template) =>
                                        template.variables.length > 0 ? (
                                            <div className="flex flex-wrap gap-1">
                                                {template.variables.slice(0, 3).map((variable) => (
                                                    <span
                                                        key={variable}
                                                        className="rounded bg-[var(--color-brand-subtle)] px-1.5 py-0.5 text-[10px] font-mono text-[var(--color-brand)]"
                                                    >
                                                        {`{${variable}}`}
                                                    </span>
                                                ))}
                                                {template.variables.length > 3 && (
                                                    <span className="text-xs text-[var(--color-text-muted)]">
                                                        +{template.variables.length - 3}
                                                    </span>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-sm text-[var(--color-text-muted)]">—</span>
                                        ),
                                },
                                {
                                    key: 'usage_count',
                                    label: 'Used',
                                    align: 'right',
                                    sortable: true,
                                    render: (template) => (
                                        <span className="tabular-nums font-medium">
                                            {template.usage_count.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'is_active',
                                    label: 'Status',
                                    render: (template) => (
                                        <StatusBadge
                                            label={template.is_active ? 'Active' : 'Inactive'}
                                            variant={template.is_active ? 'success' : 'neutral'}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'last_used_at',
                                    label: 'Last Used',
                                    sortable: true,
                                    accessor: (template) =>
                                        template.last_used_at ? formatDate(template.last_used_at) : '—',
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(template) => setSelectedTemplate(template)}
                            clickable
                            getRowKey={(template) => template.id}
                            emptyState={
                                <EmptyState
                                    icon="chat-text"
                                    title={hasFilters ? 'No templates match' : 'No templates yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first message template to save time responding to customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary" onClick={() => console.log('Create')}>
                                                <Icon name="plus" size={16} />
                                                <span>Create template</span>
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
                open={!!selectedTemplate}
                onClose={() => setSelectedTemplate(null)}
                title={selectedTemplate?.name ?? ''}
                subtitle={selectedTemplate ? categoryMeta[selectedTemplate.category].label : ''}
                size="lg"
            >
                {selectedTemplate && (
                    <div className="space-y-6">
                        <DrawerSection title="Template Details">
                            <DrawerField
                                label="Category"
                                value={
                                    <div className="flex items-center gap-2">
                                        <Icon
                                            name={categoryMeta[selectedTemplate.category].icon}
                                            size={16}
                                            className={categoryMeta[selectedTemplate.category].color}
                                        />
                                        <span>{categoryMeta[selectedTemplate.category].label}</span>
                                    </div>
                                }
                                icon="tag"
                            />
                            <DrawerField
                                label="Channel"
                                value={channelLabels[selectedTemplate.channel]}
                                icon="plug"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={selectedTemplate.is_active ? 'Active' : 'Inactive'}
                                        variant={selectedTemplate.is_active ? 'success' : 'neutral'}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField
                                label="Usage Count"
                                value={selectedTemplate.usage_count.toLocaleString()}
                                icon="arrow-up-right"
                            />
                        </DrawerSection>

                        {selectedTemplate.subject && (
                            <DrawerSection title="Subject">
                                <div className="rounded-md border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] p-3">
                                    <p className="text-sm font-medium text-[var(--color-text-main)]">
                                        {selectedTemplate.subject}
                                    </p>
                                </div>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Message Body">
                            <div className="rounded-md border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] p-4">
                                <p className="whitespace-pre-wrap text-sm text-[var(--color-text-body)]">
                                    {selectedTemplate.body}
                                </p>
                            </div>
                        </DrawerSection>

                        {selectedTemplate.variables.length > 0 && (
                            <DrawerSection title="Variables">
                                <div className="flex flex-wrap gap-2">
                                    {selectedTemplate.variables.map((variable) => (
                                        <span
                                            key={variable}
                                            className="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] px-2.5 py-1.5 text-xs font-mono font-medium text-[var(--color-brand)]"
                                        >
                                            <Icon name="brackets-curly" size={12} />
                                            {variable}
                                        </span>
                                    ))}
                                </div>
                                <p className="mt-3 text-xs text-[var(--color-text-muted)]">
                                    These variables will be automatically replaced with actual values when you use this template.
                                </p>
                            </DrawerSection>
                        )}

                        <DrawerSection title="Metadata">
                            <DrawerField
                                label="Created By"
                                value={selectedTemplate.created_by.name}
                                icon="user"
                            />
                            <DrawerField
                                label="Created"
                                value={new Date(selectedTemplate.created_at).toLocaleString('en-US', {
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                                icon="calendar"
                            />
                            {selectedTemplate.last_used_at && (
                                <DrawerField
                                    label="Last Used"
                                    value={new Date(selectedTemplate.last_used_at).toLocaleString('en-US', {
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

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="pencil-simple" size={16} />
                                <span>Edit template</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="copy" size={16} />
                                <span>Duplicate</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
