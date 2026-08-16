import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Notification = {
    id: string;
    type: 'info' | 'success' | 'warning' | 'error';
    title: string;
    message: string;
    icon: string;
    href?: string;
    created_at_human: string;
    read: boolean;
};

type NotificationsData = {
    data: {
        notifications: Notification[];
        unread_count: number;
    };
};

type NotificationBellProps = {
    /** Additional class */
    className?: string;
};

/**
 * Notification bell dropdown for dashboard/topbar.
 *
 * Features:
 * - Unread count badge
 * - Dropdown with recent notifications
 * - Type-based icons and colors
 * - Click to navigate
 * - Mark as read
 */
export function NotificationBell({ className }: NotificationBellProps) {
    const [isOpen, setIsOpen] = useState(false);

    const { data, isPending } = useQuery({
        queryKey: ['notifications'],
        queryFn: ({ signal }) =>
            api.get<NotificationsData>('/notifications', {
                params: { limit: 10 },
                signal,
            }),
        refetchInterval: 60000, // Refetch every minute
    });

    const notifications = data?.data.notifications ?? [];
    const unreadCount = data?.data.unread_count ?? 0;

    const typeConfig = {
        info: { color: 'text-blue-600', bg: 'bg-blue-100' },
        success: { color: 'text-green-600', bg: 'bg-green-100' },
        warning: { color: 'text-amber-600', bg: 'bg-amber-100' },
        error: { color: 'text-red-600', bg: 'bg-red-100' },
    };

    return (
        <div className={cn('relative', className)}>
            {/* Bell Button */}
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="relative rounded-lg p-2 text-[var(--color-text-muted)] transition-colors hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-ink-soft)]"
                aria-label="Notifications"
            >
                <Icon name="bell" size={20} />
                {unreadCount > 0 && (
                    <span className="absolute right-1 top-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[0.625rem] font-semibold text-white">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </button>

            {/* Dropdown */}
            {isOpen && (
                <>
                    {/* Backdrop */}
                    <div
                        className="fixed inset-0 z-40"
                        onClick={() => setIsOpen(false)}
                        aria-hidden
                    />

                    {/* Dropdown Panel */}
                    <div className="absolute right-0 top-full z-50 mt-2 w-80 rounded-lg border border-[var(--color-border-light)] bg-white shadow-lg">
                        {/* Header */}
                        <div className="flex items-center justify-between border-b border-[var(--color-border-light)] px-4 py-3">
                            <h3 className="font-semibold text-[var(--color-text-main)]">
                                Notifications
                            </h3>
                            {unreadCount > 0 && (
                                <span className="text-xs text-[var(--color-text-muted)]">
                                    {unreadCount} unread
                                </span>
                            )}
                        </div>

                        {/* Notifications List */}
                        <div className="max-h-[400px] overflow-y-auto">
                            {isPending ? (
                                <div className="space-y-2 p-4">
                                    {Array.from({ length: 3 }).map((_, i) => (
                                        <div
                                            key={i}
                                            className="flex gap-3 animate-pulse"
                                        >
                                            <div className="h-8 w-8 rounded-lg bg-[var(--color-surface)]" />
                                            <div className="flex-1">
                                                <div className="h-4 w-32 rounded bg-[var(--color-surface)]" />
                                                <div className="mt-1 h-3 w-48 rounded bg-[var(--color-surface)]" />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : notifications.length === 0 ? (
                                <div className="py-12 text-center">
                                    <Icon
                                        name="check-circle"
                                        size={32}
                                        className="mx-auto text-[var(--color-text-subtle)]"
                                    />
                                    <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                                        No notifications
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-[var(--color-border-light)]">
                                    {notifications.map((notification) => {
                                        const config = typeConfig[notification.type];
                                        const Component = notification.href ? 'a' : 'div';

                                        return (
                                            <Component
                                                key={notification.id}
                                                href={notification.href}
                                                className={cn(
                                                    'flex gap-3 p-4 transition-colors',
                                                    notification.href && 'hover:bg-[var(--color-surface)]',
                                                    !notification.read && 'bg-blue-50',
                                                )}
                                            >
                                                {/* Icon */}
                                                <div
                                                    className={cn(
                                                        'flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg',
                                                        config.bg,
                                                    )}
                                                >
                                                    <Icon
                                                        name={notification.icon}
                                                        size={16}
                                                        className={config.color}
                                                    />
                                                </div>

                                                {/* Content */}
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium text-[var(--color-text-main)]">
                                                        {notification.title}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                        {notification.message}
                                                    </p>
                                                    <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                                        {notification.created_at_human}
                                                    </p>
                                                </div>

                                                {/* Unread Indicator */}
                                                {!notification.read && (
                                                    <div className="flex-shrink-0">
                                                        <div className="h-2 w-2 rounded-full bg-[var(--color-brand)]" />
                                                    </div>
                                                )}
                                            </Component>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        {notifications.length > 0 && (
                            <div className="border-t border-[var(--color-border-light)] p-2">
                                <a
                                    href="/notifications"
                                    className="flex items-center justify-center gap-1 rounded-lg p-2 text-sm font-medium text-[var(--color-brand)] transition-colors hover:bg-[var(--color-brand-subtle)]"
                                >
                                    View all notifications
                                    <Icon name="arrow-right" size={14} />
                                </a>
                            </div>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
