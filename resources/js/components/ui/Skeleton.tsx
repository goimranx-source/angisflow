import { cn } from '@/lib/utils';

/**
 * Base skeleton component with shimmer animation
 */
export function SkeletonBox({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'animate-pulse rounded bg-[var(--color-brand-subtle)]',
                className
            )}
            {...props}
        />
    );
}

/**
 * Skeleton for text blocks
 */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
    return (
        <div className={cn('space-y-2', className)}>
            {Array.from({ length: lines }).map((_, i) => (
                <SkeletonBox
                    key={i}
                    className={cn(
                        'h-4',
                        i === lines - 1 && 'w-3/4' // Last line shorter
                    )}
                />
            ))}
        </div>
    );
}

/**
 * Skeleton for avatar/circular images
 */
export function SkeletonAvatar({ className }: { className?: string }) {
    return <SkeletonBox className={cn('size-12 rounded-full', className)} />;
}

/**
 * Skeleton for buttons
 */
export function SkeletonButton({ className }: { className?: string }) {
    return <SkeletonBox className={cn('h-10 w-24 rounded-[var(--radius-md)]', className)} />;
}

/**
 * Skeleton for card components
 */
export function SkeletonCard({ className }: { className?: string }) {
    return (
        <div className={cn('card p-5', className)}>
            <SkeletonBox className="mb-4 h-6 w-1/3" />
            <SkeletonText lines={2} />
        </div>
    );
}

/**
 * Skeleton for KPI cards on Dashboard
 */
export function SkeletonKpi() {
    return (
        <div className="card p-5">
            <div className="flex items-center justify-between">
                <SkeletonBox className="h-4 w-20" />
                <SkeletonBox className="size-8 rounded-[10px]" />
            </div>
            <SkeletonBox className="mt-4 h-7 w-28" />
            <SkeletonBox className="mt-2 h-3 w-16" />
        </div>
    );
}

/**
 * Skeleton for table rows
 */
export function SkeletonTable({ rows = 5, columns = 4 }: { rows?: number; columns?: number }) {
    return (
        <div className="space-y-3">
            {/* Header */}
            <div className="grid gap-3" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
                {Array.from({ length: columns }).map((_, i) => (
                    <SkeletonBox key={`header-${i}`} className="h-4" />
                ))}
            </div>
            {/* Rows */}
            {Array.from({ length: rows }).map((_, row) => (
                <div key={`row-${row}`} className="grid gap-3" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
                    {Array.from({ length: columns }).map((_, col) => (
                        <SkeletonBox key={`cell-${row}-${col}`} className="h-4" />
                    ))}
                </div>
            ))}
        </div>
    );
}

/**
 * Skeleton for list items (workspaces, businesses, etc.)
 */
export function SkeletonListItem() {
    return (
        <div className="flex items-center gap-3 border-b border-[var(--color-border-light)] p-4">
            <SkeletonBox className="size-12 shrink-0 rounded-[var(--radius-md)]" />
            <div className="min-w-0 flex-1">
                <SkeletonBox className="mb-2 h-4 w-1/2" />
                <SkeletonBox className="h-3 w-2/3" />
            </div>
            <SkeletonBox className="h-8 w-16 shrink-0" />
        </div>
    );
}

/**
 * Skeleton for workspace/business rows
 */
export function SkeletonWorkspaceRow() {
    return (
        <div className="flex items-center gap-4 border-b border-[var(--color-border-light)] p-4">
            <SkeletonBox className="size-12 shrink-0 rounded-[var(--radius-md)]" />
            <div className="min-w-0 flex-1">
                <SkeletonBox className="mb-2 h-5 w-2/5" />
                <SkeletonBox className="h-3 w-1/3" />
            </div>
            <SkeletonBox className="h-9 w-20 shrink-0 rounded-lg" />
            <SkeletonBox className="size-8 shrink-0 rounded-[var(--radius-md)]" />
        </div>
    );
}

/**
 * Skeleton for page header
 */
export function SkeletonPageHeader() {
    return (
        <div className="mb-6">
            <SkeletonBox className="mb-2 h-8 w-64" />
            <SkeletonBox className="h-4 w-96" />
        </div>
    );
}

/**
 * Skeleton for form fields
 */
export function SkeletonField() {
    return (
        <div className="space-y-2">
            <SkeletonBox className="h-4 w-24" />
            <SkeletonBox className="h-10 w-full rounded-[var(--radius-md)]" />
        </div>
    );
}

/**
 * Full page loading skeleton
 */
export function SkeletonPage() {
    return (
        <div className="mx-auto max-w-7xl">
            <SkeletonPageHeader />
            <div className="card p-6">
                <SkeletonText lines={5} />
            </div>
        </div>
    );
}
