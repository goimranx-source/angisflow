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

type Offer = {
    id: string;
    code: string;
    name: string;
    type: 'percentage' | 'fixed' | 'bogo' | 'free_shipping';
    discount_value: number;
    min_order_value?: number;
    max_discount?: number;
    usage_limit?: number;
    used_count: number;
    status: 'active' | 'scheduled' | 'expired' | 'disabled';
    valid_from: string;
    valid_until: string;
    created_at: string;
};

type OffersResponse = {
    data: Offer[];
    summary: {
        total_offers: number;
        active_count: number;
        total_redemptions: number;
        total_discount_given: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function OffersCoupons() {
    useDocumentTitle('Offers & Coupons');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedOffer, setSelectedOffer] = useState<Offer | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['offers', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<OffersResponse>('/offers', {
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

    const offers = data?.data ?? [];
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
        percentage: 'Percentage Off',
        fixed: 'Fixed Amount',
        bogo: 'Buy One Get One',
        free_shipping: 'Free Shipping',
    };

    const statusVariants: Record<string, 'success' | 'info' | 'neutral' | 'danger'> = {
        active: 'success',
        scheduled: 'info',
        expired: 'neutral',
        disabled: 'danger',
    };

    const statusLabels = {
        active: 'Active',
        scheduled: 'Scheduled',
        expired: 'Expired',
        disabled: 'Disabled',
    };

    const formatDiscount = (offer: Offer) => {
        if (offer.type === 'percentage') {
            return `${offer.discount_value}% off`;
        }
        if (offer.type === 'fixed') {
            return `${formatMoney(offer.discount_value)} off`;
        }
        if (offer.type === 'bogo') {
            return 'BOGO';
        }
        return 'Free Shipping';
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Offers & Coupons"
                    description="Create and manage promotional offers and discount coupons"
                    icon="tag"
                    actions={
                        <button type="button" className="btn btn-primary" onClick={() => console.log('Create offer')}>
                            <Icon name="plus" size={16} />
                            <span>Create offer</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Offers"
                        value={summary.total_offers.toLocaleString()}
                        icon="tag"
                        variant="brand"
                    />
                    <KPICard
                        label="Active Offers"
                        value={summary.active_count.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Redemptions"
                        value={summary.total_redemptions.toLocaleString()}
                        icon="ticket"
                        variant="info"
                    />
                    <KPICard
                        label="Discount Given"
                        value={formatMoney(summary.total_discount_given)}
                        icon="currency-dollar"
                        variant="warning"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search offers or coupon codes..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'percentage', label: 'Percentage Off' },
                                    { value: 'fixed', label: 'Fixed Amount' },
                                    { value: 'bogo', label: 'BOGO' },
                                    { value: 'free_shipping', label: 'Free Shipping' },
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
                                    { value: 'expired', label: 'Expired' },
                                    { value: 'disabled', label: 'Disabled' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load offers.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={offers}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'code',
                                    label: 'Code',
                                    render: (offer) => (
                                        <div>
                                            <p className="font-mono font-semibold text-[var(--color-brand)]">
                                                {offer.code}
                                            </p>
                                            <p className="mt-0.5 text-sm text-[var(--color-text-body)]">
                                                {offer.name}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (offer) => typeLabels[offer.type],
                                },
                                {
                                    key: 'discount',
                                    label: 'Discount',
                                    render: (offer) => (
                                        <span className="font-semibold text-[var(--color-text-main)]">
                                            {formatDiscount(offer)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'usage',
                                    label: 'Usage',
                                    align: 'right',
                                    render: (offer) => (
                                        <div className="text-right">
                                            <span className="font-semibold tabular-nums">
                                                {offer.used_count.toLocaleString()}
                                            </span>
                                            {offer.usage_limit && (
                                                <span className="text-[var(--color-text-muted)]">
                                                    {' '}
                                                    / {offer.usage_limit.toLocaleString()}
                                                </span>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (offer) => (
                                        <StatusBadge
                                            label={statusLabels[offer.status]}
                                            variant={statusVariants[offer.status]}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'valid_until',
                                    label: 'Expires',
                                    sortable: true,
                                    accessor: (offer) => formatDate(offer.valid_until),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(offer) => setSelectedOffer(offer)}
                            clickable
                            getRowKey={(offer) => offer.id}
                            emptyState={
                                <EmptyState
                                    icon="tag"
                                    title={hasFilters ? 'No offers match' : 'No offers yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first offer to boost sales with promotions.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create offer</span>
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
                open={!!selectedOffer}
                onClose={() => setSelectedOffer(null)}
                title={selectedOffer?.code ?? ''}
                subtitle={selectedOffer?.name ?? ''}
                size="md"
            >
                {selectedOffer && (
                    <div className="space-y-6">
                        <DrawerSection title="Offer Details">
                            <DrawerField label="Coupon Code" value={selectedOffer.code} icon="tag" />
                            <DrawerField label="Type" value={typeLabels[selectedOffer.type]} icon="squares-four" />
                            <DrawerField label="Discount" value={formatDiscount(selectedOffer)} icon="percent" />
                            {selectedOffer.min_order_value && (
                                <DrawerField
                                    label="Min Order"
                                    value={formatMoney(selectedOffer.min_order_value)}
                                    icon="shopping-cart"
                                />
                            )}
                            {selectedOffer.max_discount && (
                                <DrawerField
                                    label="Max Discount"
                                    value={formatMoney(selectedOffer.max_discount)}
                                    icon="arrow-down"
                                />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Usage">
                            <DrawerField
                                label="Redemptions"
                                value={
                                    <div>
                                        <span className="font-semibold">{selectedOffer.used_count.toLocaleString()}</span>
                                        {selectedOffer.usage_limit && (
                                            <span className="text-[var(--color-text-muted)]">
                                                {' '}
                                                / {selectedOffer.usage_limit.toLocaleString()}
                                            </span>
                                        )}
                                    </div>
                                }
                                icon="ticket"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedOffer.status]}
                                        variant={statusVariants[selectedOffer.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Validity Period">
                            <DrawerField label="Valid From" value={formatDate(selectedOffer.valid_from)} icon="calendar" />
                            <DrawerField
                                label="Valid Until"
                                value={formatDate(selectedOffer.valid_until)}
                                icon="calendar-check"
                            />
                        </DrawerSection>

                        {selectedOffer.status === 'active' && (
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-success-subtle)] p-4">
                                <div className="flex gap-3">
                                    <Icon name="check-circle" size={20} className="flex-none text-[var(--color-success)]" />
                                    <div>
                                        <p className="font-semibold text-[var(--color-text-main)]">Active Offer</p>
                                        <p className="mt-1 text-sm text-[var(--color-text-body)]">
                                            This offer is currently active and can be used by customers.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3 pt-4">
                            <button className="btn btn-primary flex-1">
                                <Icon name="pencil" size={16} />
                                <span>Edit</span>
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
