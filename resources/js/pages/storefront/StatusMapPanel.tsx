import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import type { StatusCatalogue } from '@/pages/settings/integrations/types';

/**
 * What each of this tool's statuses is called on this shop.
 *
 * Our statuses down the left, one per row; against each, a dropdown of the words
 * this shop actually uses. Somebody setting this up knows their own operation,
 * so they are finding the counterpart of something familiar rather than being
 * quizzed on a list of somebody else's status names.
 *
 * One answer serves both directions: read in, the map is inverted; pushed out,
 * it is used directly.
 */
export function StatusMapPanel({ connectionId }: { connectionId: string }) {
    const queryClient = useQueryClient();

    const [rules, setRules] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const [newStatus, setNewStatus] = useState('');

    const { data, isLoading, refetch } = useQuery({
        queryKey: ['integration', connectionId, 'status-map'],
        queryFn: () => api.get<{ data: StatusCatalogue }>(`/settings/integrations/${connectionId}/status-map`),
    });

    const catalogue = data?.data;

    useEffect(() => {
        if (!catalogue) return;

        const seed: Record<string, string> = {};
        for (const status of catalogue.ours) {
            if (status.mapped_to) seed[status.value] = status.mapped_to;
        }
        setRules(seed);
    }, [catalogue]);

    const save = useMutation({
        mutationFn: () => api.put(`/settings/integrations/${connectionId}/status-map`, { rules }),
        onSuccess: () => {
            toast.success('Statuses saved.');
            void queryClient.invalidateQueries({ queryKey: ['integration', connectionId] });
        },
        onError: (error: Error) => toast.error(error.message || 'Those could not be saved.'),
    });

    const addStatus = useMutation({
        mutationFn: () => api.post(`/settings/integrations/${connectionId}/statuses`, { label: newStatus }),
        onSuccess: () => {
            setNewStatus('');
            setAdding(false);
            void refetch();
        },
        onError: (error: Error) => toast.error(error.message || 'That status could not be added.'),
    });

    const choose = (ourStatus: string, theirWord: string) =>
        setRules((current) => {
            // Blank clears the row rather than storing an empty mapping.
            if (theirWord === '') {
                const { [ourStatus]: _cleared, ...rest } = current;

                return rest;
            }

            return { ...current, [ourStatus]: theirWord };
        });

    return (
        <div className="space-y-4">
            {catalogue && catalogue.unclaimed.length > 0 && (
                <p className="text-xs text-[var(--color-warning)]">
                    <Icon name="warning" size={12} className="mr-1 inline" />
                    This shop also sends {catalogue.unclaimed.join(', ')} — nothing happens when it does.
                </p>
            )}

            {isLoading && <div className="h-56 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />}

            {catalogue && (
                <div className="overflow-x-auto rounded-[var(--shell-radius)]">
                    <table className="table table-framed">
                        <thead>
                            <tr>
                                <th>This tool</th>
                                <th>Is called this on the shop</th>
                            </tr>
                        </thead>

                        <tbody>
                            {catalogue.ours.map((ours) => (
                                <tr key={ours.value}>
                                    <td className="whitespace-nowrap">
                                        <Dot tone={ours.tone} />
                                        {ours.label}
                                    </td>

                                    <td>
                                        <select
                                            className="field w-full min-w-[13rem]"
                                            value={rules[ours.value] ?? ''}
                                            onChange={(event) => choose(ours.value, event.target.value)}
                                            aria-label={`What this shop calls ${ours.label}`}
                                        >
                                            <option value="">—</option>
                                            {catalogue.theirs.map((theirs) => (
                                                <option key={theirs.value} value={theirs.value}>
                                                    {/*
                                                      The shop's own wording, with
                                                      the raw value beside it: one is
                                                      what somebody recognises, the
                                                      other is what actually arrives
                                                      on an order, and a mapping
                                                      screen is where both matter.
                                                    */}
                                                    {theirs.label}
                                                    {theirs.label !== theirs.value && ` (${theirs.value})`}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2">
                {/* Statuses are added to this tool, never to the shop — theirs
                    are whatever their software sends. */}
                {adding ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            className="field min-w-[12rem]"
                            value={newStatus}
                            onChange={(event) => setNewStatus(event.target.value)}
                            placeholder="Awaiting parts"
                            autoFocus
                        />
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => addStatus.mutate()}
                            disabled={newStatus.trim() === '' || addStatus.isPending}
                        >
                            Add
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setAdding(false)}>
                            Cancel
                        </button>
                    </div>
                ) : (
                    <button
                        type="button"
                        className="text-sm text-[var(--color-brand)]"
                        onClick={() => setAdding(true)}
                    >
                        ＋ Add a status to this tool
                    </button>
                )}

                <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => save.mutate()}
                    disabled={save.isPending}
                >
                    Save
                </button>
            </div>
        </div>
    );
}

function Dot({ tone }: { tone: string }) {
    const colour =
        tone === 'success'
            ? 'var(--color-success)'
            : tone === 'danger'
              ? 'var(--color-danger)'
              : tone === 'warning'
                ? 'var(--color-warning)'
                : tone === 'info' || tone === 'brand'
                  ? 'var(--color-brand)'
                  : 'var(--color-text-subtle)';

    return <span className="mr-2 inline-block h-1.5 w-1.5 rounded-full" style={{ background: colour }} />;
}
