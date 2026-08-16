import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type TimelineItem = {
    /** Unique ID */
    id: string;
    /** Event title */
    title: string;
    /** Event description */
    description?: string;
    /** Timestamp */
    timestamp: string;
    /** Icon name (Phosphor) */
    icon?: string;
    /** Icon color variant */
    variant?: 'default' | 'success' | 'warning' | 'error' | 'info';
    /** Optional content */
    content?: ReactNode;
};

type TimelineProps = {
    /** Timeline items */
    items: TimelineItem[];
    /** Additional class */
    className?: string;
};

/**
 * Timeline component for displaying chronological events.
 *
 * Features:
 * - Vertical timeline with connecting line
 * - Icon indicators with color variants
 * - Title, description, timestamp
 * - Optional custom content
 * - Responsive
 *
 * @example
 * ```tsx
 * <Timeline
 *   items={[
 *     {
 *       id: '1',
 *       title: 'Order created',
 *       description: 'Order #ORD-001 was created by John Doe',
 *       timestamp: '2 hours ago',
 *       icon: 'shopping-cart',
 *       variant: 'default'
 *     },
 *     {
 *       id: '2',
 *       title: 'Payment received',
 *       description: '$150.00 via Credit Card',
 *       timestamp: '1 hour ago',
 *       icon: 'currency-dollar',
 *       variant: 'success'
 *     },
 *     {
 *       id: '3',
 *       title: 'Order shipped',
 *       description: 'Tracking: TRK123456789',
 *       timestamp: '30 minutes ago',
 *       icon: 'truck',
 *       variant: 'info',
 *       content: <Button size="sm">Track shipment</Button>
 *     }
 *   ]}
 * />
 * ```
 */
export function Timeline({ items, className }: TimelineProps) {
    if (items.length === 0) {
        return (
            <div className="py-8 text-center text-sm text-[var(--color-text-muted)]">
                No activity yet
            </div>
        );
    }

    return (
        <div className={cn('space-y-4', className)}>
            {items.map((item, index) => (
                <TimelineItem
                    key={item.id}
                    item={item}
                    isLast={index === items.length - 1}
                />
            ))}
        </div>
    );
}

function TimelineItem({ item, isLast }: { item: TimelineItem; isLast: boolean }) {
    const variantStyles = {
        default: {
            bg: 'bg-[var(--color-border-light)]',
            icon: 'text-[var(--color-text-muted)]',
        },
        success: {
            bg: 'bg-green-100',
            icon: 'text-green-600',
        },
        warning: {
            bg: 'bg-amber-100',
            icon: 'text-amber-600',
        },
        error: {
            bg: 'bg-red-100',
            icon: 'text-red-600',
        },
        info: {
            bg: 'bg-blue-100',
            icon: 'text-blue-600',
        },
    };

    const variant = item.variant || 'default';
    const styles = variantStyles[variant];

    return (
        <div className="relative flex gap-4">
            {/* Timeline line and icon */}
            <div className="relative flex flex-col items-center">
                {/* Icon */}
                <div
                    className={cn(
                        'flex size-8 flex-shrink-0 items-center justify-center rounded-full',
                        styles.bg,
                    )}
                >
                    <Icon
                        name={item.icon || 'circle'}
                        size={16}
                        weight="fill"
                        className={styles.icon}
                    />
                </div>

                {/* Connecting line */}
                {!isLast && (
                    <div className="w-0.5 flex-1 bg-[var(--color-border-light)] mt-2" />
                )}
            </div>

            {/* Content */}
            <div className="min-w-0 flex-1 pb-8">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="font-medium text-[var(--color-text-main)]">
                            {item.title}
                        </p>
                        {item.description && (
                            <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">
                                {item.description}
                            </p>
                        )}
                    </div>
                    <p className="flex-shrink-0 text-xs text-[var(--color-text-muted)]">
                        {item.timestamp}
                    </p>
                </div>

                {item.content && (
                    <div className="mt-2">
                        {item.content}
                    </div>
                )}
            </div>
        </div>
    );
}
