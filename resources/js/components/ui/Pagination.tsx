import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type PaginationProps = {
    /** Current page (1-indexed) */
    currentPage: number;
    /** Total number of pages */
    totalPages: number;
    /** Callback when page changes */
    onPageChange: (page: number) => void;
    /** Show page size selector */
    showPageSize?: boolean;
    /** Current page size */
    pageSize?: number;
    /** Available page sizes */
    pageSizes?: number[];
    /** Callback when page size changes */
    onPageSizeChange?: (size: number) => void;
    /** Total items count (optional, for display) */
    totalItems?: number;
    /** Number of page buttons to show */
    siblingCount?: number;
    /** Show first/last buttons */
    showFirstLast?: boolean;
    /** Additional class */
    className?: string;
};

/**
 * Pagination component for navigating pages.
 *
 * Features:
 * - Previous/Next navigation
 * - Page number buttons
 * - First/Last page buttons (optional)
 * - Page size selector (optional)
 * - Total items display
 * - Smart page number truncation
 * - Keyboard navigation
 * - Accessible
 *
 * @example
 * ```tsx
 * <Pagination
 *   currentPage={3}
 *   totalPages={10}
 *   onPageChange={(page) => setCurrentPage(page)}
 *   totalItems={248}
 *   showPageSize
 *   pageSize={25}
 *   onPageSizeChange={(size) => setPageSize(size)}
 * />
 * ```
 */
export function Pagination({
    currentPage,
    totalPages,
    onPageChange,
    showPageSize = false,
    pageSize = 25,
    pageSizes = [10, 25, 50, 100],
    onPageSizeChange,
    totalItems,
    siblingCount = 1,
    showFirstLast = true,
    className,
}: PaginationProps) {
    // Generate page numbers with smart truncation
    const getPageNumbers = () => {
        const pageNumbers: (number | string)[] = [];

        // Always show first page
        pageNumbers.push(1);

        // Calculate range around current page
        const leftSibling = Math.max(2, currentPage - siblingCount);
        const rightSibling = Math.min(totalPages - 1, currentPage + siblingCount);

        // Show ellipsis if there's a gap after first page
        if (leftSibling > 2) {
            pageNumbers.push('...');
        }

        // Show pages around current page
        for (let i = leftSibling; i <= rightSibling; i++) {
            pageNumbers.push(i);
        }

        // Show ellipsis if there's a gap before last page
        if (rightSibling < totalPages - 1) {
            pageNumbers.push('...');
        }

        // Always show last page if there's more than 1 page
        if (totalPages > 1) {
            pageNumbers.push(totalPages);
        }

        return pageNumbers;
    };

    const pageNumbers = getPageNumbers();

    const handlePrevious = () => {
        if (currentPage > 1) {
            onPageChange(currentPage - 1);
        }
    };

    const handleNext = () => {
        if (currentPage < totalPages) {
            onPageChange(currentPage + 1);
        }
    };

    const handleFirst = () => {
        if (currentPage !== 1) {
            onPageChange(1);
        }
    };

    const handleLast = () => {
        if (currentPage !== totalPages) {
            onPageChange(totalPages);
        }
    };

    const handlePageClick = (page: number) => {
        if (page !== currentPage) {
            onPageChange(page);
        }
    };

    // Calculate item range for display
    const startItem = totalItems ? (currentPage - 1) * pageSize + 1 : null;
    const endItem = totalItems ? Math.min(currentPage * pageSize, totalItems) : null;

    return (
        <div
            className={cn('flex flex-wrap items-center justify-between gap-4', className)}
            role="navigation"
            aria-label="Pagination"
        >
            {/* Left: Items info */}
            <div className="flex items-center gap-4">
                {totalItems !== undefined && (
                    <p className="text-sm text-[var(--color-text-muted)]">
                        Showing {startItem} to {endItem} of {totalItems} results
                    </p>
                )}
            </div>

            {/* Center: Page navigation */}
            <div className="flex items-center gap-1">
                {/* First button */}
                {showFirstLast && (
                    <button
                        type="button"
                        onClick={handleFirst}
                        disabled={currentPage === 1}
                        aria-label="Go to first page"
                        className={cn(
                            'flex size-9 items-center justify-center rounded-lg transition-colors',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)]',
                            currentPage === 1
                                ? 'cursor-not-allowed text-[var(--color-text-subtle)]'
                                : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                        )}
                    >
                        <Icon name="caret-double-left" size={16} weight="bold" />
                    </button>
                )}

                {/* Previous button */}
                <button
                    type="button"
                    onClick={handlePrevious}
                    disabled={currentPage === 1}
                    aria-label="Go to previous page"
                    className={cn(
                        'flex size-9 items-center justify-center rounded-lg transition-colors',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)]',
                        currentPage === 1
                            ? 'cursor-not-allowed text-[var(--color-text-subtle)]'
                            : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                    )}
                >
                    <Icon name="caret-left" size={16} weight="bold" />
                </button>

                {/* Page numbers */}
                {pageNumbers.map((pageNum, index) =>
                    typeof pageNum === 'string' ? (
                        <span
                            key={`ellipsis-${index}`}
                            className="flex size-9 items-center justify-center text-[var(--color-text-muted)]"
                        >
                            {pageNum}
                        </span>
                    ) : (
                        <button
                            key={pageNum}
                            type="button"
                            onClick={() => handlePageClick(pageNum)}
                            aria-label={`Go to page ${pageNum}`}
                            aria-current={pageNum === currentPage ? 'page' : undefined}
                            className={cn(
                                'flex size-9 items-center justify-center rounded-lg text-sm font-medium transition-colors',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)]',
                                pageNum === currentPage
                                    ? 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                                    : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                            )}
                        >
                            {pageNum}
                        </button>
                    ),
                )}

                {/* Next button */}
                <button
                    type="button"
                    onClick={handleNext}
                    disabled={currentPage === totalPages}
                    aria-label="Go to next page"
                    className={cn(
                        'flex size-9 items-center justify-center rounded-lg transition-colors',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)]',
                        currentPage === totalPages
                            ? 'cursor-not-allowed text-[var(--color-text-subtle)]'
                            : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                    )}
                >
                    <Icon name="caret-right" size={16} weight="bold" />
                </button>

                {/* Last button */}
                {showFirstLast && (
                    <button
                        type="button"
                        onClick={handleLast}
                        disabled={currentPage === totalPages}
                        aria-label="Go to last page"
                        className={cn(
                            'flex size-9 items-center justify-center rounded-lg transition-colors',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)]',
                            currentPage === totalPages
                                ? 'cursor-not-allowed text-[var(--color-text-subtle)]'
                                : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                        )}
                    >
                        <Icon name="caret-double-right" size={16} weight="bold" />
                    </button>
                )}
            </div>

            {/* Right: Page size selector */}
            {showPageSize && onPageSizeChange && (
                <div className="flex items-center gap-2">
                    <label
                        htmlFor="page-size"
                        className="text-sm text-[var(--color-text-muted)]"
                    >
                        Per page:
                    </label>
                    <select
                        id="page-size"
                        value={pageSize}
                        onChange={(e) => onPageSizeChange(Number(e.target.value))}
                        className={cn(
                            'rounded-lg border border-[var(--color-border-light)]',
                            'bg-[var(--color-card-bg)] px-3 py-1.5 text-sm',
                            'text-[var(--color-text-main)]',
                            'transition-all duration-150',
                            'focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                        )}
                    >
                        {pageSizes.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>
                </div>
            )}
        </div>
    );
}
