import { useState } from 'react';
import { Link } from 'react-router';

import { HomeAsk } from '@/components/home/HomeAsk';
import { HomeTodos } from '@/components/home/HomeTodos';
import { EditBusinessModal } from '@/components/home/EditBusinessModal';
import { EditWorkspaceModal } from '@/components/home/EditWorkspaceModal';
import { ItemMenu } from '@/components/organiser/ItemMenu';
import { BusinessBadge, WorkspaceBadge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useOpenBusiness } from '@/hooks/useOpenBusiness';
import { organiserKey, useOrganiser } from '@/hooks/useOrganiser';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { BusinessSummary, WorkspaceSummary } from '@/types';

/**
 * Where signing in lands.
 *
 * A dashboard is about one set of books; a subscriber may keep several, so
 * opening straight into one is picking wrong for anybody with more than one.
 * This is the floor above that: ask something, clear what is outstanding, or go
 * into whichever business you meant.
 */
export default function Home() {
    const { auth, tenant, refresh } = useSession();
    const open = useOpenBusiness();
    const [busyId, setBusyId] = useState<string | null>(null);
    const { data: organiser } = useOrganiser();
    const [editingWorkspace, setEditingWorkspace] = useState<WorkspaceSummary & { business_count?: number } | null>(null);
    const [editingBusiness, setEditingBusiness] = useState<BusinessSummary | null>(null);

    useDocumentTitle('Home');

    const businesses = tenant?.businesses ?? [];
    const workspaces = tenant?.workspaces ?? [];
    const firstName = auth?.user.name.split(' ')[0] ?? '';

    const workspaceName = (id: string | null) =>
        workspaces.find((w) => w.id === id)?.name ?? '';

    const countIn = (workspaceId: string) =>
        businesses.filter((b) => b.workspace === workspaceId).length;

    const removeWorkspace = async (id: string, name: string) => {
        try {
            const result = await api.delete<{ message: string }>(`/workspaces/${id}`);
            toast.success(result.message);
            void refresh();
        } catch (problem) {
            toast.error((problem as { message?: string })?.message ?? `${name} could not be removed.`);
        }
    };

    const removeBusiness = async (id: string, name: string) => {
        try {
            const result = await api.delete<{ message: string }>(`/businesses/${id}`);
            toast.success(result.message);
            void refresh();
        } catch (problem) {
            toast.error((problem as { message?: string })?.message ?? `${name} could not be removed.`);
        }
    };

    return (
        <div className="mx-auto max-w-4xl space-y-7 pb-8 sm:space-y-10 sm:pb-10">
            <section className="pt-1 text-center sm:pt-4">
                <h1 className="font-[family-name:var(--font-heading)] text-xl font-bold sm:text-[1.75rem]">
                    Hi, {firstName}! Where do you want to get started?
                </h1>

                <HomeAsk />
            </section>

            <HomeTodos />

            {/* ── Your workspaces ─────────────────────────────────────────── */}
            <section>
                <div className="mb-3 flex items-end justify-between gap-3">
                    <h2 className="font-[family-name:var(--font-heading)] text-lg font-bold">
                        Your workspaces
                    </h2>
                    <Link to="/workspaces" className="home-see-all">
                        Go to workspaces
                        <Icon name="arrow-right" size={13} weight="duotone" />
                    </Link>
                </div>

                <div className="card overflow-hidden">
                    {workspaces.length === 0 ? (
                        <EmptyState
                            compact
                            icon="squares-four"
                            title="No workspaces yet"
                            body="A workspace holds your businesses. Your plan decides how many you get."
                            action={
                                <Link to="/workspaces" className="todo-action">
                                    Add one
                                </Link>
                            }
                        />
                    ) : (
                        <div className="divide-y divide-[var(--shell-border)]">
                            {workspaces.map((workspace) => {
                                const count = countIn(workspace.id);
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
                                                    {count} {count === 1 ? 'business' : 'businesses'}
                                                </span>
                                                {workspace.id === tenant?.workspace?.id && (
                                                    <span className="biz-open-tag">Open</span>
                                                )}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            disabled={count === 0}
                                            onClick={() => void open(workspace.id)}
                                            className="biz-row-action"
                                            title={count === 0 ? 'Add a business to it first' : undefined}
                                        >
                                            Open
                                        </button>

                                        <ItemMenu
                                            type="workspace"
                                            id={workspace.id}
                                            name={workspace.name}
                                            onDelete={() => void removeWorkspace(workspace.id, workspace.name)}
                                            onEdit={() => setEditingWorkspace({ ...workspace, business_count: count })}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </section>

            {/* ── Your businesses ─────────────────────────────────────────── */}
            <section>
                <div className="mb-3 flex items-end justify-between gap-3">
                    <h2 className="font-[family-name:var(--font-heading)] text-lg font-bold">
                        Your businesses
                    </h2>
                    <Link to="/businesses" className="home-see-all">
                        Go to businesses
                        <Icon name="arrow-right" size={13} weight="duotone" />
                    </Link>
                </div>

                <div className="card overflow-hidden">
                    {businesses.length === 0 ? (
                        <EmptyState
                            compact
                            icon="buildings"
                            title="No businesses yet"
                            body="A business is one set of books — its own orders, stock and figures."
                            action={
                                <Link to="/businesses" className="todo-action">
                                    Add one
                                </Link>
                            }
                        />
                    ) : (
                        <div className="divide-y divide-[var(--shell-border)]">
                            {businesses.map((business) => {
                                const key = organiserKey('business', business.id);
                                const isFavourite = organiser?.favourites.includes(key) ?? false;

                                return (
                                    <div key={business.id} className="biz-row">
                                        <BusinessBadge 
                                            business={business}
                                            size="lg"
                                            showFavorite={isFavourite}
                                        />

                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold text-[var(--color-text-main)]">
                                                {business.name}
                                            </p>
                                            <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-[var(--color-text-muted)]">
                                                <span>{workspaceName(business.workspace)}</span>
                                                <span aria-hidden>·</span>
                                                <span>{business.currency}</span>
                                                {business.id === tenant?.business?.id && (
                                                    <span className="biz-open-tag">Open</span>
                                                )}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            disabled={busyId === business.id}
                                            onClick={() => {
                                                setBusyId(business.id);
                                                void open(business.workspace, business.id).finally(() =>
                                                    setBusyId(null),
                                                );
                                            }}
                                            className="biz-row-action"
                                        >
                                            {busyId === business.id ? 'Opening…' : 'Dashboard'}
                                        </button>

                                        <ItemMenu
                                            type="business"
                                            id={business.id}
                                            name={business.name}
                                            onDelete={() => void removeBusiness(business.id, business.name)}
                                            onEdit={() => setEditingBusiness(business)}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </section>

            {/* Edit Modals */}
            {editingWorkspace && (
                <EditWorkspaceModal
                    workspace={editingWorkspace}
                    onClose={() => setEditingWorkspace(null)}
                    onUpdated={() => void refresh()}
                />
            )}

            {editingBusiness && (
                <EditBusinessModal
                    business={editingBusiness}
                    onClose={() => setEditingBusiness(null)}
                    onUpdated={() => void refresh()}
                />
            )}
        </div>
    );
}
