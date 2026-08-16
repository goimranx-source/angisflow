import { type ReactNode } from 'react';

import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { cn } from '@/lib/utils';

type DetailPageProps = {
    /** Page title */
    title: string;
    /** Subtitle or ID */
    subtitle?: string;
    /** Breadcrumb items */
    breadcrumbs?: Array<{ label: string; href?: string; icon?: string }>;
    /** Action buttons (e.g., Edit, Delete) */
    actions?: ReactNode;
    /** Status badge or indicator */
    status?: ReactNode;
    /** Back button href */
    backHref?: string;
    /** Back button label */
    backLabel?: string;
    /** Tab configuration */
    tabs?: Array<{
        value: string;
        label: string;
        badge?: string | number;
        content: ReactNode;
    }>;
    /** Default active tab */
    defaultTab?: string;
    /** Single content (no tabs) */
    children?: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Standard detail page layout template.
 *
 * Features:
 * - Page header with title, subtitle, status badge
 * - Optional breadcrumb navigation
 * - Back button
 * - Action buttons
 * - Tabbed content or single content
 * - Consistent spacing and structure
 *
 * @example
 * ```tsx
 * <DetailPage
 *   title="Order #ORD-001"
 *   subtitle="Created on Jan 15, 2024"
 *   breadcrumbs={[
 *     { label: 'Home', href: '/' },
 *     { label: 'Orders', href: '/orders' },
 *     { label: 'ORD-001' }
 *   ]}
 *   status={<Badge variant="success">Fulfilled</Badge>}
 *   actions={
 *     <>
 *       <Button variant="ghost">Edit</Button>
 *       <Button variant="danger">Cancel</Button>
 *     </>
 *   }
 *   backHref="/orders"
 *   tabs={[
 *     {
 *       value: 'details',
 *       label: 'Details',
 *       content: <OrderDetails />
 *     },
 *     {
 *       value: 'items',
 *       label: 'Items',
 *       badge: 5,
 *       content: <OrderItems />
 *     },
 *     {
 *       value: 'history',
 *       label: 'History',
 *       content: <OrderHistory />
 *     }
 *   ]}
 *   defaultTab="details"
 * />
 * ```
 */
export function DetailPage({
    title,
    subtitle,
    breadcrumbs,
    actions,
    status,
    backHref,
    backLabel = 'Back',
    tabs,
    defaultTab,
    children,
    className,
}: DetailPageProps) {
    return (
        <div className={cn('mx-auto max-w-7xl', className)}>
            {/* Breadcrumbs */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <div className="mb-4">
                    <Breadcrumb items={breadcrumbs} />
                </div>
            )}

            {/* Back Button */}
            {backHref && (
                <div className="mb-4">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => window.location.href = backHref}
                    >
                        <Icon name="caret-left" size={14} />
                        {backLabel}
                    </Button>
                </div>
            )}

            {/* Header */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-3">
                        <h1 className="truncate text-2xl font-bold text-[var(--color-text-main)]">
                            {title}
                        </h1>
                        {status && <div>{status}</div>}
                    </div>
                    {subtitle && (
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                            {subtitle}
                        </p>
                    )}
                </div>

                {actions && (
                    <div className="flex flex-wrap gap-2">
                        {actions}
                    </div>
                )}
            </div>

            {/* Content */}
            <div className="mt-8">
                {tabs && tabs.length > 0 ? (
                    <Tabs defaultValue={defaultTab ?? tabs[0]!.value}>
                        <TabsList>
                            {tabs.map((tab) => (
                                <TabsTrigger
                                    key={tab.value}
                                    value={tab.value}
                                    badge={tab.badge}
                                >
                                    {tab.label}
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        {tabs.map((tab) => (
                            <TabsContent key={tab.value} value={tab.value}>
                                {tab.content}
                            </TabsContent>
                        ))}
                    </Tabs>
                ) : (
                    children
                )}
            </div>
        </div>
    );
}
