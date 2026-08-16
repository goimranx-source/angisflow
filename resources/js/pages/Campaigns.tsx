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

type Campaign = {
    id: string;
    name: string;
    type: 'email' | 'sms' | 'push' | 'social' | 'multi_channel';
    status: 'draft' | 'scheduled' | 'active' | 'paused' | 'completed';
    target_audience: string;
    sent_count: number;
    open_rate: number;
    click_rate: number;
    conversion_rate: number;
    revenue_generated: number;
    budget: number;
    start_date: string;
    end_date?: string;
    created_at: string;
};

type CampaignsResponse = {
    data: Campaign[];
    summary: {
        total_campaigns: number;
        active_count: number;
        total_sent: number;
        total_revenue: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Campaigns() {
    useDocumentTitle('Campaigns');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedCampaign, setSelectedCampaign] = useState<Campaign | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['campaigns', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<CampaignsResponse>('/campaigns', {
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

    const campaigns = data?.data ?? [];
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

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const typeLabels: Record<string, string> = {
        email: 'Email',
        sms: 'SMS',
        push: 'Push',
        social: 'Social',
        multi_channel: 'Multi-Channel',
    };

    const statusVariants: Record<string, 'neutral' | 'info' | 'success' | 'warning' | 'danger'> = {
        draft: 'neutral',
        scheduled: 'info',
        active: 'success',
        paused: 'warning',
        completed: 'neutral',
    };

    const statusLabels = {
        draft: 'Draft',
        scheduled: 'Scheduled',
        active: 'Active',
        paused: 'Paused',
        completed: 'Completed',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Campaigns"
                    description="Create and manage marketing campaigns across channels"
                    icon="megaphone"
                    actions={
                        <button className="btn btn-primary" onClick={() => console.log('Create campaign')}>
                            <Icon name="plus" size={16} />
                            <span>Create campaign</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Campaigns"
                        value={summary.total_campaigns.toLocaleString()}
                        icon="megaphone"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_count.toLocaleString()}
                        icon="lightning"
                        variant="success"
                    />
                    <KPICard
                        label="Total Sent"
                        value={summary.total_sent.toLocaleString()}
                        icon="paper-plane-tilt"
                        variant="info"
                    />
                    <KPICard
                        label="Revenue"
                        value={formatMoney(summary.total_revenue)}
                        icon="currency-dollar"
                        variant="success"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search campaigns..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'email', label: 'Email' },
                                    { value: 'sms', label: 'SMS' },
                                    { value: 'push', label: 'Push' },
                                    { value: 'social', label: 'Social' },
                                    { value: 'multi_channel', label: 'Multi-Channel' },
                                ]}
                                placeholder="All types"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'scheduled', label: 'Scheduled' },
                                    { value: 'draft', label: 'Draft' },
                                    { value: 'completed', label: 'Completed' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load campaigns.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={campaigns}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Campaign',
                                    render: (camp) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{camp.name}</p>
                                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                {camp.target_audience}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (camp) => typeLabels[camp.type],
                                },
                                {
                                    key: 'sent_count',
                                    label: 'Sent',
                                    align: 'right',
                                    sortable: true,
                                    render: (camp) => (
                                        <span className="tabular-nums">{camp.sent_count.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'performance',
                                    label: 'Performance',
                                    render: (camp) => (
                                        <div className="text-sm">
                                            <div className="flex justify-between">
                                                <span className="text-[var(--color-text-muted)]">Open:</span>
                                                <span className="font-medium">{camp.open_rate}%</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-[var(--color-text-muted)]">Click:</span>
                                                <span className="font-medium">{camp.click_rate}%</span>
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'revenue_generated',
                                    label: 'Revenue',
                                    align: 'right',
                                    sortable: true,
                                    render: (camp) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatMoney(camp.revenue_generated)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (camp) => (
                                        <StatusBadge
                                            label={statusLabels[camp.status]}
                                            variant={statusVariants[camp.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(camp) => setSelectedCampaign(camp)}
                            clickable
                            getRowKey={(camp) => camp.id}
                            emptyState={
                                <EmptyState
                                    icon="megaphone"
                                    title={hasFilters ? 'No campaigns match' : 'No campaigns yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first campaign to reach your customers.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create campaign</span>
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
                open={!!selectedCampaign}
                onClose={() => setSelectedCampaign(null)}
                title={selectedCampaign?.name ?? ''}
                subtitle={selectedCampaign?.target_audience ?? ''}
                size="md"
            >
                {selectedCampaign && (
                    <div className="space-y-6">
                        <DrawerSection title="Campaign Details">
                            <DrawerField label="Type" value={typeLabels[selectedCampaign.type]} icon="tag" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedCampaign.status]}
                                        variant={statusVariants[selectedCampaign.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField label="Budget" value={formatMoney(selectedCampaign.budget)} icon="currency-dollar" />
                        </DrawerSection>

                        <DrawerSection title="Performance">
                            <DrawerField
                                label="Sent"
                                value={selectedCampaign.sent_count.toLocaleString()}
                                icon="paper-plane-tilt"
                            />
                            <DrawerField label="Open Rate" value={`${selectedCampaign.open_rate}%`} icon="envelope-open" />
                            <DrawerField label="Click Rate" value={`${selectedCampaign.click_rate}%`} icon="cursor-click" />
                            <DrawerField
                                label="Conversion"
                                value={`${selectedCampaign.conversion_rate}%`}
                                icon="target"
                            />
                            <DrawerField
                                label="Revenue"
                                value={formatMoney(selectedCampaign.revenue_generated)}
                                icon="currency-dollar"
                            />
                        </DrawerSection>

                        <DrawerSection title="Schedule">
                            <DrawerField label="Start Date" value={formatDate(selectedCampaign.start_date)} icon="calendar" />
                            {selectedCampaign.end_date && (
                                <DrawerField
                                    label="End Date"
                                    value={formatDate(selectedCampaign.end_date)}
                                    icon="calendar-check"
                                />
                            )}
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="chart-line" size={16} />
                                <span>Analytics</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="pencil" size={16} />
                                <span>Edit</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
