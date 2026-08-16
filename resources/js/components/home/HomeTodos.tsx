import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { useSession } from '@/providers/SessionProvider';

type Todo = {
    key: string;
    icon: string;
    title: string;
    body: string;
    action: string;
    to: string;
    tone?: 'warning';
};

/**
 * What is actually outstanding on this account.
 *
 * ── Why the rows can come back ───────────────────────────────────────────────
 *
 * Every one of these is derived from live state — the trial is running, the
 * address is unverified, two-factor is off. Dismissing one records that decision
 * against the person, not against the state. So a row that reappears because the
 * state came back — a plan lapsing, two-factor being switched off again — is
 * correct rather than a bug: the thing is outstanding again, and hiding it for
 * ever would be the list lying.
 */
export function HomeTodos() {
    const { auth, tenant, app, refresh, todo_marks } = useSession();
    const queryClient = useQueryClient();
    const [showAll, setShowAll] = useState(false);
    
    // Initialize showDismissed from localStorage
    const [showDismissed, setShowDismissed] = useState(() => {
        const saved = localStorage.getItem('homeTodos_showDismissed');
        return saved === 'true';
    });

    // Persist showDismissed to localStorage whenever it changes
    const toggleShowDismissed = (value: boolean) => {
        setShowDismissed(value);
        localStorage.setItem('homeTodos_showDismissed', String(value));
    };

    const dismiss = useMutation({
        mutationFn: (key: string) =>
            api.post<{ marks: Record<string, string> }>(`/todos/${key}`, { state: 'dismissed' }),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['boot'] });
            void refresh();
            // Don't reset showDismissed state so user stays on current view
        },
    });

    const restore = useMutation({
        mutationFn: (key: string) =>
            api.post<{ marks: Record<string, string> }>(`/todos/${key}`, { state: 'open' }),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['boot'] });
            void refresh();
            // Don't reset showDismissed state so user stays on current view
        },
    });

    const account = tenant?.account;
    const marks = todo_marks ?? {};
    const allTodos: Todo[] = [];

    // Onboarding todos - show if onboarding is not complete
    if (account && !account.onboarding_completed) {
        const onboardingSteps = account.onboarding_steps || {};

        if (!onboardingSteps.workspace_created) {
            allTodos.push({
                key: 'onboarding-workspace',
                icon: 'squares-four',
                title: 'Create your first workspace',
                body: 'A workspace organizes your businesses and teams together.',
                action: 'Create workspace',
                to: '/workspaces',
            });
        }

        if (!onboardingSteps.business_created) {
            allTodos.push({
                key: 'onboarding-business',
                icon: 'buildings',
                title: 'Add your first business',
                body: 'A business is one set of books — its own orders, stock and figures.',
                action: 'Add business',
                to: '/businesses',
            });
        }

        if (!onboardingSteps.plan_selected || !onboardingSteps.payment_confirmed) {
            allTodos.push({
                key: 'onboarding-plan',
                icon: 'gift',
                title: 'Complete your trial setup',
                body: 'Choose a plan to unlock all features. Your 15-day trial is waiting.',
                action: 'Choose plan',
                to: '/billing',
            });
        }
    }

    if (auth && !auth.user.email_verified) {
        allTodos.push({
            key: 'verify',
            icon: 'warning',
            title: 'Verify your email address',
            body: 'It is how you get back in if you are ever locked out.',
            action: 'Verify',
            to: '/profile',
            tone: 'warning',
        });
    }

    if (account?.status === 'trialing') {
        const days = account.trial_ends_at
            ? Math.max(0, Math.ceil((Date.parse(account.trial_ends_at) - Date.now()) / 86_400_000))
            : null;

        allTodos.push({
            key: 'plan',
            icon: 'wallet',
            title: days === null ? 'Choose a plan' : `Choose a plan — ${days} days left`,
            body: 'Nothing is lost when the trial ends; picking early changes nothing else.',
            action: 'See plans',
            to: '/billing',
        });
    }

    if (auth && !auth.user.two_factor_enabled) {
        allTodos.push({
            key: 'two-factor',
            icon: 'shield-check',
            title: 'Turn on two-factor',
            body: 'A password alone is one leak away from somebody else\'s books.',
            action: 'Set up',
            to: '/profile',
        });
    }

    if (!app.logo) {
        allTodos.push({
            key: 'brand',
            icon: 'paint-brush',
            title: 'Add your own logo',
            body: 'It replaces ours on your documents and on the sign-in screen.',
            action: 'Add it',
            to: '/settings/appearance',
        });
    }

    // Filter out dismissed todos
    const activeTodos = allTodos.filter((todo) => marks[todo.key] !== 'dismissed');
    const dismissedTodos = allTodos.filter((todo) => marks[todo.key] === 'dismissed');
    const dismissedCount = dismissedTodos.length;
    
    // Determine which todos to display
    const todosToShow = showDismissed ? allTodos : activeTodos;
    const displayTodos = showAll ? todosToShow : todosToShow.slice(0, 10);
    const hasMore = todosToShow.length > 10;

    // If all are dismissed or no todos exist, show empty state
    if (activeTodos.length === 0 && !showDismissed) {
        return (
            <section>
                <h2 className="mb-3 flex items-center gap-2 font-[family-name:var(--font-heading)] text-lg font-bold">
                    Your to-dos
                </h2>

                <div className="card p-4">
                    <div className="flex items-center gap-3">
                        <span className="todo-icon">
                            <Icon name="check-circle" size={17} weight="duotone" />
                        </span>

                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                You've got no new actions
                            </p>
                            <p className="text-[0.8125rem] text-[var(--color-text-muted)]">
                                Keep your services active and running smoothly by completing the
                                recommended actions
                            </p>
                        </div>

                        {dismissedCount > 0 && allTodos.length > 0 && (
                            <button
                                type="button"
                                onClick={() => toggleShowDismissed(true)}
                                className="todo-action"
                            >
                                View all
                            </button>
                        )}
                    </div>
                </div>
            </section>
        );
    }

    return (
        <section>
            <h2 className="mb-3 flex items-center gap-2 font-[family-name:var(--font-heading)] text-lg font-bold">
                Your to-dos
                <span className="todo-count">{activeTodos.length}</span>
                {dismissedCount > 0 && (
                    <button
                        type="button"
                        onClick={() => {
                            toggleShowDismissed(!showDismissed);
                            if (!showDismissed) {
                                setShowAll(false); // Reset show all when toggling dismissed view
                            }
                        }}
                        className="cursor-pointer text-sm font-medium text-[var(--color-brand)] hover:opacity-80 transition-opacity"
                    >
                        {showDismissed ? `Hide all (${dismissedCount})` : `View all (${allTodos.length})`}
                    </button>
                )}
            </h2>

            <div className="card divide-y divide-[var(--shell-border)] overflow-hidden">
                {displayTodos.map((todo) => {
                    const isDismissed = marks[todo.key] === 'dismissed';
                    
                    return (
                        <div 
                            key={todo.key} 
                            className="todo-row"
                            style={isDismissed ? { opacity: 0.6 } : undefined}
                        >
                            <span className={`todo-icon ${todo.tone === 'warning' ? 'is-warning' : ''}`}>
                                <Icon name={todo.icon} size={17} weight="duotone" />
                            </span>

                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                    {todo.title}
                                </p>
                                <p className="text-[0.8125rem] text-[var(--color-text-muted)]">
                                    {todo.body}
                                </p>
                            </div>

                            <Link to={todo.to} className="todo-action">
                                {todo.action}
                            </Link>

                            {isDismissed ? (
                                <button
                                    type="button"
                                    onClick={() => restore.mutate(todo.key)}
                                    className="row-menu-trigger"
                                    title="Restore"
                                >
                                    <Icon name="arrow-counter-clockwise" size={16} weight="duotone" />
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => dismiss.mutate(todo.key)}
                                    className="row-menu-trigger"
                                    title="Dismiss"
                                >
                                    <Icon name="x" size={16} weight="duotone" />
                                </button>
                            )}
                        </div>
                    );
                })}

                {!showAll && hasMore && (
                    <div className="p-4 text-center">
                        <button
                            type="button"
                            onClick={() => setShowAll(true)}
                            className="cursor-pointer text-sm font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                        >
                            Show more
                        </button>
                    </div>
                )}
            </div>
        </section>
    );
}
