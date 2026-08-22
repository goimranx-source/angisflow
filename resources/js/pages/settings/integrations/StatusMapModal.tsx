import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import type { Connection, StatusCatalogue } from './types';

/**
 * What each of our statuses is called on this shop.
 *
 * ── Which way round it reads ─────────────────────────────────────────────────
 *
 * Our statuses down the left, one per row. Against each, a dropdown of the words
 * that shop uses. Somebody setting this up knows their own operation, so they are
 * finding the counterpart of something familiar — not being quizzed on a list of
 * somebody else's status names.
 *
 * The choices come from the shop: what this connection has actually been seen to
 * send, plus what its platform documents. Nothing is typed and nothing invented.
 */
export function StatusMapModal({ connection, onClose }: { connection: Connection; onClose: () => void }) {
    const queryClient = useQueryClient();

    const [rules, setRules] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const [newStatus, setNewStatus] = useState('');

    const { data, isLoading, refetch } = useQuery({
        queryKey: ['settings', 'integrations', connection.id, 'status-map'],
        queryFn: () => api.get<{ data: StatusCatalogue }>(`/settings/integrations/${connection.id}/status-map`),
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
        mutationFn: () => api.put(`/settings/integrations/${connection.id}/status-map`, { rules }),
        onSuccess: () => {
            toast.success('Statuses saved.');
            void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] });
            onClose();
        },
        onError: (error: Error) => toast.error(error.message || 'Those could not be saved.'),
    });

    const addStatus = useMutation({
        mutationFn: () => api.post(`/settings/integrations/${connection.id}/statuses`, { label: newStatus }),
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
        <Modal open onClose={onClose} title={`${connection.name} — statuses`} size="lg">
            {isLoading && <div className="h-56 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />}

            {catalogue && (
                <>
                    {/*
                      Words this shop sends that nothing claims. The one thing on
                      this screen that needs acting on, so it leads.
                    */}
                    {catalogue.unclaimed.length > 0 && (
                        <div className="mb-4 rounded-[var(--shell-radius)] border border-[var(--color-warning)] px-3 py-2 text-sm text-[var(--color-warning)]">
                            <Icon name="warning" size={13} className="mr-1.5 inline" />
                            This shop also sends {catalogue.unclaimed.join(', ')} — pick one below to give it a
                            meaning.
                        </div>
                    )}

                    <div className="overflow-x-auto">
                        <table className="table-framed w-full text-sm">
                            <thead>
                                <tr className="bg-[var(--shell-muted)] text-left">
                                    <th className="px-3 py-2 font-medium">This tool</th>
                                    <th className="px-3 py-2 font-medium">Is called this on the shop</th>
                                </tr>
                            </thead>

                            <tbody>
                                {catalogue.ours.map((ours) => (
                                    <tr key={ours.value} className="border-t border-[var(--shell-border)]">
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <StatusDot tone={ours.tone} />
                                            {ours.label}
                                        </td>

                                        <td className="px-3 py-2">
                                            <select
                                                className="field w-full min-w-[13rem]"
                                                value={rules[ours.value] ?? ''}
                                                onChange={(event) => choose(ours.value, event.target.value)}
                                                aria-label={`What this shop calls ${ours.label}`}
                                            >
                                                <option value="">—</option>
                                                {catalogue.theirs.map((theirs) => (
                                                    <option key={theirs.value} value={theirs.value}>
                                                        {theirs.value}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/*
                      Statuses can be added to this tool, never to the shop —
                      theirs are whatever their software sends.
                    */}
                    <div className="mt-3">
                        {adding ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <input
                                    className="field flex-1 min-w-[12rem]"
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
                    </div>
                </>
            )}

            <div className="mt-6 flex justify-end gap-2">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    Cancel
                </button>
                <button type="button" className="btn btn-primary" onClick={() => save.mutate()} disabled={save.isPending}>
                    Save
                </button>
            </div>
        </Modal>
    );
}

function StatusDot({ tone }: { tone: string }) {
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
