import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';

import { MediaDetail } from '@/components/media/MediaDetail';
import { MediaToolbar } from '@/components/media/MediaToolbar';
import { Icon } from '@/components/ui/Icon';
import { api, ApiError } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { MediaItem, MediaKind, MediaSort, MediaStats } from '@/types/settings';

type MediaPage = { data: MediaItem[]; meta: { next_cursor: string | null; has_more: boolean } };

/**
 * The library, and the picker over it — one component.
 *
 * The first version of Prism had these as two things that drifted apart: the
 * crop tool worked in one and not the other, and the upload path was fixed
 * twice. Here the Media tab renders this inline and every "choose an image"
 * button renders the same thing in a dialog, so there is one behaviour to get
 * right.
 *
 * ── Two modes, one component ──────────────────────────────────────────────
 *
 *   library   (no onPick) — the Media tab. Clicking a tile opens its details.
 *             Checkboxes appear on hover for bulk removal.
 *
 *   picker    (onPick set) — the dialog a "Choose an image" button opens.
 *             Clicking a tile picks it immediately, which is what somebody
 *             mid-form actually wants.
 *
 * ── Paged by cursor ──────────────────────────────────────────────────────────
 *
 * A library grows without limit. `useInfiniteQuery` asks for the next slice by
 * the cursor the server handed back, which is an index seek whatever page you
 * are on — and, unlike an offset, does not shift under you when somebody
 * uploads while you are scrolling.
 */
