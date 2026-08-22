import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState, useRef, useEffect } from 'react';
import { useSearchParams } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { ConnectShopModal } from './integrations/ConnectShopModal';
import type { Connection, Kind, Platform } from './integrations/types';

// Inline sync button with dropdown for table rows
function IntegrationSyncButton({ 
    isOpen, 
    onToggle,
    onSync, 
    isPending,
    isDisabled,
}: { 
    isOpen: boolean; 
    onToggle: () => void;
    onSync: (full: boolean) => void;
    isPending: boolean;
    isDisabled: boolean;
}) {
    const buttonRef = useRef<HTMLDivElement>(null);
    
    const buttonRect = buttonRef.current?.getBoundingClientRect();
    const dropdownHeight = 120;
    const spaceBelow = buttonRect ? window.innerHeight - buttonRect.bottom : 999;
    const spaceAbove = buttonRect?.top ?? 0;
    const showAbove = isOpen && spaceBelow < dropdownHeight && spaceAbove > spaceBelow;

    return (
        <div ref={buttonRef} className="relative inline-flex">
            <div className="flex gap-0">
                <button
                    type="button"
                    className="btn btn-secondary rounded-r-none border-r-0"
                    onClick={() => onSync(false)}
                    disabled={isPending || isDisabled}
                >
                    {isPending ? (
                        <Icon name="spinner" size={13} className="animate-spin" />
                    ) : (
                        <Icon name="arrows-clockwise" size={13} />
                    )}
                    Sync
                </button>
                <button
                    type="button"
                    className="btn btn-secondary rounded-l-none px-2"
                    onClick={onToggle}
                    disabled={isPending || isDisabled}
                >
                    <Icon name="caret-down" size={10} />
                </button>
            </div>
            
            {isOpen && buttonRect && (
                <>
                    <div className="fixed inset-0 z-10" onClick={onToggle} />
                    <div 
                        className="fixed z-20 w-48 overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] shadow-lg"
                        style={{
                            top: showAbove ? undefined : `${buttonRect.bottom + 4}px`,
                            bottom: showAbove ? `${window.innerHeight - buttonRect.top + 4}px` : undefined,
                            left: `${buttonRect.right - 192}px`,
                        }}
                    >
                        <button
                            type="button"
                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-[var(--shell-hover)]"
                            onClick={() => {
                                onToggle();
                                onSync(false);
                            }}
                        >
                            <Icon name="arrows-clockwise" size={14} />
                            <div>
                                <div className="font-medium text-[var(--color-text-main)]">Incremental</div>
                                <div className="text-xs text-[var(--color-text-muted)]">New/modified</div>
                            </div>
                        </button>
                        <button
                            type="button"
                            className="flex w-full items-center gap-2 border-t border-[var(--shell-border)] px-3 py-2 text-left text-sm hover:bg-[var(--shell-hover)]"
                            onClick={() => {
                                onToggle();
                                onSync(true);
                            }}
                        >
                            <Icon name="arrow-clockwise" size={14} />
                            <div>
                                <div className="font-medium text-[var(--color-text-main)]">Full Sync</div>
                                <div className="text-xs text-[var(--color-text-muted)]">All records</div>
                            </div>
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * The shops this business sells through.
 *
 * A table, matching the currency panel: one row per connection, the state of it
 * on the row, actions on the right. No explanatory paragraphs — somebody who has
 * opened this screen already knows what an integration is, and prose above a
 * table is prose nobody reads twice.
 */
export default function IntegrationsPanel() {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();

    const [connecting, setConnecting] = useState(false);
    const [editing, setEditing] = useState<Connection | null>(null);
    const [syncing, setSyncing] = useState<string | null>(null);
    const [syncMenuOpen, setSyncMenuOpen] = useState<string | null>(null);

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['settings', 'integrations'],
        queryFn: () =>
            api.get<{ data: Connection[]; stores: Array<{ id: string; name: string }> }>(
                '/settings/integrations',
            ),
    });

    // Debug logging
    if (isError && error) {
        console.error('Integrations fetch error:', error);
    }

    const { data: catalogue } = useQuery({
        queryKey: ['settings', 'integrations', 'catalogue'],
        queryFn: () => api.get<{ data: Platform[]; kinds: Kind[] }>('/settings/integrations/catalogue'),
        // The set of drivers only changes when the application is deployed.
        staleTime: Infinity,
    });

    const connections = data?.data ?? [];
    const stores = data?.stores ?? [];
    const platforms = useMemo(() => catalogue?.data ?? [], [catalogue]);
    const kinds = useMemo(() => catalogue?.kinds ?? [], [catalogue]);

    // Courier configuration links arrive here with the integration public id
    // in `edit`. Wait for the list request, open that exact connection, then
    // consume the parameter so closing the modal does not reopen it.
    useEffect(() => {
        const editId = searchParams.get('edit');

        if (!editId || isLoading || isError) {
            return;
        }

        const connection = connections.find((item) => item.id === editId);

        if (connection === undefined) {
            return;
        }

        setEditing(connection);

        const nextParams = new URLSearchParams(searchParams);
        nextParams.delete('edit');
        setSearchParams(nextParams, { replace: true });
    }, [connections, isError, isLoading, searchParams, setSearchParams]);

    const sync = useMutation({
        mutationFn: (params: { id: string; full?: boolean }) =>
            api.post<{ data: SyncReport }>(`/settings/integrations/${params.id}/sync`, { full: params.full ?? false }),
        onMutate: (params) => setSyncing(params.id),
        onSettled: () => setSyncing(null),
        onSuccess: (result, variables) => {
            const written = result.data?.written ?? 0;
            const skipped = result.data?.skipped ?? 0;
            const syncType = variables?.full ? 'Full sync' : 'Incremental sync';
            const brought = `${syncType}: Brought in ${written} record${written === 1 ? '' : 's'}`;

            // A run that wrote three hundred and skipped four is not a failure,
            // but it is not a clean success either.
            if (skipped > 0) {
                toast.warning(`${brought} — ${skipped} could not be imported.`);
            } else {
                toast.success(`${brought}.`);
            }

            void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] });
        },
        // The driver's own sentence — "Nothing answered at …", "refused these
        // credentials" — rather than a bare status code.
        onError: (error: Error) => toast.error(error.message || 'That sync did not finish.'),
    });

    const remove = useMutation({
        mutationFn: (id: string) => api.delete(`/settings/integrations/${id}`),
        onSuccess: () => {
            toast.success('Connection removed.');
            void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] });
        },
    });

    const pause = useMutation({
        mutationFn: ({ id, active }: { id: string; active: boolean }) =>
            api.patch(`/settings/integrations/${id}`, { is_active: active, status: active ? 'active' : 'paused' }),
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] }),
    });

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-[family-name:var(--font-heading)] text-base font-bold">Integrations</h2>

                <button type="button" className="btn btn-primary" onClick={() => setConnecting(true)}>
                    <Icon name="plus" size={14} />
                    Add integration
                </button>
            </div>

            {isLoading && (
                <div className="h-28 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />
            )}

            {isError && (
                <div className="card px-6 py-8 text-center text-sm">
                    <p className="text-[var(--color-text-body)]">These connections could not be loaded.</p>
                    <button type="button" className="btn btn-secondary mt-3" onClick={() => void refetch()}>
                        Try again
                    </button>
                </div>
            )}

            {!isLoading && !isError && connections.length === 0 && (
                <div className="card px-6 py-10 text-center">
                    <Icon name="plug" size={26} className="mx-auto text-[var(--color-text-subtle)]" />
                    <p className="mt-3 text-sm text-[var(--color-text-body)]">
                        Nothing connected yet.
                    </p>
                    <button type="button" className="btn btn-primary mt-4" onClick={() => setConnecting(true)}>
                        Add integration
                    </button>
                </div>
            )}

            {/*
              Every column gets the room it needs and the table scrolls sideways
              rather than squeezing. Cramming six columns and five buttons into a
              narrow panel wraps every header onto two lines and stacks the
              actions, which reads as broken rather than as compact.
            */}
            {connections.length > 0 && (
                <div className="overflow-x-auto rounded-[var(--shell-radius)]">
                    <table className="table table-framed min-w-[60rem]">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Kind</th>
                                <th>Platform</th>
                                <th>Connected to</th>
                                <th>Last sync</th>
                                <th>State</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            {connections.map((connection) => (
                                <Row
                                    key={connection.id}
                                    connection={connection}
                                    syncing={syncing === connection.id}
                                    syncMenuOpen={syncMenuOpen === connection.id}
                                    onSyncMenuToggle={() => setSyncMenuOpen(syncMenuOpen === connection.id ? null : connection.id)}
                                    onSync={(full) => sync.mutate({ id: connection.id, full })}
                                    onEdit={() => setEditing(connection)}
                                    onPause={() =>
                                        pause.mutate({ id: connection.id, active: !connection.is_active })
                                    }
                                    onRemove={() => remove.mutate(connection.id)}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(connecting || editing) && (
                <ConnectShopModal
                    platforms={platforms}
                    kinds={kinds}
                    stores={stores}
                    connection={editing}
                    onClose={() => {
                        setConnecting(false);
                        setEditing(null);
                    }}
                    onSaved={() => {
                        setConnecting(false);
                        setEditing(null);
                        void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] });
                    }}
                />
            )}

        </div>
    );
}

