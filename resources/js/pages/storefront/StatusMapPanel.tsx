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
        <div className="flex min-h-0 flex-1 flex-col gap-4">
            {/*
              The toolbar belongs to the table, above its headings and divided
              from them — the same arrangement as the drawer's head, and the
              same reason: pinned above the box instead, the table's own top
              border slid underneath it before any row moved, so the first thing
              scrolling did was take the frame away.
            */}
            <div
                className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-[var(--shell-radius)] border"
                style={{ borderColor: 'var(--shell-border)' }}
            >
                <div className="shrink-0 px-3 py-2.5">
                {/*
                  ── The filter left, the actions right ──────────────────────
                  
                  It was two rows: a toolbar, and a search box given the whole
                  width beneath it. A search box on a row of its own claims as
                  much of the screen as the table it filters, which is more
                  weight than a thing nobody touches until they need it.
                  
                  One row, and it reads left to right in the order the work
                  happens: find the status, then add or save. The same shape as
                  the field mapping above it, with the filter on the other end
                  because there is no entity switcher here to hold that corner.
                */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="relative w-72 max-w-full">
                        <Icon
                            name="magnifying-glass"
                            size={13}
                            className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 opacity-50"
                        />
                        <input
                            className="field w-full pl-8 text-sm"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Find a status"
                            aria-label="Filter the statuses"
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Statuses are added to this tool, never to the shop —
                            theirs are whatever their software sends. */}
                        {adding ? (
                            <>
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
                            </>
                        ) : (
                            <>
                                {/*
                                  The one thing with no home in the drawer's
                                  header: the statuses this shop sends that
                                  nothing here listens for.
                                */}
                                {catalogue && catalogue.unclaimed.length > 0 && (
                                    <span
                                        className="cursor-help px-1 text-[var(--color-text-muted)] opacity-60"
                                        title={`This shop also sends ${catalogue.unclaimed.join(', ')} — nothing happens here when it does.`}
                                        aria-label={`This shop also sends ${catalogue.unclaimed.join(', ')} — nothing happens here when it does.`}
                                    >
                                        <Icon name="info" size={13} weight="duotone" />
                                    </span>
                                )}

                                <button
                                    type="button"
                                    className="btn btn-secondary"
                                    onClick={() => setAdding(true)}
                                >
                                    <Icon name="plus" size={13} />
                                    Add a status
                                </button>
                            </>
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
                </div>


            {isLoading && (
                <div className="h-56 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />
            )}

            {/*
              ── The rows scroll, not the drawer ─────────────────────────

              Scrolling used to move the whole panel, carrying the column
              headings away and leaving somebody forty rows down looking at
              seven dropdowns with nothing to say which was which.

              This box scrolls instead. `overflow: auto` on both axes makes
              it the scrolling ancestor, which is what lets the headings
              stick to its top — the two are the same decision, not two.

              The height is whatever is left after the toolbar, taken from the
              drawer rather than worked out from the window. `100vh` was the
              first attempt and is wrong twice over: it measures the window
              rather than the panel, and the difference is every pixel of the
              drawer's head, its padding and this toolbar — a number that
              changes with all three.
            */}
                </div>

                <div
                    className="h-px shrink-0"
                    style={{ background: 'var(--shell-border)' }}
                    aria-hidden="true"
                />

                {catalogue && (
                <div className="min-h-0 flex-1 overflow-auto">
                    <table
                        className="table table-framed w-full min-w-[28rem] table-fixed !rounded-none !border-0"
                        /*
                         * `.table-framed` sets overflow:hidden to clip its rows
                         * inside its rounded corners, which makes the table its
                         * own scroll container — and a sticky heading then
                         * positions against a box that never scrolls. The
                         * corners are rounded by the wrapper instead.
                         */
                        style={{ overflow: 'visible' }}
                    >
                        <colgroup>
                            <col style={{ width: '45%' }} />
                            <col />
                        </colgroup>

                        {/*
                          Pinned to the top of the box that scrolls.

                          On the cells rather than the row: a sticky <thead> is
                          honoured by some engines and quietly ignored by others,
                          and the failure is silent — position computes as
                          sticky, top computes correctly, and the row scrolls away
                          regardless.
                        */}
                        <thead className="[&>tr>th]:sticky [&>tr>th]:top-0 [&>tr>th]:z-10 [&>tr>th]:bg-[var(--color-card-bg)]">
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
