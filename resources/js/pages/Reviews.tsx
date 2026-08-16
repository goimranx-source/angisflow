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

type Review = {
    id: string;
    product: {
        id: string;
        name: string;
    };
    customer: {
        id: string;
        name: string;
    };
    rating: number;
    title: string;
    comment: string;
    status: 'pending' | 'approved' | 'rejected';
    is_verified: boolean;
    helpful_count: number;
    created_at: string;
};

type ReviewsResponse = {
    data: Review[];
    summary: {
        total_reviews: number;
        pending_count: number;
        avg_rating: number;
        verified_percentage: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Reviews() {
    useDocumentTitle('Reviews');

    const [search, setSearch] = useState('');
    const [ratingFilter, setRatingFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedReview, setSelectedReview] = useState<Review | null>(null);
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['reviews', { search, ratingFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<ReviewsResponse>('/reviews', {
                params: {
                    search,
                    rating: ratingFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const reviews = data?.data ?? [];
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
        setRatingFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || ratingFilter || statusFilter;

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const statusVariants: Record<string, 'warning' | 'success' | 'danger'> = {
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
    };

    const statusLabels = {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
    };

    const renderStars = (rating: number) => {
        return (
            <div className="flex gap-0.5">
                {[1, 2, 3, 4, 5].map((star) => (
                    <Icon
                        key={star}
                        name={star <= rating ? 'star-fill' : 'star'}
                        size={14}
                        className={star <= rating ? 'text-[var(--color-warning)]' : 'text-[var(--color-text-muted)]'}
                    />
                ))}
            </div>
        );
    };

    return (
        <div className="flex h-full flex-col">
            <div className="px-6 pt-6">
                <PageHeader
                    title="Reviews"
                    description="Manage product reviews and customer feedback"
                    icon="star"
                    actions={
                        <button className="btn btn-primary" onClick={() => console.log('Request reviews')}>
                            <Icon name="envelope-simple" size={16} />
                            <span>Request reviews</span>
                        </button>
                    }
                />
            </div>

            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Reviews"
                        value={summary.total_reviews.toLocaleString()}
                        icon="star"
                        variant="brand"
                    />
                    <KPICard
                        label="Pending"
                        value={summary.pending_count.toLocaleString()}
                        icon="clock"
                        variant="warning"
                    />
                    <KPICard
                        label="Avg Rating"
                        value={summary.avg_rating.toFixed(1)}
                        icon="star-fill"
                        variant={summary.avg_rating >= 4 ? 'success' : 'warning'}
                    />
                    <KPICard
                        label="Verified"
                        value={`${summary.verified_percentage}%`}
                        icon="seal-check"
                        variant="info"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search reviews or products..."
                    filters={
                        <>
                            <FilterSelect
                                label="Rating"
                                value={ratingFilter}
                                onChange={setRatingFilter}
                                options={[
                                    { value: '5', label: '5 Stars' },
                                    { value: '4', label: '4 Stars' },
                                    { value: '3', label: '3 Stars' },
                                    { value: '2', label: '2 Stars' },
                                    { value: '1', label: '1 Star' },
                                ]}
                                placeholder="All ratings"
                            />
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'pending', label: 'Pending' },
                                    { value: 'approved', label: 'Approved' },
                                    { value: 'rejected', label: 'Rejected' },
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
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load reviews.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={reviews}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'product',
                                    label: 'Product',
                                    render: (rev) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {rev.product.name}
                                            </p>
                                            <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">
                                                by {rev.customer.name}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'rating',
                                    label: 'Rating',
                                    sortable: true,
                                    render: (rev) => (
                                        <div className="flex items-center gap-2">
                                            {renderStars(rev.rating)}
                                            <span className="font-semibold tabular-nums">{rev.rating}</span>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'title',
                                    label: 'Review',
                                    render: (rev) => (
                                        <div className="max-w-sm">
                                            <p className="font-medium text-[var(--color-text-main)]">{rev.title}</p>
                                            <p className="mt-0.5 line-clamp-1 text-sm text-[var(--color-text-body)]">
                                                {rev.comment}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'verified',
                                    label: 'Verified',
                                    render: (rev) =>
                                        rev.is_verified ? (
                                            <StatusBadge label="Verified" variant="success" icon="seal-check" />
                                        ) : (
                                            <span className="text-sm text-[var(--color-text-muted)]">—</span>
                                        ),
                                },
                                {
                                    key: 'helpful_count',
                                    label: 'Helpful',
                                    align: 'right',
                                    render: (rev) => (
                                        <span className="tabular-nums">{rev.helpful_count}</span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    render: (rev) => (
                                        <StatusBadge
                                            label={statusLabels[rev.status]}
                                            variant={statusVariants[rev.status]}
                                            dot
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(rev) => setSelectedReview(rev)}
                            clickable
                            getRowKey={(rev) => rev.id}
                            emptyState={
                                <EmptyState
                                    icon="star"
                                    title={hasFilters ? 'No reviews match' : 'No reviews yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Reviews will appear here as customers share feedback.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="envelope-simple" size={16} />
                                                <span>Request reviews</span>
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
                open={!!selectedReview}
                onClose={() => setSelectedReview(null)}
                title={selectedReview?.title ?? ''}
                subtitle={selectedReview ? `${selectedReview.product.name} by ${selectedReview.customer.name}` : ''}
                size="md"
            >
                {selectedReview && (
                    <div className="space-y-6">
                        <DrawerSection title="Review Details">
                            <DrawerField label="Product" value={selectedReview.product.name} icon="package" />
                            <DrawerField label="Customer" value={selectedReview.customer.name} icon="user" />
                            <DrawerField
                                label="Rating"
                                value={
                                    <div className="flex items-center gap-2">
                                        {renderStars(selectedReview.rating)}
                                        <span className="font-semibold">{selectedReview.rating}/5</span>
                                    </div>
                                }
                                icon="star"
                            />
                            {selectedReview.is_verified && (
                                <DrawerField
                                    label="Verified Purchase"
                                    value={<StatusBadge label="Verified" variant="success" icon="seal-check" />}
                                    icon="seal-check"
                                />
                            )}
                        </DrawerSection>

                        <DrawerSection title="Comment">
                            <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-neutral-subtle)] p-4">
                                <p className="text-sm text-[var(--color-text-body)]">{selectedReview.comment}</p>
                            </div>
                        </DrawerSection>

                        <DrawerSection title="Engagement">
                            <DrawerField
                                label="Helpful Votes"
                                value={selectedReview.helpful_count.toString()}
                                icon="thumbs-up"
                            />
                            <DrawerField
                                label="Status"
                                value={
                                    <StatusBadge
                                        label={statusLabels[selectedReview.status]}
                                        variant={statusVariants[selectedReview.status]}
                                        dot
                                    />
                                }
                                icon="circle-notch"
                            />
                        </DrawerSection>

                        <DrawerSection title="Timeline">
                            <DrawerField
                                label="Posted"
                                value={formatDate(selectedReview.created_at)}
                                icon="calendar"
                            />
                        </DrawerSection>

                        {selectedReview.status === 'pending' && (
                            <div className="flex gap-3 pt-4">
                                <button className="btn btn-primary flex-1">
                                    <Icon name="check" size={16} />
                                    <span>Approve</span>
                                </button>
                                <button className="btn btn-secondary text-[var(--color-danger)]">
                                    <Icon name="x" size={16} />
                                    <span>Reject</span>
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </DetailDrawer>
        </div>
    );
}
