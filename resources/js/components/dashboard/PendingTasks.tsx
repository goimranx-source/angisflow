import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Task = {
    id: string;
    type: 'invoice' | 'payment' | 'order' | 'approval' | 'other';
    title: string;
    description: string;
    icon: string;
    href: string;
    priority: 'high' | 'medium' | 'low';
};

type PendingTasksData = {
    data: {
        tasks: Task[];
    };
};

type PendingTasksProps = {
    /** Additional class */
    className?: string;
};

/**
 * Pending tasks widget for dashboard.
 *
 * Features:
 * - Shows actionable items requiring attention
 * - Icon and priority indicators
 * - Click to navigate to task
 * - Loading and empty states
 */
export function PendingTasks({ className }: PendingTasksProps) {
    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'pending-tasks'],
        queryFn: ({ signal }) =>
            api.get<PendingTasksData>('/dashboard/pending-tasks', { signal }),
    });

    const tasks = data?.data.tasks ?? [];

    const priorityColors = {
        high: 'text-red-600',
        medium: 'text-amber-600',
        low: 'text-gray-500',
    };

    return (
        <div className={cn('card p-6', className)}>
            {/* Header */}
            <div className="flex items-center justify-between">
                <h3 className="font-semibold text-[var(--color-text-main)]">
                    Pending Tasks
                </h3>
                {tasks.length > 0 && (
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-xs font-semibold text-red-600">
                        {tasks.length}
                    </span>
                )}
            </div>

            {/* Content */}
            <div className="mt-4">
                {isError ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Icon
                            name="warning-circle"
                            size={24}
                            className="text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            Could not load tasks
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="mt-2 text-sm font-medium text-[var(--color-brand)] hover:underline"
                        >
                            Try again
                        </button>
                    </div>
                ) : isPending ? (
                    <div className="space-y-3">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex items-start gap-3 animate-pulse"
                            >
                                <div className="mt-0.5 h-8 w-8 rounded-lg bg-[var(--color-surface)]" />
                                <div className="flex-1">
                                    <div className="h-4 w-32 rounded bg-[var(--color-surface)]" />
                                    <div className="mt-1 h-3 w-48 rounded bg-[var(--color-surface)]" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : tasks.length === 0 ? (
                    <div className="py-8 text-center">
                        <Icon
                            name="check-circle"
                            size={32}
                            weight="fill"
                            className="mx-auto text-green-500"
                        />
                        <p className="mt-2 text-sm font-medium text-[var(--color-text-main)]">
                            All caught up!
                        </p>
                        <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                            No pending tasks
                        </p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {tasks.map((task) => (
                            <a
                                key={task.id}
                                href={task.href}
                                className="flex items-start gap-3 rounded-lg p-2 transition-colors hover:bg-[var(--color-surface)]"
                            >
                                {/* Icon */}
                                <div className="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-[var(--color-brand-subtle)]">
                                    <Icon
                                        name={task.icon}
                                        size={16}
                                        className="text-[var(--color-ink-soft)]"
                                    />
                                </div>

                                {/* Content */}
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="text-sm font-medium text-[var(--color-text-main)]">
                                            {task.title}
                                        </p>
                                        {task.priority === 'high' && (
                                            <Icon
                                                name="warning-circle"
                                                size={14}
                                                weight="fill"
                                                className={priorityColors[task.priority]}
                                            />
                                        )}
                                    </div>
                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                        {task.description}
                                    </p>
                                </div>
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
