import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type ActivityItem = {
    /** Unique ID */
    id: string;
    /** Activity type */
    type: 'comment' | 'status' | 'assignment' | 'edit' | 'create' | 'delete' | 'custom';
    /** Actor name */
    actor: string;
    /** Actor avatar URL */
    actorAvatar?: string;
    /** Activity description */
    description: string;
    /** Timestamp */
    timestamp: string;
    /** Icon name (Phosphor) */
    icon?: string;
    /** Icon color variant */
    variant?: 'default' | 'success' | 'warning' | 'error' | 'info';
    /** Optional content (e.g., comment body) */
    content?: ReactNode;
};

type ActivityFeedProps = {
    /** Activity items */
    items: ActivityItem[];
    /** Additional class */
    className?: string;
};

/**
 * Activity feed component for displaying user activities and system events.
 *
 * Features:
 * - User avatars with fallback initials
 * - Activity type icons
 * - Timestamp display
 * - Optional expandable content
 * - Responsive
 * - Accessible
 *
 * @example
 * ```tsx
 * <ActivityFeed
 *   items={[
 *     {
 *       id: '1',
 *       type: 'comment',
 *       actor: 'John Doe',
 *       actorAvatar: '/avatars/john.jpg',
 *       description: 'added a comment',
 *       timestamp: '2 minutes ago',
 *       content: <p>This looks great! Let's ship it.</p>
 *     },
 *     {
 *       id: '2',
 *       type: 'status',
 *       actor: 'Jane Smith',
 *       description: 'changed status from Pending to In Progress',
 *       timestamp: '1 hour ago',
 *       variant: 'info'
 *     },
 *     {
 *       id: '3',
 *       type: 'assignment',
 *       actor: 'System',
 *       description: 'assigned this to John Doe',
 *       timestamp: '2 hours ago',
 *       variant: 'default'
 *     }
 *   ]}
 * />
 * ```
 */
export function ActivityFeed({ items, className }: ActivityFeedProps) {
    if (items.length === 0) {
        return (
            <div className="py-8 text-center text-sm text-[var(--color-text-muted)]">
                No activity yet
            </div>
        );
    }

    return (
        <div className={cn('space-y-4', className)}>
            {items.map((item) => (
                <ActivityItem key={item.id} item={item} />
            ))}
        </div>
    );
}

function ActivityItem({ item }: { item: ActivityItem }) {
    const typeConfig = {
        comment: { icon: 'chat-circle-text', variant: 'default' as const },
        status: { icon: 'arrows-clockwise', variant: 'info' as const },
        assignment: { icon: 'user-circle', variant: 'default' as const },
        edit: { icon: 'pencil-simple', variant: 'default' as const },
        create: { icon: 'plus-circle', variant: 'success' as const },
        delete: { icon: 'trash', variant: 'error' as const },
        custom: { icon: 'circle', variant: 'default' as const },
    };

    const config = typeConfig[item.type];
    const icon = item.icon || config.icon;
    const variant = item.variant || config.variant;

    const variantStyles = {
        default: 'text-[var(--color-text-muted)]',
        success: 'text-green-600',
        warning: 'text-amber-600',
        error: 'text-red-600',
        info: 'text-blue-600',
    };

    const getInitials = (name: string) => {
        const parts = name.split(' ').filter(Boolean);
        if (parts.length >= 2) {
            const first = parts[0]?.[0] || '';
            const last = parts[parts.length - 1]?.[0] || '';
            return `${first}${last}`.toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    };

    return (
        <div className="flex gap-3">
            {/* Avatar */}
            <div className="relative flex-shrink-0">
                {item.actorAvatar ? (
                    <img
                        src={item.actorAvatar}
                        alt={item.actor}
                        className="size-8 rounded-full object-cover"
                    />
                ) : (
                    <div className="flex size-8 items-center justify-center rounded-full bg-[var(--color-brand-subtle)] text-xs font-medium text-[var(--color-ink-soft)]">
                        {getInitials(item.actor)}
                    </div>
                )}

                {/* Type icon badge */}
                <div
                    className="absolute -bottom-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-white"
                    style={{ boxShadow: '0 0 0 1.5px var(--color-border-light)' }}
                >
                    <Icon
                        name={icon}
                        size={10}
                        weight="fill"
                        className={variantStyles[variant]}
                    />
                </div>
            </div>

            {/* Content */}
            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="text-sm text-[var(--color-text-main)]">
                            <span className="font-medium">{item.actor}</span>{' '}
                            <span className="text-[var(--color-text-muted)]">
                                {item.description}
                            </span>
                        </p>
                    </div>
                    <span className="flex-shrink-0 text-xs text-[var(--color-text-muted)]">
                        {item.timestamp}
                    </span>
                </div>

                {item.content && (
                    <div className="mt-2 rounded-lg bg-[var(--color-card-bg)] p-3 text-sm text-[var(--color-text-main)]">
                        {item.content}
                    </div>
                )}
            </div>
        </div>
    );
}
