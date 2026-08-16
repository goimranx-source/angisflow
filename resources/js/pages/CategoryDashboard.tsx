import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { useSession } from '@/providers/SessionProvider';

type CategoryModule = {
    key: string;
    label: string;
    icon?: string;
    summary?: string;
    path: string;
    is_built: boolean;
};

type CategoryDashboardPayload = {
    data: {
        category: {
            key: string;
            name: string;
            description: string;
        };
        modules: {
            enabled: CategoryModule[];
            coming_soon: CategoryModule[];
        };
        quick_actions: Array<{
            label: string;
            description: string;
            icon: string;
            href: string;
            action_type: 'primary' | 'secondary';
        }>;
        setup_progress: {
            completed: number;
            total: number;
            steps: Array<{
                id: string;
                title: string;
                description: string;
                completed: boolean;
                action_href?: string;
            }>;
        };
    };
};

export default function CategoryDashboard() {
    useDocumentTitle('Getting Started');

    const { tenant } = useSession();
    const business = tenant?.business;

    const { data, isLoading } = useQuery({
        queryKey: ['category-dashboard', business?.id],
        queryFn: ({ signal }) =>
            api.get<CategoryDashboardPayload>('/category-dashboard', { signal }),
        enabled: !!business,
    });

    const payload = data?.data;

    if (!business || isLoading) {
        return <div className="p-6">Loading...</div>;
    }

    return (
        <div className="mx-auto max-w-5xl space-y-8 pb-10">
            {/* Hero Section */}
            <section className="rounded-lg bg-gradient-to-r from-[var(--color-brand-subtle)] to-[var(--color-site-bg)] p-8">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-[var(--color-text-main)]">
                            {payload?.category.name || 'Welcome to your workspace'}
                        </h1>
                        <p className="mt-2 text-lg text-[var(--color-text-body)]">
                            {payload?.category.description ||
                                'Get started with the features built for your business type'}
                        </p>
                    </div>
                </div>
            </section>

            {/* Quick Actions */}
            {payload?.quick_actions && payload.quick_actions.length > 0 && (
                <section>
                    <h2 className="mb-4 text-xl font-bold text-[var(--color-text-main)]">
                        Quick Actions
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {payload.quick_actions.map((action) => (
                            <Link
                                key={action.label}
                                to={action.href}
                                className={`group flex flex-col gap-3 rounded-lg border p-4 transition-all ${
                                    action.action_type === 'primary'
                                        ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)] hover:bg-[var(--color-brand-subtle)]'
                                        : 'border-[var(--color-border-light)] bg-[var(--color-card-bg)] hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-subtle)]'
                                }`}
                            >
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="font-semibold text-[var(--color-text-main)]">
                                            {action.label}
                                        </h3>
                                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                            {action.description}
                                        </p>
                                    </div>
                                    <Icon name={action.icon} size={20} />
                                </div>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* Setup Progress */}
            {payload?.setup_progress && (
                <section>
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-xl font-bold text-[var(--color-text-main)]">
                            Setup Progress
                        </h2>
                        <span className="text-sm font-semibold text-[var(--color-text-body)]">
                            {payload.setup_progress.completed} of {payload.setup_progress.total}
                        </span>
                    </div>

                    {/* Progress Bar */}
                    <div className="mb-6 h-2 overflow-hidden rounded-full bg-[var(--color-border-light)]">
                        <div
                            className="h-full bg-gradient-to-r from-[var(--color-brand)] to-[var(--color-brand-hover)] transition-all"
                            style={{
                                width: `${
                                    (payload.setup_progress.completed /
                                        payload.setup_progress.total) *
                                    100
                                }%`,
                            }}
                        />
                    </div>

                    {/* Setup Steps */}
                    <div className="space-y-3">
                        {payload.setup_progress.steps.map((step) => (
                            <div
                                key={step.id}
                                className={`flex items-start gap-4 rounded-lg border p-4 ${
                                    step.completed
                                        ? 'border-[var(--color-border-light)] bg-[var(--color-site-bg)]'
                                        : 'border-[var(--color-border-light)] bg-[var(--color-card-bg)]'
                                }`}
                            >
                                <div className="mt-1 flex-shrink-0">
                                    <div
                                        className={`flex h-6 w-6 items-center justify-center rounded-full ${
                                            step.completed
                                                ? 'bg-[var(--color-success)]'
                                                : 'border-2 border-[var(--color-text-subtle)]'
                                        }`}
                                    >
                                        {step.completed && (
                                            <Icon name="check" size={14} className="text-white" />
                                        )}
                                    </div>
                                </div>

                                <div className="flex-1">
                                    <h3
                                        className={`font-semibold ${
                                            step.completed
                                                ? 'text-[var(--color-text-muted)]'
                                                : 'text-[var(--color-text-main)]'
                                        }`}
                                    >
                                        {step.title}
                                    </h3>
                                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                        {step.description}
                                    </p>
                                    {!step.completed && step.action_href && (
                                        <Link
                                            to={step.action_href}
                                            className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-[var(--color-brand)] hover:text-[var(--color-brand-hover)]"
                                        >
                                            Get started
                                            <Icon name="arrow-right" size={14} />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {/* Available Modules */}
            {payload?.modules && (
                <>
                    {payload.modules.enabled.length > 0 && (
                        <section>
                            <h2 className="mb-4 text-xl font-bold text-[var(--color-text-main)]">
                                Your Features ({payload.modules.enabled.length})
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {payload.modules.enabled.map((module) => (
                                    <Link
                                        key={module.key}
                                        to={module.path}
                                        className="group flex flex-col gap-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-card-bg)] p-4 transition-all hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-subtle)]"
                                    >
                                        {module.icon && (
                                            <Icon
                                                name={module.icon}
                                                size={24}
                                                className="text-[var(--color-brand)]"
                                            />
                                        )}
                                        <h3 className="font-semibold text-[var(--color-text-main)]">
                                            {module.label}
                                        </h3>
                                        {module.summary && (
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                {module.summary}
                                            </p>
                                        )}
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    {payload.modules.coming_soon.length > 0 && (
                        <section>
                            <h2 className="mb-4 text-xl font-bold text-[var(--color-text-main)]">
                                Coming Soon ({payload.modules.coming_soon.length})
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {payload.modules.coming_soon.map((module) => (
                                    <div
                                        key={module.key}
                                        className="flex flex-col gap-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-site-bg)] p-4 opacity-60"
                                    >
                                        {module.icon && (
                                            <Icon name={module.icon} size={24} />
                                        )}
                                        <h3 className="font-semibold text-[var(--color-text-main)]">
                                            {module.label}
                                        </h3>
                                        {module.summary && (
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                {module.summary}
                                            </p>
                                        )}
                                        <div className="mt-2 inline-flex items-center gap-2 rounded-full bg-[var(--color-warning-subtle)] px-3 py-1 text-xs font-semibold text-[var(--color-warning)]">
                                            <Icon name="hourglass" size={12} />
                                            Coming soon
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                </>
            )}
        </div>
    );
}
