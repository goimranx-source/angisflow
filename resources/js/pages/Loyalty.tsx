import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
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

type LoyaltyMember = {
    id: string;
    customer: {
        id: string;
        name: string;
        email: string;
    };
    tier: 'bronze' | 'silver' | 'gold' | 'platinum';
    points_balance: number;
    points_earned: number;
    points_redeemed: number;
    lifetime_value: number;
    member_since: string;
    last_activity: string;
};

type LoyaltyResponse = {
    data: LoyaltyMember[];
    summary: {
        total_members: number;
        active_members: number;
        total_points_issued: number;
        redemption_rate: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Loyalty() {
    useDocumentTitle('Loyalty');

    const [search, setSearch] = useState('');
    const [tierFilter, setTierFilter] = useState('');
    const [selectedMember, setSelectedMember] = useState<LoyaltyMember | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('points_balance');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['loyalty', { search, tierFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<LoyaltyResponse>('/loyalty', {
                params: {
                    search,
                    tier: tierFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const members = data?.data ?? [];
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
        setTierFilter('');
    };

    const hasFilters = search || tierFilter;

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney } = useMoney();

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const tierVariants: Record<string, 'neutral' | 'info' | 'warning' | 'brand'> = {
        bronze: 'neutral',
        silver: 'info',
        gold: 'warning',
        platinum: 'brand',
    };

    const tierLabels = {
        bronze: 'Bronze',
        silver: 'Silver',
        gold: 'Gold',
        platinum: 'Platinum',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Loyalty Program"
                    description="Manage customer loyalty rewards and memberships"
                    icon="medal"
                    actions={
                        <div className="flex gap-3">
                            <button className="btn btn-secondary" onClick={() => console.log('Program settings')}>
                                <Icon name="gear" size={16} />
                                <span>Settings</span>
                            </button>
                            <button className="btn btn-primary" onClick={() => console.log('Add member')}>
                                <Icon name="plus" size={16} />
                                <span>Add member</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Members"
                        value={summary.total_members.toLocaleString()}
                        icon="users"
                        variant="brand"
                    />
                    <KPICard
                        label="Active Members"
                        value={summary.active_members.toLocaleString()}
                        icon="user-check"
                        variant="success"
                    />
                    <KPICard
                        label="Points Issued"
                        value={summary.total_points_issued.toLocaleString()}
                        icon="sparkle"
                        variant="info"
                    />
                    <KPICard
                        label="Redemption Rate"
                        value={`${summary.redemption_rate}%`}
                        icon="percent"
                        variant="warning"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search members..."
                    filters={
                        <>
                            <div className="flex gap-2">
                                {(['bronze', 'silver', 'gold', 'platinum'] as const).map((tier) => (
                                    <button
                                        key={tier}
                                        onClick={() => setTierFilter(tierFilter === tier ? '' : tier)}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-colors ${
                                            tierFilter === tier
                                                ? 'bg-[var(--color-brand)] text-white'
                                                : 'bg-[var(--shell-tint)] text-[var(--color-text-main)] hover:bg-[var(--shell-hover)]'
                                        }`}
                                    >
                                        {tier}
                                    </button>
                                ))}
                            </div>
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load loyalty members.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={members}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'customer',
                                    label: 'Member',
                                    render: (mem) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {mem.customer.name}
                                            </p>
                                            <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">
                                                {mem.customer.email}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'tier',
                                    label: 'Tier',
                                    render: (mem) => (
                                        <StatusBadge
                                            label={tierLabels[mem.tier]}
                                            variant={tierVariants[mem.tier]}
                                            icon="medal"
                                        />
                                    ),
                                },
                                {
                                    key: 'points_balance',
                                    label: 'Points',
                                    align: 'right',
                                    sortable: true,
                                    render: (mem) => (
                                        <span className="font-semibold tabular-nums text-[var(--color-brand)]">
                                            {mem.points_balance.toLocaleString()}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'lifetime_value',
                                    label: 'Lifetime Value',
                                    align: 'right',
                                    sortable: true,
                                    render: (mem) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatMoney(mem.lifetime_value)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'member_since',
                                    label: 'Member Since',
                                    sortable: true,
                                    accessor: (mem) => formatDate(mem.member_since),
                                },
                                {
                                    key: 'last_activity',
                                    label: 'Last Activity',
                                    sortable: true,
                                    accessor: (mem) => formatDate(mem.last_activity),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(mem) => setSelectedMember(mem)}
                            clickable
                            getRowKey={(mem) => mem.id}
                            emptyState={
                                <EmptyState
                                    icon="medal"
                                    title={hasFilters ? 'No members match' : 'No loyalty members yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Start enrolling customers in your loyalty program.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Add member</span>
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
                open={!!selectedMember}
                onClose={() => setSelectedMember(null)}
                title={selectedMember?.customer.name ?? ''}
                subtitle={selectedMember?.customer.email ?? ''}
                size="md"
            >
                {selectedMember && (
                    <div className="space-y-6">
                        <DrawerSection title="Membership">
                            <DrawerField
                                label="Tier"
                                value={
                                    <StatusBadge
                                        label={tierLabels[selectedMember.tier]}
                                        variant={tierVariants[selectedMember.tier]}
                                        icon="medal"
                                    />
                                }
                                icon="medal"
                            />
                            <DrawerField
                                label="Member Since"
                                value={formatDate(selectedMember.member_since)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Last Activity"
                                value={formatDate(selectedMember.last_activity)}
                                icon="clock"
                            />
                        </DrawerSection>

                        <DrawerSection title="Points">
                            <DrawerField
                                label="Balance"
                                value={
                                    <span className="font-semibold text-[var(--color-brand)]">
                                        {selectedMember.points_balance.toLocaleString()}
                                    </span>
                                }
                                icon="sparkle"
                            />
                            <DrawerField
                                label="Earned"
                                value={selectedMember.points_earned.toLocaleString()}
                                icon="arrow-up"
                            />
                            <DrawerField
                                label="Redeemed"
                                value={selectedMember.points_redeemed.toLocaleString()}
                                icon="arrow-down"
                            />
                        </DrawerSection>

                        <DrawerSection title="Value">
                            <DrawerField
                                label="Lifetime Value"
                                value={formatMoney(selectedMember.lifetime_value)}
                                icon="currency-dollar"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="plus" size={16} />
                                <span>Add Points</span>
                            </button>
                            <button className="btn btn-secondary">
                                <Icon name="gift" size={16} />
                                <span>Reward</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
