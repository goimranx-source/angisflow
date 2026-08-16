import { type ReactNode } from 'react';

import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { PageHeader } from '@/components/ui/PageHeader';
import { cn } from '@/lib/utils';

type ListPageProps = {
    /** Page title */
    title: string;
    /** Page description */
    description?: string;
    /** Breadcrumb items */
    breadcrumbs?: Array<{ label: string; href?: string; icon?: string }>;
    /** Action buttons (e.g., Create New button) */
    actions?: ReactNode;
    /** Filter bar component */
    filters?: ReactNode;
    /** Main content (typically a table) */
    children: ReactNode;
    /** Pagination component */
    pagination?: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Standard list page layout template.
 *
 * Features:
 * - Page header with title, description, actions
 * - Optional breadcrumb navigation
 * - Filter bar section
 * - Main content area (table)
 * - Pagination section
 * - Consistent spacing and structure
 *
 * @example
 * ```tsx
 * <ListPage
 *   title="Orders"
 *   description="Manage customer orders"
 *   breadcrumbs={[
 *     { label: 'Home', href: '/' },
 *     { label: 'Orders' }
 *   ]}
 *   actions={<Button>Create Order</Button>}
 *   filters={
 *     <div className="flex gap-2">
 *       <SearchInput ... />
 *       <Select ... />
 *     </div>
 *   }
 *   pagination={
 *     <Pagination ... />
 *   }
 * >
 *   <Table>...</Table>
 * </ListPage>
 * ```
 */
export function ListPage({
    title,
    description,
    breadcrumbs,
    actions,
    filters,
    children,
    pagination,
    className,
}: ListPageProps) {
    return (
        <div className={cn('mx-auto max-w-7xl', className)}>
            {/* Breadcrumbs */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <div className="mb-4">
                    <Breadcrumb items={breadcrumbs} />
                </div>
            )}

            {/* Page Header */}
            <PageHeader
                title={title}
                description={description}
                actions={actions}
            />

            {/* Filters */}
            {filters && (
                <div className="mt-6">
                    {filters}
                </div>
            )}

            {/* Main Content */}
            <div className="mt-6">
                {children}
            </div>

            {/* Pagination */}
            {pagination && (
                <div className="mt-6">
                    {pagination}
                </div>
            )}
        </div>
    );
}
