import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

export type Tag = { id: string; name: string; colour: string | null };

export type ItemType = 'workspace' | 'business';

type OrganiserPayload = {
    data: Tag[];
    /** Tag ids per thing, keyed "type:publicId". */
    assigned: Record<string, string[]>;
    favourites: string[];
};

const ORGANISER_KEY = ['organiser'] as const;

/**
 * Tags and favourites, fetched once for the whole account.
 *
 * ── Why one request rather than per row ──────────────────────────────────────
 *
 * Both are tiny — a handful of tags, a handful of stars — and every row of
 * every list needs them. Asking per row would be thirty requests to draw one
 * screen. Asking once and looking up locally is one.
 */
export function useOrganiser() {
    return useQuery({
        queryKey: ORGANISER_KEY,
        queryFn: ({ signal }) => api.get<OrganiserPayload>('/tags', { signal }),
        staleTime: 60_000,
    });
}

/** The key a thing is stored under, on both sides. */
export function organiserKey(type: ItemType, publicId: string): string {
    return `${type}:${publicId}`;
}

/**
 * Changing tags and favourites, applied to the screen before the server has
 * been asked.
 *
 * ── Why these do not invalidate ──────────────────────────────────────────────
 *
 * Every one of them used to finish with invalidateQueries, which is two
 * sequential round trips for one tick of a checkbox: the POST goes, and only
 * once it lands does the refetch of /tags start — so the box a finger is
 * still resting on stays unticked for both of them. On anything slower than
 * localhost that reads as the click not having registered, and the usual
 * next move is to click it again, which un-does it.
 *
 * There is nothing to refetch *for*, either: /tags/{id}/assign already
 * returns the whole new assignment map, and /favourites the whole new list,
 * so the answer is in the reply to the request that was already made. These
 * write that reply straight into the cache instead — one round trip rather
 * than two.
 *
 * And the screen does not wait even for that one. onMutate applies the change
 * locally first, so a tick is immediate; the reply then overwrites it with
 * the server's own version, which is normally identical and occasionally
 * corrects it. A failure rolls back to exactly what was there before, which
 * is what the `previous` snapshot is for — and cancelQueries is what stops an
 * in-flight background refetch from landing on top of the optimistic write
 * and undoing it a moment later.
 */
export function useOrganiserActions() {
    const queryClient = useQueryClient();

    const refresh = () => void queryClient.invalidateQueries({ queryKey: ORGANISER_KEY });

    /** Snapshot the cache and stop anything in flight from overwriting it. */
    const beginOptimistic = async (update: (current: OrganiserPayload) => OrganiserPayload) => {
        await queryClient.cancelQueries({ queryKey: ORGANISER_KEY });

        const previous = queryClient.getQueryData<OrganiserPayload>(ORGANISER_KEY);

        if (previous) {
            queryClient.setQueryData<OrganiserPayload>(ORGANISER_KEY, update(previous));
        }

        return { previous };
    };

    /** Put back exactly what was there before the optimistic write. */
    const rollback = (context: { previous?: OrganiserPayload } | undefined) => {
        if (context?.previous) {
            queryClient.setQueryData(ORGANISER_KEY, context.previous);
        }
    };

    /** Merge an authoritative slice of the payload in, leaving the rest alone. */
    const merge = (patch: Partial<OrganiserPayload>) => {
        queryClient.setQueryData<OrganiserPayload>(ORGANISER_KEY, (current) =>
            current ? { ...current, ...patch } : current,
        );
    };

    const setFavourite = useMutation({
        mutationFn: (input: { type: ItemType; target: string; on: boolean }) =>
            api.post<{ favourites: string[] }>('/favourites', input),
        onMutate: (input) => {
            const key = organiserKey(input.type, input.target);

            return beginOptimistic((current) => ({
                ...current,
                favourites: input.on
                    ? [...new Set([...current.favourites, key])]
                    : current.favourites.filter((value) => value !== key),
            }));
        },
        onSuccess: (result) => merge({ favourites: result.favourites }),
        onError: (_error, _input, context) => {
            rollback(context);
            toast.error('That could not be saved.');
        },
    });

    const assignTag = useMutation({
        mutationFn: (input: { tag: string; type: ItemType; target: string; on: boolean }) =>
            api.post<{ assigned: Record<string, string[]> }>(`/tags/${input.tag}/assign`, {
                type: input.type,
                target: input.target,
                on: input.on,
            }),
        onMutate: (input) => {
            const key = organiserKey(input.type, input.target);

            return beginOptimistic((current) => {
                const held = current.assigned[key] ?? [];

                return {
                    ...current,
                    assigned: {
                        ...current.assigned,
                        [key]: input.on
                            ? [...new Set([...held, input.tag])]
                            : held.filter((id) => id !== input.tag),
                    },
                };
            });
        },
        onSuccess: (result) => merge({ assigned: result.assigned }),
        onError: (_error, _input, context) => {
            rollback(context);
            toast.error('That could not be saved.');
        },
    });

    /**
     * No optimistic row for this one. A tag has no id until the server gives
     * it one, and a placeholder id would be a real problem rather than a
     * cosmetic one — assigning to it while it is still pending would send
     * that made-up id to /tags/{id}/assign. Writing the reply into the cache
     * is still half the wait it was.
     */
    const createTag = useMutation({
        mutationFn: (name: string) => api.post<{ data: Tag }>('/tags', { name }),
        onSuccess: (result) => {
            queryClient.setQueryData<OrganiserPayload>(ORGANISER_KEY, (current) =>
                current
                    ? {
                          ...current,
                          data: [...current.data, result.data].sort((a, b) =>
                              a.name.localeCompare(b.name),
                          ),
                      }
                    : current,
            );
        },
    });

    const renameTag = useMutation({
        mutationFn: (input: { id: string; name: string }) =>
            api.patch<{ data: Tag }>(`/tags/${input.id}`, { name: input.name }),
        onMutate: (input) =>
            beginOptimistic((current) => ({
                ...current,
                data: current.data
                    .map((tag) => (tag.id === input.id ? { ...tag, name: input.name } : tag))
                    .sort((a, b) => a.name.localeCompare(b.name)),
            })),
        onSuccess: (result) => {
            queryClient.setQueryData<OrganiserPayload>(ORGANISER_KEY, (current) =>
                current
                    ? {
                          ...current,
                          data: current.data
                              .map((tag) => (tag.id === result.data.id ? result.data : tag))
                              .sort((a, b) => a.name.localeCompare(b.name)),
                      }
                    : current,
            );
        },
        onError: (_error, _input, context) => {
            rollback(context);
            toast.error('That tag could not be renamed.');
        },
    });

    const deleteTag = useMutation({
        mutationFn: (id: string) => api.delete(`/tags/${id}`),
        onMutate: (id) =>
            beginOptimistic((current) => ({
                ...current,
                data: current.data.filter((tag) => tag.id !== id),
                // It has to come off everything it was on as well, or rows
                // keep rendering a tag that no longer exists.
                assigned: Object.fromEntries(
                    Object.entries(current.assigned).map(([key, ids]) => [
                        key,
                        ids.filter((value) => value !== id),
                    ]),
                ),
            })),
        onError: (_error, _input, context) => {
            rollback(context);
            toast.error('That tag could not be removed.');
        },
    });

    return { setFavourite, assignTag, createTag, renameTag, deleteTag, refresh };
}
