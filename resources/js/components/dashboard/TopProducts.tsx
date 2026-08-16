import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Product = {
    id: string;
    name: string;
    sku: string | null;
    image_url: string | null;
    quantity_sold: number;
    revenue: number;
    revenue_formatted: string;
};

type TopProductsData = {
    data: {
        period: string;
        currency: string;
        products: Product[];
    };
};

type TopProductsProps = {
    /** Number of products to show */
    limit?: number;
    /** Additional class */
    className?: string;
};

/**
 * Top-selling products widget for dashboard.
 *
 * Features:
 * - Shows top N products by revenue
 * - Product image with fallback
 * - Quantity sold and revenue
 * - Loading and empty states
 * - Click to view product details
 */
export function TopProducts({ limit = 5, className }: TopProductsProps) {
    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'top-products', limit],
        queryFn: ({ signal }) =>
            api.get<TopProductsData>('/dashboard/top-products', {
                params: { limit },
                signal,
            }),
    });

    const products = data?.data.products ?? [];

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-base font-semibold text-[var(--color-text-main)]">
                    Top Products
                </h3>
                <a
                    href="/catalogue/products"
                    className="btn btn-secondary flex items-center gap-1 py-1.5 text-sm"
                >
                    View All
                    <Icon name="caret-right" size={14} weight="bold" />
                </a>
            </div>

            {/* Content */}
            <div className="mt-4">
                {isError ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Icon
                            name="warning-circle"
                            size={24}
                            className="text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            Could not load top products
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="mt-2 text-sm font-medium text-[var(--color-brand)] hover:underline"
                        >
                            Try again
                        </button>
                    </div>
                ) : isPending ? (
                    <div className="space-y-3">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex items-center gap-3 animate-pulse"
                            >
                                <div className="h-10 w-10 rounded-lg bg-[var(--color-surface)]" />
                                <div className="flex-1">
                                    <div className="h-4 w-24 rounded bg-[var(--color-surface)]" />
                                    <div className="mt-1 h-3 w-16 rounded bg-[var(--color-surface)]" />
                                </div>
                                <div className="h-4 w-16 rounded bg-[var(--color-surface)]" />
                            </div>
                        ))}
                    </div>
                ) : products.length === 0 ? (
                    <div className="py-8 text-center">
                        <Icon
                            name="package"
                            size={32}
                            className="mx-auto text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            No sales data yet
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {products.map((product, index) => (
                            <div
                                key={product.id}
                                className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-[var(--color-surface)]"
                            >
                                {/* Rank */}
                                <div className="flex h-6 w-6 flex-shrink-0 items-center justify-center">
                                    {index < 3 ? (
                                        <Icon
                                            name="medal"
                                            size={18}
                                            weight="fill"
                                            className={cn(
                                                index === 0 && 'text-yellow-500',
                                                index === 1 && 'text-gray-400',
                                                index === 2 && 'text-amber-600',
                                            )}
                                        />
                                    ) : (
                                        <span className="text-xs font-medium text-[var(--color-text-muted)]">
                                            {index + 1}
                                        </span>
                                    )}
                                </div>

                                {/* Product Image */}
                                {product.image_url ? (
                                    <img
                                        src={product.image_url}
                                        alt={product.name}
                                        className="h-10 w-10 rounded-lg object-cover"
                                    />
                                ) : (
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--color-brand-subtle)]">
                                        <Icon
                                            name="package"
                                            size={20}
                                            className="text-[var(--color-ink-soft)]"
                                        />
                                    </div>
                                )}

                                {/* Product Info */}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-[var(--color-text-main)]">
                                        {product.name}
                                    </p>
                                    <p className="text-xs text-[var(--color-text-muted)]">
                                        {product.quantity_sold} sold
                                    </p>
                                </div>

                                {/* Revenue */}
                                <div className="flex-shrink-0 text-right">
                                    <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                        {product.revenue_formatted}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* No "view all" link at bottom - already in header */}
        </div>
    );
}
