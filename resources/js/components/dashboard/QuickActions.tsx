import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { Panel } from '@/components/ui/Panel';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { cn } from '@/lib/utils';

type QuickAction = {
    id: string;
    label: string;
    description: string;
    icon: string;
    href: string;
    variant?: 'default' | 'primary' | 'success';
};

type QuickActionsData = {
    data: {
        actions: QuickAction[];
    };
};

type QuickActionsProps = {
    /** Additional class */
    className?: string;
};

/**
 * Quick actions panel for dashboard.
 *
 * Features:
 * - Category-specific shortcuts
 * - Icon and description
 * - Click to navigate
 * - Responsive grid layout
 * - Backend-driven (adapts to business category)
 */
export function QuickActions({ className }: QuickActionsProps) {
    const business = useBusinessScope();

    const { data, isPending, isError } = useQuery({
        queryKey: ['dashboard', 'quick-actions', business],
        queryFn: ({ signal }) =>
            api.get<QuickActionsData>('/dashboard/quick-actions', { signal }),
    });

    const actions = data?.data.actions ?? [];

    const variantStyles = {
        default: 'bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]',
        primary: 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]',
        success: 'bg-[var(--color-success-subtle)] text-[var(--color-success)]',
    };

    return (
        <Panel
            title="Quick actions"
            action={
                <Icon
                    name="lightning"
                    size={15}
                    weight="fill"
                    className="text-[var(--color-warning)]"
                />
            }
            className={className}
        >
            <div>
                {isError ? (
                    <div className="py-8 text-center">
                        <p className="text-sm text-[var(--color-text-muted)]">
                            Could not load quick actions
                        </p>
                    </div>
                ) : isPending ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex items-start gap-3 rounded-lg border border-[var(--color-border-light)] p-3 animate-pulse"
                            >
                                <div className="h-10 w-10 rounded-lg bg-[var(--color-card-bg)]" />
                                <div className="flex-1">
                                    <div className="h-4 w-24 rounded bg-[var(--color-card-bg)]" />
                                    <div className="mt-1 h-3 w-32 rounded bg-[var(--color-card-bg)]" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : actions.length === 0 ? (
                    <div className="py-8 text-center">
                        <Icon
                            name="magic-wand"
                            size={32}
                            className="mx-auto text-[var(--color-text-subtle)]"
                        />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                            No quick actions available
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {actions.map((action) => (
                            <a
                                key={action.id}
                                href={action.href}
                                className="group flex items-start gap-3 rounded-lg border border-[var(--color-border-light)] p-3 transition-all hover:border-[var(--color-brand)] hover:shadow-sm"
                            >
                                {/* Icon */}
                                <div
                                    className={cn(
                                        'flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg transition-colors',
                                        variantStyles[action.variant || 'default'],
                                    )}
                                >
                                    <Icon name={action.icon} size={20} />
                                </div>

                                {/* Content */}
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-[var(--color-text-main)] group-hover:text-[var(--color-brand)]">
                                        {action.label}
                                    </p>
                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                        {action.description}
                                    </p>
                                </div>

                                {/* Arrow */}
                                <Icon
                                    name="arrow-right"
                                    size={16}
                                    className="flex-shrink-0 text-[var(--color-text-subtle)] opacity-0 transition-opacity group-hover:opacity-100"
                                />
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </Panel>
    );
}
