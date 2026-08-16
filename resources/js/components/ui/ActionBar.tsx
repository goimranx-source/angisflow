import { type ReactNode } from 'react';

import { SearchInput } from '@/components/ui/Form/SearchInput';
import { cn } from '@/lib/utils';

type ActionBarProps = {
    /** Search configuration */
    search?: {
        placeholder?: string;
        value?: string;
        onChange?: (value: string) => void;
        onSearch?: (value: string) => void;
    };
    /** Filter components */
    filters?: ReactNode;
    /** Bulk action buttons */
    bulkActions?: ReactNode;
    /** Number of selected items */
    selectedCount?: number;
    /** Clear selection handler */
    onClearSelection?: () => void;
    /** Primary action buttons */
    actions?: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Action bar component for list pages.
 *
 * Features:
 * - Search input
 * - Filter controls
 * - Bulk actions (shown when items selected)
 * - Primary action buttons
 * - Responsive layout
 * - Selection indicator
 *
 * @example
 * ```tsx
 * <ActionBar
 *   search={{
 *     placeholder: 'Search products...',
 *     onSearch: (value) => setSearchQuery(value)
 *   }}
 *   filters={
 *     <>
 *       <Select onChange={e => setCategory(e.target.value)}>
 *         <option value="">All categories</option>
 *         <option value="electronics">Electronics</option>
 *       </Select>
 *       <Select onChange={e => setStatus(e.target.value)}>
 *         <option value="">All statuses</option>
 *         <option value="active">Active</option>
 *       </Select>
 *     </>
 *   }
 *   selectedCount={selectedItems.length}
 *   onClearSelection={() => setSelectedItems([])}
 *   bulkActions={
 *     <>
 *       <Button variant="ghost" size="sm">
 *         <Icon name="tag" size={14} />
 *         Add tags
 *       </Button>
 *       <Button variant="ghost" size="sm">
 *         <Icon name="archive" size={14} />
 *         Archive
 *       </Button>
 *       <Button variant="danger" size="sm">
 *         <Icon name="trash" size={14} />
 *         Delete
 *       </Button>
 *     </>
 *   }
 *   actions={
 *     <>
 *       <Button variant="ghost">
 *         <Icon name="export" size={14} />
 *         Export
 *       </Button>
 *       <Button>
 *         <Icon name="plus" size={14} />
 *         Create Product
 *       </Button>
 *     </>
 *   }
 * />
 * ```
 */
export function ActionBar({
    search,
    filters,
    bulkActions,
    selectedCount = 0,
    onClearSelection,
    actions,
    className,
}: ActionBarProps) {
    const hasSelection = selectedCount > 0;

    return (
        <div className={cn('space-y-4', className)}>
            {/* Main action bar */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                {/* Left side: Search and filters */}
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    {search && (
                        <div className="min-w-[200px] flex-1 sm:max-w-sm">
                            <SearchInput
                                placeholder={search.placeholder || 'Search...'}
                                value={search.value}
                                onChange={search.onChange ? (e) => search.onChange?.(e.target.value) : undefined}
                                onSearch={search.onSearch}
                                size="sm"
                            />
                        </div>
                    )}

                    {filters && (
                        <div className="flex flex-wrap items-center gap-2">
                            {filters}
                        </div>
                    )}
                </div>

                {/* Right side: Primary actions */}
                {actions && !hasSelection && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>

            {/* Bulk actions bar (shown when items selected) */}
            {hasSelection && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--color-brand-subtle)] bg-[var(--color-brand-subtle)] px-4 py-2.5">
                    {/* Selection count */}
                    <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-[var(--color-text-main)]">
                            {selectedCount} {selectedCount === 1 ? 'item' : 'items'} selected
                        </p>
                        {onClearSelection && (
                            <button
                                type="button"
                                onClick={onClearSelection}
                                className="text-sm font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition-colors"
                            >
                                Clear
                            </button>
                        )}
                    </div>

                    {/* Bulk actions */}
                    {bulkActions && (
                        <div className="flex flex-wrap items-center gap-2">
                            {bulkActions}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