export function MediaPicker({
    imagesOnly = false,
    selectedId = null,
    onPick,
    onClose,
}: {
    imagesOnly?: boolean;
    selectedId?: string | null;
    onPick?: (item: MediaItem) => void;
    onClose?: () => void;
}) {
    const queryClient = useQueryClient();
    const isPicker = !!onPick;

    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [kind, setKind] = useState<MediaKind | null>(imagesOnly ? 'image' : null);
    const [sort, setSort] = useState<MediaSort>('newest');
    const [uploading, setUploading] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [detail, setDetail] = useState<MediaItem | null>(null);
    const dragDepth = useRef(0);
    const fileInput = useRef<HTMLInputElement>(null);

    // Typing shouldn't fire a request per keystroke. 300ms is long enough to
    // finish a word and short enough not to feel laggy.
    useEffect(() => {
        const id = window.setTimeout(() => setTerm(search.trim()), 300);

        return () => window.clearTimeout(id);
    }, [search]);

    const queryKey = ['media', { kind, term, sort }];

    const query = useInfiniteQuery({
        queryKey,
        initialPageParam: null as string | null,
        queryFn: ({ pageParam, signal }) =>
            api.get<MediaPage>('/media', {
                params: {
                    kind: kind ?? undefined,
                    search: term || undefined,
                    sort,
                    cursor: pageParam ?? undefined,
                },
                signal,
            }),
        getNextPageParam: (last) => last.meta.next_cursor,
    });

    // The byte total, cached separately from the page itself — a stat about
    // the whole library rather than about the slice currently on screen.
    const { data: stats } = useQuery({
        queryKey: ['media', 'stats'],
        queryFn: ({ signal }) => api.get<{ data: MediaStats }>('/media/stats', { signal }),
        staleTime: 10_000,
    });

    const items = query.data?.pages.flatMap((page) => page.data) ?? [];

    const invalidate = useCallback(
        () =>
            Promise.all([
                queryClient.invalidateQueries({ queryKey: ['media'] }),
            ]),
        [queryClient],
    );

    // ── Uploading ────────────────────────────────────────────────────────

    const upload = async (files: FileList | File[] | null) => {
        const list = files ? Array.from(files) : [];

        if (list.length === 0) {
            return;
        }

        setUploading(true);

        let uploaded = 0;
        let duplicates = 0;
        let failed = 0;

        for (const file of list) {
            try {
                const body = new FormData();
                body.append('file', file);

                // FormData rather than the shared client — a multipart body
                // must not be stringified, and the browser has to set its own
                // boundary header.
                const response = await fetch('/api/v1/media', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
                        ),
                    },
                    body,
                });

                const payload = (await response.json().catch(() => ({}))) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                    duplicate?: boolean;
                };

                if (!response.ok) {
                    throw new ApiError(response.status, payload.message ?? 'Upload failed.', payload.errors ?? {});
                }

                payload.duplicate ? duplicates++ : uploaded++;
            } catch (error) {
                failed++;
                toast.error(
                    error instanceof ApiError
                        ? `${file.name}: ${error.fieldError('file') ?? error.message}`
                        : `${file.name} could not be uploaded.`,
                );
            }
        }

        await invalidate();
        setUploading(false);

        if (fileInput.current) {
            fileInput.current.value = '';
        }

        if (uploaded > 0) {
            toast.success(uploaded === 1 ? 'Uploaded.' : `${uploaded} files uploaded.`);
        }

        if (duplicates > 0) {
            toast.info(
                duplicates === 1
                    ? 'Already in the library — nothing new was added.'
                    : `${duplicates} files were already in the library.`,
            );
        }

        // Failures already reported individually above.
        void failed;
    };

    // ── Drag and drop ───────────────────────────────────────────────────
    //
    // Counted with a depth rather than toggled on enter/leave: a child element
    // firing its own dragleave as the pointer crosses into it would otherwise
    // flicker the drop overlay off and straight back on.

    const onDragEnter = (event: React.DragEvent) => {
        event.preventDefault();

        if (event.dataTransfer.types.includes('Files')) {
            dragDepth.current++;
            setDragging(true);
        }
    };

    const onDragLeave = (event: React.DragEvent) => {
        event.preventDefault();
        dragDepth.current = Math.max(0, dragDepth.current - 1);

        if (dragDepth.current === 0) {
            setDragging(false);
        }
    };

    const onDrop = (event: React.DragEvent) => {
        event.preventDefault();
        dragDepth.current = 0;
        setDragging(false);
        void upload(event.dataTransfer.files);
    };

    // ── Selection ────────────────────────────────────────────────────────

    const toggleSelect = (id: string) => {
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const deleteSelected = async () => {
        const ids = [...selected];

        if (ids.length === 0) {
            return;
        }

        if (!window.confirm(`Remove ${ids.length} ${ids.length === 1 ? 'file' : 'files'} from the library? This cannot be undone.`)) {
            return;
        }

        try {
            const result = await api.delete<{ message: string; removed: number }>('/media', { ids });

            setSelected(new Set());
            await invalidate();
            toast.success(result.message);
        } catch {
            toast.error('Those could not be removed.');
        }
    };

    const removeOne = (id: string) => {
        setSelected((current) => {
            const next = new Set(current);
            next.delete(id);

            return next;
        });
        void invalidate();
    };

    return (
        <div
            className="relative flex h-full min-h-0 flex-col"
            onDragEnter={onDragEnter}
            onDragOver={(event) => event.preventDefault()}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
        >
            <div className="pb-3">
                <MediaToolbar
                    search={search}
                    onSearch={setSearch}
                    kind={kind}
                    onKind={setKind}
                    sort={sort}
                    onSort={setSort}
                    stats={stats?.data}
                    imagesOnly={imagesOnly}
                    uploading={uploading}
                    onUpload={() => fileInput.current?.click()}
                    selectedCount={selected.size}
                    onClearSelection={() => setSelected(new Set())}
                    onDeleteSelected={() => void deleteSelected()}
                />
            </div>

            <input
                ref={fileInput}
                type="file"
                multiple
                accept={imagesOnly ? 'image/*' : 'image/*,application/pdf'}
                className="hidden"
                onChange={(event) => void upload(event.target.files)}
            />

            <div className="flex min-h-0 flex-1 gap-4">
                <div className="min-h-0 min-w-0 flex-1 overflow-y-auto">
                    {query.isPending ? (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                            {Array.from({ length: 10 }, (_, i) => (
                                <div key={i} className="aspect-square animate-pulse rounded-xl bg-[var(--color-brand-subtle)]" />
                            ))}
                        </div>
                    ) : items.length === 0 ? (
                        <EmptyState term={term} onUpload={() => fileInput.current?.click()} />
                    ) : (
                        <>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                                {items.map((item) => (
                                    <MediaTile
                                        key={item.id}
                                        item={item}
                                        isPicker={isPicker}
                                        pickedId={selectedId}
                                        selected={selected.has(item.id)}
                                        detailOpen={detail?.id === item.id}
                                        onPick={onPick}
                                        onToggleSelect={() => toggleSelect(item.id)}
                                        onOpenDetail={() => setDetail(item)}
                                    />
                                ))}
                            </div>

                            {query.hasNextPage && (
                                <div className="pt-4 text-center">
                                    <button
                                        type="button"
                                        onClick={() => void query.fetchNextPage()}
                                        disabled={query.isFetchingNextPage}
                                        className="btn btn-secondary"
                                    >
                                        {query.isFetchingNextPage ? 'Loading…' : 'Load more'}
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>

                {/* The details panel, only in library mode. A picker dialog is
                    already a small, focused surface; splitting it further to
                    show details would leave neither half usable. */}
                {!isPicker && detail && (
                    <div className="w-72 flex-none border-l border-[var(--color-border-light)] pl-4">
                        <MediaDetail
                            item={detail}
                            onClose={() => setDetail(null)}
                            onUpdated={(updated) => {
                                setDetail(updated);
                                void invalidate();
                            }}
                            onDeleted={(id) => {
                                setDetail(null);
                                removeOne(id);
                            }}
                        />
                    </div>
                )}
            </div>

            {onClose && (
                <div className="flex justify-end border-t border-[var(--color-border-light)] pt-3">
                    <button type="button" onClick={onClose} className="btn btn-ghost">
                        Close
                    </button>
                </div>
            )}

            {/* The drop overlay. A picker dialog and the inline library both
                accept a drag from the desktop — uploading should not require
                first finding the upload button. */}
            {dragging && (
                <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center rounded-xl border-2 border-dashed border-[var(--color-brand)] bg-[var(--color-card-bg)]/90">
                    <div className="text-center">
                        <Icon name="upload-simple" size={32} className="mx-auto text-[var(--color-brand-active)]" />
                        <p className="mt-2 font-semibold text-[var(--color-text-main)]">Drop to upload</p>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Tiles ────────────────────────────────────────────────────────────────

function MediaTile({
    item,
    isPicker,
    pickedId,
    selected,
    detailOpen,
    onPick,
    onToggleSelect,
    onOpenDetail,
}: {
    item: MediaItem;
    isPicker: boolean;
    pickedId: string | null;
    selected: boolean;
    detailOpen: boolean;
    onPick?: (item: MediaItem) => void;
    onToggleSelect: () => void;
    onOpenDetail: () => void;
}) {
    const isThePicked = isPicker && item.id === pickedId;

    const click = () => {
        if (isPicker) {
            onPick?.(item);
        } else {
            onOpenDetail();
        }
    };

    return (
        <div
            className={cn(
                'group relative overflow-hidden rounded-xl border bg-[var(--color-card-bg)] transition-colors',
                isThePicked || detailOpen
                    ? 'border-[var(--color-brand)] ring-2 ring-[var(--color-brand)]/30'
                    : selected
                      ? 'border-[var(--color-brand-border)]'
                      : 'border-[var(--color-border-light)] hover:border-[var(--color-border-strong)]',
            )}
        >
            {/* The checkbox — library mode only. Kept out of the picker: a
                dialog you are choosing one image from has no use for bulk
                removal, and a checkbox sitting on every tile there would just
                be a control that does nothing. */}
            {!isPicker && (
                <button
                    type="button"
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggleSelect();
                    }}
                    className={cn(
                        'absolute top-1.5 left-1.5 z-10 grid size-5 place-items-center rounded-md border-2 transition-colors',
                        selected
                            ? 'border-[var(--color-brand)] bg-[var(--color-brand)] text-[var(--color-ink)] opacity-100'
                            : 'border-white bg-black/20 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100',
                    )}
                    aria-label={selected ? `Deselect ${item.name}` : `Select ${item.name}`}
                >
                    {selected && <Icon name="check" size={12} weight="bold" />}
                </button>
            )}

            <button type="button" onClick={click} className="block w-full cursor-pointer">
                <span className="grid aspect-square place-items-center bg-[var(--color-brand-subtle)] p-2">
                    {item.is_image ? (
                        <img
                            src={item.thumb_url}
                            alt={item.alt_text ?? ''}
                            // Off-screen tiles are not fetched until scrolled to,
                            // so a library of a thousand images does not cost a
                            // thousand requests to open — and each one that is
                            // fetched is the small derivative, not the original.
                            loading="lazy"
                            decoding="async"
                            className="max-h-full max-w-full object-contain"
                        />
                    ) : (
                        <span className="flex flex-col items-center gap-1">
                            <Icon name="file-magnifying-glass" size={26} className="text-[var(--color-text-subtle)]" />
                            <span className="text-[0.5625rem] font-bold tracking-wide text-[var(--color-text-subtle)]">
                                {item.extension}
                            </span>
                        </span>
                    )}
                </span>
            </button>

            <div className="px-2 py-1.5">
                <span className="block truncate text-[0.6875rem] text-[var(--color-text-muted)]" title={item.name}>
                    {item.name}
                </span>
                <span className="block text-[0.625rem] text-[var(--color-text-subtle)]">{item.readable_size}</span>
            </div>

            {isThePicked && (
                <span className="absolute top-1.5 right-1.5 grid size-5 place-items-center rounded-full bg-[var(--color-brand)] text-[var(--color-ink)]">
                    <Icon name="check" size={12} weight="bold" />
                </span>
            )}
        </div>
    );
}

function EmptyState({ term, onUpload }: { term: string; onUpload: () => void }) {
    return (
        <div className="rounded-xl border border-dashed border-[var(--color-border-light)] px-6 py-14 text-center">
            <Icon name="images" size={30} className="mx-auto text-[var(--color-text-subtle)]" />
            <p className="mt-3 text-sm font-medium text-[var(--color-text-main)]">
                {term ? 'Nothing matches that.' : 'Nothing here yet.'}
            </p>
            <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                {term
                    ? 'Try a different search, or clear it to see everything.'
                    : 'Upload once, and it can be used anywhere in the tool.'}
            </p>
            {!term && (
                <button type="button" onClick={onUpload} className="btn btn-secondary mt-4">
                    <Icon name="upload-simple" size={15} />
                    Upload a file
                </button>
            )}
        </div>
    );
}
