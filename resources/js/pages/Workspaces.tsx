import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Link } from 'react-router';

import { EditWorkspaceModal } from '@/components/home/EditWorkspaceModal';
import { NewWorkspace } from '@/components/home/NewWorkspace';
import { FilterBar, matchesFilters, NO_FILTERS, type Filters } from '@/components/organiser/FilterBar';
import { ItemMenu } from '@/components/organiser/ItemMenu';
import { WorkspaceBadge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/ui/PageHeader';
import { SkeletonWorkspaceRow } from '@/components/ui/Skeleton';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useOpenBusiness } from '@/hooks/useOpenBusiness';
import { organiserKey, useOrganiser } from '@/hooks/useOrganiser';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { Allowance } from '@/types';

type WorkspaceRow = {
    id: string;
    name: string;
    slug: string;
    is_active: boolean;
    business_count: number;
};

/**
 * The containers a subscription is sold in.
 *
 * The allowance comes back with the list rather than being worked out here, so
 * the button and the rule that governs it can never disagree — the server
 * computes both from the same count.
 */
export default function Workspaces() {
    const { tenant, refresh } = useSession();
    const open = useOpenBusiness();
    const queryClient = useQueryClient();
    const [filters, setFilters] = useState<Filters>(NO_FILTERS);
    const { data: organiser } = useOrganiser();
    const [editingWorkspace, setEditingWorkspace] = useState<WorkspaceRow | null>(null);

    useDocumentTitle('Workspaces');

    const { data, isPending } = useQuery({
        queryKey: ['workspaces'],
        queryFn: ({ signal }) =>
            api.get<{ data: WorkspaceRow[]; allowance: Allowance }>('/workspaces', { signal }),
    });

    const workspaces = data?.data ?? [];
    const allowance = data?.allowance ?? tenant?.allowance;
    const limit = allowance?.workspaces.limit ?? null;

    const matches = useMemo(
        () =>
            workspaces.filter((workspace) =>
                matchesFilters(
                    filters,
                    { name: workspace.name, key: organiserKey('workspace', workspace.id) },
                    organiser,
                ),
            ),
        [workspaces, filters, organiser],
    );

    const remove = async (id: string, name: string) => {
        try {
            const result = await api.delete<{ message: string }>(`/workspaces/${id}`);

            toast.success(result.message);
            void queryClient.invalidateQueries({ queryKey: ['workspaces'] });
            // Businesses went with it, so the page that lists them is stale too.
            void queryClient.invalidateQueries({ queryKey: ['businesses'] });
            void refresh();
        } catch (problem) {
            // The server refuses the last workspace standing. Its wording is
            // the reason, so show it.
            toast.error((problem as { message?: string })?.message ?? `${name} could not be removed.`);
        }
    };

    return (
        <div className="mx-auto max-w-4xl">
            <PageHeader
                title="Workspaces"
                description="Each workspace keeps its businesses apart. Your plan decides how many you get."
            />

            <FilterBar
                placeholder="Search workspaces"
                filters={filters}
                onChange={setFilters}
            />

            {isPending ? (
                <div className="card overflow-hidden">
                    {Array.from({ length: 3 }).map((_, i) => (
                        <SkeletonWorkspaceRow key={i} />
                    ))}
                </div>
            ) : workspaces.length === 0 ? (
                <div className="card overflow-hidden">
                    <EmptyState
                        icon="squares-four"
                        title="No workspaces yet"
                        body="A workspace holds your businesses — a way to keep unrelated operations properly apart."
                    />
                </div>
            ) : (
                <div className="card overflow-hidden">
                    <div className="list-head">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                {allowance?.plan ? capitalise(allowance.plan) : 'Trial'} plan
                            </p>
                            <p className="text-xs text-[var(--color-text-muted)]">
                                {allowance?.workspaces.used ?? workspaces.length} of{' '}
                                {limit === null ? 'unlimited' : limit} workspaces used
                            </p>
                        </div>

                        {allowance && !allowance.workspaces.can_add && (
                            <Link to="/billing" className="todo-action">
                                Upgrade plan
                            </Link>
                        )}
                    </div>

                    {matches.length === 0 ? (
                        <EmptyState
                            compact
                            icon="list-magnifying-glass"
                            title="Nothing matches those filters"
                            body="Try a different name, or clear the tag and favourite filters."
                        />
                    ) : (
                        <div className="divide-y divide-[var(--shell-border)]">
                            {matches.map((workspace) => {
                                const key = organiserKey('workspace', workspace.id);
                                const isFavourite = organiser?.favourites.includes(key) ?? false;

                                return (
                                    <div key={workspace.id} className="biz-row">
                                        <WorkspaceBadge 
                                            workspace={workspace}
                                            size="lg"
                                            showFavorite={isFavourite}
                                        />

                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold text-[var(--color-text-main)]">
                                                {workspace.name}
                                            </p>
                                            <p className="flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                                                <span>
                                                    {workspace.business_count}{' '}
                                                    {workspace.business_count === 1
                                                        ? 'business'
                                                        : 'businesses'}
                                                </span>
                                                {workspace.id === tenant?.workspace?.id && (
                                                    <span className="biz-open-tag">Open</span>
                                                )}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            disabled={workspace.business_count === 0}
                                            onClick={() => void open(workspace.id)}
                                            className="biz-row-action"
                                            title={
                                                workspace.business_count === 0
                                                    ? 'Add a business to it first'
                                                    : undefined
                                            }
                                        >
                                            Open
                                        </button>

                                        <ItemMenu
                                            type="workspace"
                                            id={workspace.id}
                                            name={workspace.name}
                                            holds={workspace.business_count}
                                            onDelete={() => void remove(workspace.id, workspace.name)}
                                            onEdit={() => setEditingWorkspace(workspace)}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

            <div className="mt-4">
                <NewWorkspace
                    allowance={allowance}
                    onCreated={() => void queryClient.invalidateQueries({ queryKey: ['workspaces'] })}
                />
            </div>

            {editingWorkspace && (
                <EditWorkspaceModal
                    workspace={editingWorkspace}
                    onClose={() => setEditingWorkspace(null)}
                    onUpdated={() => {
                        void queryClient.invalidateQueries({ queryKey: ['workspaces'] });
                        void refresh();
                    }}
                />
            )}
        </div>
    );
}

function capitalise(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}