type SyncReport = { written?: number; skipped?: number };

/** Matches PlatformRegistry::KIND_LABELS. */
const KIND_LABELS: Record<string, string> = {
    store: 'Online store',
    courier: 'Courier',
    other: 'Other',
};

function Row({
    connection,
    syncing,
    syncMenuOpen,
    onSyncMenuToggle,
    onSync,
    onEdit,
    onPause,
    onRemove,
}: {
    connection: Connection;
    syncing: boolean;
    syncMenuOpen: boolean;
    onSyncMenuToggle: () => void;
    onSync: (full: boolean) => void;
    onEdit: () => void;
    onPause: () => void;
    onRemove: () => void;
}) {
    const failing = connection.status === 'error';
    const paused = !connection.is_active || connection.status === 'paused';

    return (
        <>
            <tr>
                <td className="min-w-[12rem]">
                    <span className="block font-medium whitespace-nowrap" title={connection.name}>
                        {connection.name}
                    </span>

                    {/* The reason it is failing, on the row it belongs to. */}
                    {failing && connection.last_error_message && (
                        <span className="mt-1 block text-xs text-[var(--color-danger)]">
                            {connection.last_error_message}
                        </span>
                    )}

                </td>

                <td className="whitespace-nowrap text-[var(--color-text-body)]">{KIND_LABELS[connection.kind] ?? connection.kind}</td>

                <td className="whitespace-nowrap text-[var(--color-text-body)]">{connection.platform}</td>

                <td className="whitespace-nowrap">
                    {connection.store ? (
                        <span className="text-[var(--color-text-body)]">{connection.store.name}</span>
                    ) : connection.kind === 'courier' ? (
                        <span className="text-[var(--color-text-body)]">Courier account</span>
                    ) : connection.kind === 'other' ? (
                        <span className="text-[var(--color-text-body)]">External service</span>
                    ) : (
                        // Its orders would import as counter sales — worth a
                        // click, not a blank cell.
                        <button type="button" onClick={onEdit} className="text-xs text-[var(--color-warning)]">
                            No shop set
                        </button>
                    )}
                </td>

                <td className="whitespace-nowrap text-[var(--color-text-muted)]">
                    {connection.last_success_at
                        ? new Date(connection.last_success_at).toLocaleDateString()
                        : 'Never'}
                </td>

                <td className="whitespace-nowrap">
                    <State failing={failing} paused={paused} />

                    {connection.unmapped_statuses > 0 && (
                        // Mapping itself lives on the storefront, alongside the
                        // product and order field maps. This is only the count.
                        <span className="mt-1 block text-xs text-[var(--color-warning)]">
                            {connection.unmapped_statuses} unmapped
                        </span>
                    )}
                </td>

                <td>
                    {/* nowrap, not wrap: five buttons folding onto two lines
                        makes every row a different height. The table scrolls. */}
                    <div className="flex items-center justify-end gap-1.5 whitespace-nowrap">
                        {connection.can_pull && (
                            <IntegrationSyncButton
                                isOpen={syncMenuOpen}
                                onToggle={onSyncMenuToggle}
                                onSync={onSync}
                                isPending={syncing}
                                isDisabled={paused}
                            />
                        )}
                        <button type="button" className="btn btn-secondary" onClick={onEdit}>
                            Edit
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={onPause}>
                            {paused ? 'Resume' : 'Pause'}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={onRemove} aria-label="Remove">
                            <Icon name="trash" size={13} />
                        </button>
                    </div>
                </td>
            </tr>

        </>
    );
}

function State({ failing, paused }: { failing: boolean; paused: boolean }) {
    const [label, colour] = failing
        ? ['Not working', 'var(--color-danger)']
        : paused
          ? ['Paused', 'var(--color-text-subtle)']
          : ['Working', 'var(--color-success)'];

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-xs" style={{ color: colour }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: colour }} />
            {label}
        </span>
    );
}
