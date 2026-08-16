import { type ReactNode } from 'react';

import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { PageHeader } from '@/components/ui/PageHeader';
import { cn } from '@/lib/utils';

type DashboardPageProps = {
    /** Page title */
    title: string;
    /** Page description */
    description?: string;
    /** Breadcrumb items */
    breadcrumbs?: Array<{ label: string; href?: string; icon?: string }>;
    /** Action buttons (e.g., filters, export) */
    actions?: ReactNode;
    /** Dashboard content */
    children: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Standard dashboard page layout template.
 *
 * Features:
 * - Page header with title, description, actions
 * - Optional breadcrumb navigation
 * - Full-width content area for widgets
 * - Consistent spacing and structure
 *
 * @example
 * ```tsx
 * <DashboardPage
 *   title="Sales Dashboard"
 *   description="Overview of your sales performance"
 *   actions={
 *     <div className="flex gap-2">
 *       <Select>
 *         <option>Last 7 days</option>
 *         <option>Last 30 days</option>
 *       </Select>
 *       <Button variant="ghost">Export</Button>
 *     </div>
 *   }
 * >
 *   <StatsGrid>
 *     <StatsCard ... />
 *     <StatsCard ... />
 *   </StatsGrid>
 *   
 *   <div className="grid gap-6 lg:grid-cols-2">
 *     <SalesChart />
 *     <RevenueChart />
 *   </div>
 * </DashboardPage>
 * ```
 */
export function DashboardPage({
    title,
    description,
    breadcrumbs,
    actions,
    children,
    className,
}: DashboardPageProps) {
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

            {/* Dashboard Content */}
            <div className="mt-6 space-y-6">
                {children}
            </div>
        </div>
    );
}
