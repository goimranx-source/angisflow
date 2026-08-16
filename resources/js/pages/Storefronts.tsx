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

type Storefront = {
    id: string;
    name: string;
    domain: string;
    type: 'main' | 'brand' | 'regional' | 'category';
    status: 'active' | 'inactive' | 'maintenance' | 'draft';
    products_count: number;
    total_orders: number;
    total_revenue: number;
    theme: string;
    language: string;
    currency: string;
    created_at: string;
    last_updated: string;
};

type StorefrontsResponse = {
    data: Storefront[];
    summary: {
        total_storefronts: number;
        active_count: number;
        total_products: number;
        total_revenue: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Storefronts() {
    useDocumentTitle('Storefronts');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedStorefront, setSelectedStorefront] = useState<Storefront | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['storefronts', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<StorefrontsResponse>('/storefronts', {
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

    const storefronts = data?.data ?? [];
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
        main: 'Main Store',
        brand: 'Brand Store',
        regional: 'Regional Store',
        category: 'Category Store',
    };

    const statusVariants: Record<string, 'success' | 'neutral' | 'warning' | 'info'> = {
        active: 'success',
        inactive: 'neutral',
        maintenance: 'warning',
        draft: 'info',
    };

    const statusLabels = {
        active: 'Active',
        inactive: 'Inactive',
        maintenance: 'Maintenance',
        draft: 'Draft',
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Storefronts"
                    description="Manage multiple storefronts for different brands, regions, or categories"
                    icon="storefront"
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => console.log('Create storefront')}
                        >
                            <Icon name="plus" size={16} />
                            <span>Create storefront</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Storefronts"
                        value={summary.total_storefronts.toLocaleString()}
                        icon="storefront"
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_count.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Total Products"
                        value={summary.total_products.toLocaleString()}
                        icon="package"
                        variant="info"
                    />
                    <KPICard
                        label="Total Revenue"
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
                    searchPlaceholder="Search storefronts..."
                    filters={
                        <>
                            <FilterSelect
                                label="Type"
                                value={typeFilter}
                                onChange={setTypeFilter}
                                options={[
                                    { value: 'main', label: 'Main Store' },
                                    { value: 'brand', label: 'Brand Store' },
                                    { value: 'regional', label: 'Regional Store' },
                                    { value: 'category', label: 'Category Store' },
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
                                    { value: 'maintenance', label: 'Maintenance' },
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

            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load storefronts.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={storefronts}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Storefront',
                                    render: (store) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{store.name}</p>
                                            <p className="mt-0.5 text-sm text-[var(--color-brand)]">{store.domain}</p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (store) => typeLabels[store.type],
                                },
                                {
                                    key: 'products_count',
                                    label: 'Products',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="tabular-nums">{store.products_count.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'total_orders',
                                    label: 'Orders',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="tabular-nums">{store.total_orders.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'total_revenue',
                                    label: 'Revenue',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="font-semibold tabular-nums">{formatMoney(store.total_revenue)}</span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (store) => (
                                        <StatusBadge
                                            label={statusLabels[store.status]}
                                            variant={statusVariants[store.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(store) => setSelectedStorefront(store)}
                            clickable
                            getRowKey={(store) => store.id}
                            emptyState={
                                <EmptyState
                                    icon="storefront"
                                    title={hasFilters ? 'No storefronts match' : 'No storefronts yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first storefront to sell online.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create storefront</span>
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
                open={!!selectedStorefront}
                onClose={() => setSelectedStorefront(null)}
                title={selectedStorefront?.name ?? ''}
                subtitle={selectedStorefront?.domain ?? ''}
                size="md"
            >
                {selectedStorefront && (
                    <div className="space-y-6">
                        <DrawerSection title="Storefront Details">
                            <DrawerField label="Type" value={typeLabels[selectedStorefront.type]} icon="tag" />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedStorefront.status]}
                                        variant={statusVariants[selectedStorefront.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                            <DrawerField label="Domain" value={selectedStorefront.domain} icon="globe" />
                        </DrawerSection>

                        <DrawerSection title="Catalog">
                            <DrawerField
                                label="Products"
                                value={selectedStorefront.products_count.toLocaleString()}
                                icon="package"
                            />
                            <DrawerField
                                label="Theme"
                                value={selectedStorefront.theme}
                                icon="paint-brush"
                            />
                        </DrawerSection>

                        <DrawerSection title="Performance">
                            <DrawerField
                                label="Total Orders"
                                value={selectedStorefront.total_orders.toLocaleString()}
                                icon="shopping-cart"
                            />
                            <DrawerField
                                label="Revenue"
                                value={formatMoney(selectedStorefront.total_revenue)}
                                icon="currency-dollar"
                            />
                        </DrawerSection>

                        <DrawerSection title="Settings">
                            <DrawerField label="Language" value={selectedStorefront.language} icon="translate" />
                            <DrawerField label="Currency" value={selectedStorefront.currency} icon="currency-circle-dollar" />
                        </DrawerSection>

                        <DrawerSection title="Timeline">
                            <DrawerField
                                label="Created"
                                value={formatDate(selectedStorefront.created_at)}
                                icon="calendar"
                            />
                            <DrawerField
                                label="Last Updated"
                                value={formatDate(selectedStorefront.last_updated)}
                                icon="clock"
                            />
                        </DrawerSection>

                        <div className="flex gap-3 pt-4">
                            <a
                                href={`https://${selectedStorefront.domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn-primary flex-1"
                            >
                                <Icon name="arrow-square-out" size={16} />
                                <span>Visit Store</span>
                            </a>
                            <button className="btn btn-secondary">
                                <Icon name="gear" size={16} />
                                <span>Settings</span>
                            </button>
                        </div>
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
