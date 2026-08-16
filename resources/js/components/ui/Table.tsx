import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type SortDirection = 'asc' | 'desc' | null;

type Column<T> = {
    /** Unique key for the column */
    key: string;
    /** Column header label */
    label: string;
    /** Whether this column can be sorted */
    sortable?: boolean;
    /** Custom render function for cell content */
    render?: (item: T, index: number) => ReactNode;
    /** Accessor function to get the value for default rendering */
    accessor?: (item: T) => ReactNode;
    /** Column width class (e.g., 'w-48', 'w-1/4') */
    width?: string;
    /** Align cell content */
    align?: 'left' | 'center' | 'right';
};

type TableProps<T> = {
    /** Array of data items to display */
    data: T[];
    /** Column configuration */
    columns: Column<T>[];
    /** Current sort column key */
    sortBy?: string | null;
    /** Current sort direction */
    sortDirection?: SortDirection;
    /** Sort change handler */
    onSort?: (key: string) => void;
    /** Row click handler */
    onRowClick?: (item: T, index: number) => void;
    /** Whether rows are clickable (adds hover effect) */
    clickable?: boolean;
    /** Function to generate unique key for each row */
    getRowKey?: (item: T, index: number) => string | number;
    /** Empty state component */
    emptyState?: ReactNode;
    /** Whether data is loading */
    loading?: boolean;
    /** Number of skeleton rows to show when loading */
    skeletonRows?: number;
    /** Additional table class names */
    className?: string;
    /** Compact mode (smaller padding) */
    compact?: boolean;
    /** Striped rows */
    striped?: boolean;
};

/**
 * Versatile table component with sorting, pagination support, and customizable rendering.
 *
 * Features:
 * - Sortable columns
 * - Custom cell rendering
 * - Row click handling
 * - Loading states with skeletons
 * - Empty states
 * - Responsive (horizontal scroll on small screens)
 * - Accessible (proper table semantics, keyboard navigation)
 *
 * Example:
 * ```tsx
 * <Table
 *   data={orders}
 *   columns={[
 *     { key: 'id', label: 'Order #', accessor: (o) => o.public_id },
 *     { key: 'customer', label: 'Customer', accessor: (o) => o.customer_name },
 *     { key: 'total', label: 'Total', accessor: (o) => formatMoney(o.total), align: 'right' },
 *     { key: 'status', label: 'Status', render: (o) => <StatusBadge status={o.status} /> },
 *   ]}
 *   sortBy={sortBy}
 *   sortDirection={sortDirection}
 *   onSort={handleSort}
 *   onRowClick={(order) => navigate(`/orders/${order.public_id}`)}
 *   clickable
 * />
 * ```
 */
export function Table<T>({
    data,
    columns,
    sortBy = null,
    sortDirection = null,
    onSort,
    onRowClick,
    clickable = false,
    getRowKey = (_, index) => index,
    emptyState,
    loading = false,
    skeletonRows = 5,
    className,
    compact = false,
    striped = false,
}: TableProps<T>) {
    const handleSort = (key: string) => {
        if (onSort) {
            onSort(key);
        }
    };

    const handleRowClick = (item: T, index: number) => {
        if (onRowClick) {
            onRowClick(item, index);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent, item: T, index: number) => {
        if ((e.key === 'Enter' || e.key === ' ') && onRowClick) {
            e.preventDefault();
            onRowClick(item, index);
        }
    };

    if (loading) {
        return (
            <div className={cn('overflow-x-auto', className)}>
                <table className="table">
                    <TableHead
                        columns={columns}
                        sortBy={sortBy}
                        sortDirection={sortDirection}
                        onSort={handleSort}
                    />
                    <tbody>
                        {Array.from({ length: skeletonRows }, (_, i) => (
                            <tr key={i}>
                                {columns.map((col) => (
                                    <td key={col.key} className={col.width}>
                                        <div className="skeleton h-5 w-full max-w-[200px]" />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    if (data.length === 0) {
        return (
            <div className={cn('overflow-x-auto', className)}>
                <table className="table">
                    <TableHead
                        columns={columns}
                        sortBy={sortBy}
                        sortDirection={sortDirection}
                        onSort={handleSort}
                    />
                </table>
                {emptyState || (
                    <div className="py-12 text-center">
                        <p className="text-sm text-[var(--color-text-muted)]">No data to display</p>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className={cn('overflow-x-auto', className)}>
            <table className={cn('table', compact && 'table-compact', striped && 'table-striped')}>
                <TableHead
                    columns={columns}
                    sortBy={sortBy}
                    sortDirection={sortDirection}
                    onSort={handleSort}
                />
                <tbody>
                    {data.map((item, index) => (
                        <tr
                            key={getRowKey(item, index)}
                            onClick={() => handleRowClick(item, index)}
                            onKeyDown={(e) => handleKeyDown(e, item, index)}
                            tabIndex={clickable && onRowClick ? 0 : undefined}
                            role={clickable && onRowClick ? 'button' : undefined}
                            className={cn(
                                clickable && onRowClick && 'cursor-pointer hover:bg-[var(--color-bg-subtle)]',
                            )}
                        >
                            {columns.map((col) => {
                                const content = col.render
                                    ? col.render(item, index)
                                    : col.accessor
                                      ? col.accessor(item)
                                      : null;

                                return (
                                    <td
                                        key={col.key}
                                        className={cn(
                                            col.width,
                                            col.align === 'center' && 'text-center',
                                            col.align === 'right' && 'text-right',
                                        )}
                                    >
                                        {content}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

type TableHeadProps<T> = {
    columns: Column<T>[];
    sortBy: string | null;
    sortDirection: SortDirection;
    onSort?: (key: string) => void;
};

function TableHead<T>({ columns, sortBy, sortDirection, onSort }: TableHeadProps<T>) {
    return (
        <thead>
            <tr>
                {columns.map((col) => {
                    const isSorted = sortBy === col.key;
                    const sortable = col.sortable && onSort;

                    return (
                        <th
                            key={col.key}
                            className={cn(
                                col.width,
                                col.align === 'center' && 'text-center',
                                col.align === 'right' && 'text-right',
                                sortable && 'cursor-pointer select-none hover:bg-[var(--color-bg-subtle)]',
                            )}
                            onClick={() => sortable && onSort(col.key)}
                        >
                            <div
                                className={cn(
                                    'flex items-center gap-1.5',
                                    col.align === 'center' && 'justify-center',
                                    col.align === 'right' && 'justify-end',
                                )}
                            >
                                <span>{col.label}</span>
                                {sortable && (
                                    <span
                                        className={cn(
                                            'transition-opacity',
                                            isSorted ? 'opacity-100' : 'opacity-0 group-hover:opacity-50',
                                        )}
                                    >
                                        {isSorted && sortDirection === 'desc' ? (
                                            <Icon name="caret-down" size={14} weight="fill" />
                                        ) : (
                                            <Icon name="caret-up" size={14} weight="fill" />
                                        )}
                                    </span>
                                )}
                            </div>
                        </th>
                    );
                })}
            </tr>
        </thead>
    );
}

