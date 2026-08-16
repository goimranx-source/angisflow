import { useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Link } from 'react-router';

import { EditBusinessModal } from '@/components/home/EditBusinessModal';
import { NewBusiness } from '@/components/home/NewBusiness';
import { FilterBar, matchesFilters, NO_FILTERS, type Filters } from '@/components/organiser/FilterBar';
import { ItemMenu } from '@/components/organiser/ItemMenu';
import { BusinessBadge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useOpenBusiness } from '@/hooks/useOpenBusiness';
import { organiserKey, useOrganiser } from '@/hooks/useOrganiser';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload, BusinessSummary } from '@/types';

/**
 * Every set of books, grouped by the workspace holding it.
 *
 * ── Why grouped rather than one flat list ────────────────────────────────────
 *
 * The workspace is what the plan is sold in, and it is what decides whether
 * another business can be added. A flat list hides that: "add a business"
 * belongs to a particular workspace, and a single button at the top of an
 * undivided list cannot say which.
 *
 * The list comes from the shell rather than its own request — it is already
 * there, it is small, and it is the same list the sidebar's picker reads. Two
 * copies would eventually disagree about which businesses exist.
 */
export default function Businesses() {
    const { tenant, apply, refresh } = useSession();
    const open = useOpenBusiness();
    const queryClient = useQueryClient();
    const [busyId, setBusyId] = useState<string | null>(null);
    const [filters, setFilters] = useState<Filters>(NO_FILTERS);
    const { data: organiser } = useOrganiser();
    const [expandedWarnings, setExpandedWarnings] = useState<Set<string>>(new Set());
    const [editingBusiness, setEditingBusiness] = useState<BusinessSummary | null>(null);

    useDocumentTitle('Businesses');

    const businesses = tenant?.businesses ?? [];
    const workspaces = tenant?.workspaces ?? [];
    const workspaceAllowances = tenant?.workspace_allowances ?? {};

    // One group per workspace, each already narrowed by the search. A workspace
    // whose businesses all fail the search drops out entirely rather than
    // sitting there as an empty heading.
    const filtering =
        filters.query.trim() !== '' || filters.favouritesOnly || filters.tags.length > 0;

    const groups = useMemo(
        () =>
            workspaces
                .map((workspace) => ({
                    workspace,
                    items: businesses.filter(
                        (business) =>
                            business.workspace === workspace.id &&
                            matchesFilters(
                                filters,
                                { name: business.name, key: organiserKey('business', business.id) },
                                organiser,
                            ),
                    ),
                }))
                // A workspace whose businesses all fail the filter drops out
                // rather than sitting there as an empty heading.
                .filter((group) => (filtering ? group.items.length > 0 : true)),
        [workspaces, businesses, filters, organiser, filtering],
    );

    const remove = async (id: string, name: string) => {
        try {
            // The shell comes back with it: removing the business somebody is
            // currently in has to leave them somewhere real, and the server is
            // what decides where.
            const result = await api.delete<{ message: string; boot?: BootPayload }>(
                `/businesses/${id}`,
            );

            toast.success(result.message);

            if (result.boot) {
                apply(result.boot);
            }

            // The workspaces page keeps its own list, with a business count on
            // each row. Applying the shell here does not touch it, so without
            // this it goes on showing the count from before the deletion until
            // something else happens to refetch it.
            void queryClient.invalidateQueries({ queryKey: ['workspaces'] });
        } catch (problem) {
            toast.error((problem as { message?: string })?.message ?? `${name} could not be removed.`);
        }
    };

    return (
        <div className="mx-auto max-w-4xl">
            <PageHeader
                title="Businesses"
                description="Every set of books you keep, grouped by the workspace holding it."
            />

            <FilterBar
                placeholder="Search businesses"
                filters={filters}
                onChange={setFilters}
            />

            {/* No blanket "no businesses yet" screen here.
             *
             * There used to be one, shown whenever the account held no
             * businesses at all, and it sent people to the workspaces page —
             * which has no way to add a business either. Both screens pointed
             * at each other and neither could do the thing, so an account that
             * lost its last business had no route back to having one.
             *
             * The grouped list below already handles this properly: every
             * workspace draws its own card with its own allowance and its own
             * Add button, and says "nothing in this workspace yet" inside.
             * That is the screen somebody in this state needs. The only case
             * with genuinely nothing to draw is having no workspaces. */}
            {workspaces.length === 0 ? (
                <div className="card overflow-hidden">
                    <EmptyState
                        icon="buildings"
                        title="No workspaces yet"
                        body="Businesses live inside a workspace, so there needs to be one first."
                        action={
                            <Link to="/workspaces" className="todo-action">
                                Go to workspaces
                            </Link>
                        }
                    />
                </div>
            ) : groups.length === 0 ? (
                <div className="card overflow-hidden">
                    <EmptyState
                        icon="list-magnifying-glass"
                        title="Nothing matches those filters"
                        body="Try a different name, or clear the tag and favourite filters."
                    />
                </div>
            ) : (
                <div className="space-y-4">
                    {groups.map(({ workspace, items }) => {
                        const allowance = workspaceAllowances[workspace.id];
                        const atLimit = allowance && !allowance.can_add;
                        const hasRoomInOtherWorkspaces = Object.values(workspaceAllowances).some(
                            (w) => w && w.can_add
                        );
                        
                        return (
                            <div key={workspace.id} className="card overflow-hidden">
                                <div className="list-head">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-[var(--color-text-main)]">
                                            {workspace.name}
                                        </p>
                                        <p className="text-xs text-[var(--color-text-muted)]">
                                            {items.length}{' '}
                                            {items.length === 1 ? 'business' : 'businesses'}
                                            {allowance?.limit != null &&
                                                ` of ${allowance.limit} allowed`}
                                        </p>
                                    </div>

                                    <NewBusiness
                                        workspaceId={workspace.id}
                                        workspaceName={workspace.name}
                                        allowance={allowance}
                                        onCreated={() => {
                                            void refresh();
                                            // Same reason as the delete path:
                                            // the count on the workspaces page
                                            // has just gone up.
                                            void queryClient.invalidateQueries({
                                                queryKey: ['workspaces'],
                                            });
                                        }}
                                    />
                                </div>

                                {/* Collapsible warning banner when at business limit for this workspace */}
                                {atLimit && (
                                    <div className="limit-banner">
                                        {/* Compact banner - always visible */}
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const newExpanded = new Set(expandedWarnings);
                                                if (newExpanded.has(workspace.id)) {
                                                    newExpanded.delete(workspace.id);
                                                } else {
                                                    newExpanded.add(workspace.id);
                                                }
                                                setExpandedWarnings(newExpanded);
                                            }}
                                            className="limit-banner-toggle"
                                        >
                                            <div className="flex items-center gap-2 min-w-0 flex-1">
                                                <Icon
                                                    name="info"
                                                    size={16}
                                                    weight="duotone"
                                                    className="flex-shrink-0"
                                                />
                                                <span className="truncate text-xs font-semibold">
                                                    Business limit reached for this workspace
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-1.5 flex-shrink-0">
                                                <span className="hidden text-[0.6875rem] font-medium opacity-75 sm:inline">
                                                    {expandedWarnings.has(workspace.id) ? 'Hide' : 'View'} options
                                                </span>
                                                <Icon
                                                    name={expandedWarnings.has(workspace.id) ? 'caret-up' : 'caret-down'}
                                                    size={14}
                                                    weight="duotone"
                                                />
                                            </div>
                                        </button>

                                        {/* Expanded content */}
                                        {expandedWarnings.has(workspace.id) && (
                                            <div className="limit-banner-body">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-[0.8125rem] leading-relaxed text-[var(--color-text-body)]">
                                                            {allowance?.limit === 1
                                                                ? 'This workspace can hold 1 business on your current plan.'
                                                                : `This workspace can hold ${allowance?.limit} businesses on your current plan.`}
                                                            {' '}
                                                            {hasRoomInOtherWorkspaces ? (
                                                                <>
                                                                    You can add businesses to other workspaces that have available slots, 
                                                                    or purchase additional business capacity specifically for this workspace.
                                                                </>
                                                            ) : (
                                                                <>
                                                                    To add more businesses, you can purchase additional business slots 
                                                                    for this workspace or upgrade your entire plan.
                                                                </>
                                                            )}
                                                        </p>
                                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                                            <Link
                                                                to={`/billing/add-business-slot?workspace=${workspace.id}`}
                                                                className="limit-banner-cta"
                                                            >
                                                                <Icon name="plus-circle" size={14} weight="duotone" />
                                                                Buy slot for this workspace
                                                            </Link>
                                                            <Link
                                                                to="/billing"
                                                                className="todo-action text-xs"
                                                            >
                                                                <Icon name="arrow-up-right" size={14} weight="duotone" className="mr-1.5" />
                                                                Upgrade plan
                                                            </Link>
                                                            {hasRoomInOtherWorkspaces && (
                                                                <Link
                                                                    to="/workspaces"
                                                                    className="home-see-all text-xs"
                                                                >
                                                                    <Icon name="squares-four" size={14} weight="duotone" />
                                                                    View other workspaces
                                                                </Link>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {items.length === 0 ? (
                                    <EmptyState
                                        compact
                                        icon="buildings"
                                        title="Nothing in this workspace yet"
                                        body="Add a business to start keeping books in it."
                                    />
                                ) : (
                                    <div className="divide-y divide-[var(--shell-border)]">
                                        {items.map((business) => (
                                            <BusinessRow
                                                key={business.id}
                                                business={business}
                                                current={business.id === tenant?.business?.id}
                                                busy={busyId === business.id}
                                                onOpen={() => {
                                                    setBusyId(business.id);
                                                    void open(business.workspace, business.id).finally(
                                                        () => setBusyId(null),
                                                    );
                                                }}
                                                onDelete={() =>
                                                    void remove(business.id, business.name)
                                                }
                                                onEdit={() => setEditingBusiness(business)}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
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

function BusinessRow({
    business,
    current,
    busy,
    onOpen,
    onDelete,
    onEdit,
}: {
    business: BusinessSummary;
    current: boolean;
    busy: boolean;
    onOpen: () => void;
    onDelete: () => void;
    onEdit: () => void;
}) {
    const { data: organiser } = useOrganiser();
    const key = organiserKey('business', business.id);
    const isFavourite = organiser?.favourites.includes(key) ?? false;

    return (
        <div className="biz-row">
            <BusinessBadge 
                business={business}
                size="lg"
                showFavorite={isFavourite}
            />

            <div className="min-w-0 flex-1">
                <p className="truncate font-semibold text-[var(--color-text-main)]">
                    {business.name}
                </p>
                <p className="flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                    <span>{business.currency}</span>
                    {current && <span className="biz-open-tag">Open</span>}
                </p>
            </div>

            <button type="button" disabled={busy} onClick={onOpen} className="biz-row-action">
                {busy ? 'Opening…' : 'Dashboard'}
            </button>

            <ItemMenu type="business" id={business.id} name={business.name} onDelete={onDelete} onEdit={onEdit} />
        </div>
    );
}
