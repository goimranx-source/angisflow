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
export function StatusMapPanel({
    connectionId,
    onSummary,
}: {
    connectionId: string;
    /** How much is matched, for the badge beside Sync in the drawer's header. */
    onSummary?: (summary: { mapped: number; unmapped: number }) => void;
}) {
    const queryClient = useQueryClient();

    const [rules, setRules] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const [newStatus, setNewStatus] = useState('');

    /*
     * The same filter the field mapping has, for the same reason.
     *
     * A shop with a plugin per courier can carry thirty statuses, and the one
     * being looked for is usually known by name. Matched against both halves,
     * so typing what the shop calls something finds it just as well as typing
     * what this tool calls it.
     */
    const [query, setQuery] = useState('');

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

    const visible = (catalogue?.ours ?? []).filter((ours) => {
        const needle = query.trim().toLowerCase();

        if (needle === '') return true;

        const theirWord = rules[ours.value] ?? '';
        const theirLabel = catalogue?.theirs.find((t) => t.value === theirWord)?.label ?? '';

        return [ours.label, ours.value, theirWord, theirLabel].some((piece) =>
            piece.toLowerCase().includes(needle),
        );
    });

    const mapped = Object.keys(rules).length;
    const unmapped = (catalogue?.ours.length ?? 0) - mapped;

    useEffect(() => {
        onSummary?.({ mapped, unmapped });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mapped, unmapped]);

    return (
        <div className="space-y-4">
            {/*
              The same toolbar as the field mapping, in the same place.

              Save used to sit at the bottom, past every row, which on a shop
              with thirty statuses meant scrolling to the end to keep a change
              made at the top. Two panels behind one tab strip should not
              disagree about where their controls live.
            */}
            <div
                className="sticky z-20 -mx-1 space-y-3 px-1 pb-3 pt-1"
                style={{
                    top: 'var(--store-tabs-height, 0px)',
                    background: 'var(--color-card-bg)',
                }}
                ref={(node) => {
                    if (!node) return;

                    node.parentElement?.style.setProperty(
                        '--toolbar-height',
                        `${Math.round(node.getBoundingClientRect().height)}px`,
                    );
                }}
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {/* Statuses are added to this tool, never to the shop —
                        theirs are whatever their software sends. */}
                    {adding ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                className="field min-w-[12rem]"
                                value={newStatus}
                                onChange={(event) => setNewStatus(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' && newStatus.trim() !== '') {
                                        event.preventDefault();
                                        addStatus.mutate();
                                    }

                                    if (event.key === 'Escape') setAdding(false);
                                }}
                                placeholder="Awaiting parts"
                                aria-label="Name the new status"
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
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => setAdding(false)}
                            >
                                Cancel
                            </button>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => setAdding(true)}
                            >
                                <Icon name="plus" size={13} />
                                Add a status
                            </button>

                            {/*
                              Beside the control, not beside the search.

                              A count to the right of a search box reads as a
                              result count for a search nobody typed. These
                              describe the mapping as a whole, so they sit with
                              the rest of the toolbar.
                            */}
                            <div className="flex items-center gap-3 text-xs text-[var(--color-text-muted)]">
                                {/*
                                  The counts themselves are in the drawer's
                                  header now, beside Sync. What stays is the one
                                  thing that has no home up there: the statuses
                                  this shop sends that nothing here listens for.
                                */}
                                {catalogue && catalogue.unclaimed.length > 0 && (
                                    <span
                                        className="cursor-help opacity-60"
                                        title={`This shop also sends ${catalogue.unclaimed.join(', ')} — nothing happens here when it does.`}
                                        aria-label={`This shop also sends ${catalogue.unclaimed.join(', ')} — nothing happens here when it does.`}
                                    >
                                        <Icon name="info" size={12} />
                                    </span>
                                )}
                            </div>
                        </div>
                    )}

                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => save.mutate()}
                        disabled={save.isPending}
                    >
                        {save.isPending ? 'Saving…' : 'Save'}
                    </button>
                </div>

                <div className="relative">
                    <Icon
                        name="magnifying-glass"
                        size={13}
                        className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 opacity-50"
                    />
                    <input
                        className="field w-full pl-8 text-sm"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Find a status — by its name here or on the shop"
                        aria-label="Filter the statuses"
                    />
                </div>
            </div>

            {isLoading && (
                <div className="h-56 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />
            )}

            {catalogue && (
                <div className="overflow-x-auto rounded-[var(--shell-radius)]">
                    <table className="table table-framed w-full min-w-[28rem] table-fixed">
                        <colgroup>
                            <col style={{ width: '45%' }} />
                            <col />
                        </colgroup>

                        <thead>
                            <tr>
                                <th>This tool&rsquo;s status</th>
                                <th>Is called this on your shop</th>
                            </tr>
                        </thead>

                        <tbody>
                            {visible.length === 0 && (
                                <tr>
                                    <td colSpan={2} className="text-center text-[var(--color-text-muted)]">
                                        No status matches &ldquo;{query}&rdquo;.
                                    </td>
                                </tr>
                            )}

                            {visible.map((ours) => (
                                <tr key={ours.value}>
                                    <td className="whitespace-nowrap">
                                        <Dot tone={ours.tone} />
                                        {ours.label}
                                    </td>

                                    <td>
                                        <select
                                            className="field w-full"
                                            value={rules[ours.value] ?? ''}
                                            onChange={(event) => choose(ours.value, event.target.value)}
                                            aria-label={`What this shop calls ${ours.label}`}
                                        >
                                            <option value="">— not matched</option>
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
