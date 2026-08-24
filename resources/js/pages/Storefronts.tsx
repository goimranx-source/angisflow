import { useState, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';


import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    StatusBadge,
    KPICard,
    KPICardSkeleton,
} from '@/components/modules';
import { DateRangePicker, type DateRange } from '@/components/ui/DateRangePicker';
import { confirm } from '@/components/ui/Confirm';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { FieldMapPanel } from '@/pages/storefront/FieldMapPanel';
import { StatusMapPanel } from '@/pages/storefront/StatusMapPanel';
import { useMoney } from '@/hooks/useMoney';
import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useFlyoutPosition } from '@/hooks/useFlyoutPosition';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';


/**
 * A count, shortened once it stops being readable in full.
 *
 * Money has `both()` for this; a plain tally had nothing, so twelve thousand
 * products rendered as "12,000" and a hundred thousand pushed the card wider
 * than its neighbours. Below a thousand there is nothing to gain — "412" is
 * shorter than "0.4K" and says more.
 */
function compactCount(value: number): string {
    if (Math.abs(value) < 1000) {
        return value.toLocaleString();
    }

    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

/**
 * A date as the endpoint wants it, in the reader's own day.
 *
 * `toISOString` would be shorter and wrong either side of midnight: it converts
 * to UTC first, so a range picked on the 1st in Dhaka is sent as the 31st and
 * the figures come back for a month nobody chose.
 */
function toIsoDay(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * A row's actions, behind one mark.
 *
 * ── Why a menu rather than the buttons themselves ────────────────────────────
 *
 * The column held a split sync button — a control wide enough to read, on every
 * row, for something done occasionally. It set the column's width for the sake
 * of its widest row, and put a thing that changes a shop permanently under the
 * cursor of somebody scanning a list.
 *
 * One mark opens the list, and the list uses words. An icon-only menu trades a
 * label for a guess, and these are not guessable: two kinds of sync that differ
 * in what they fetch cannot be told apart by an arrow.
 *
 * ── Why it is drawn where it is ──────────────────────────────────────────────
 *
 * In a portal at the coordinates of its own button, because the table scrolls
 * inside a box with its own overflow — a menu positioned inside the row is
 * clipped by the first ancestor that scrolls, which is how a menu on the last
 * visible row ends up half a menu.
 */
function RowActions({
    store,
    open,
    onToggle,
    onOpen,
    onSync,
    onEdit,
    onDelete,
    isPending,
}: {
    store: Storefront;
    open: boolean;
    onToggle: () => void;
    onOpen: () => void;
    onSync: (full: boolean) => void;
    onEdit: () => void;
    onDelete: () => void;
    isPending: boolean;
}) {
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    /*
     * Measured against the window rather than dropped below the button.
     *
     * The last row of a table is close enough to the bottom that a menu opened
     * from it went off the screen -- and the last row is exactly where somebody
     * is when they reach for the delete on the thing they just added.
     */
    const at = useFlyoutPosition({ open, trigger: buttonRef, panel: menuRef });

    const item =
        'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition';

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                onClick={(event) => {
                    // The name opens the shop; this must not do both.
                    event.stopPropagation();
                    onToggle();
                }}
                /*
                  Boxed, because a bare glyph is not obviously a button.

                  Three dots on their own read as a decoration or a truncation
                  mark until somebody happens to hover them. An outline says
                  "press this" without a label, which is the whole job of an
                  icon-only control.
                */
                className="rounded-[var(--shell-radius-sm)] border p-1.5 text-[var(--color-text-muted)] transition hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                style={{ borderColor: 'var(--shell-border)' }}
                aria-label={`Actions for ${store.name}`}
                aria-haspopup="menu"
                aria-expanded={open}
            >
                <Icon
                    name={isPending ? 'spinner' : 'dots-three-vertical'}
                    size={16}
                    className={isPending ? 'animate-spin' : undefined}
                />
            </button>

            {open &&
                createPortal(
                    <>
                        <FlyoutGuard
                            onClose={onToggle}
                        />

                        <div
                            ref={menuRef}
                            role="menu"
                            data-flyout-panel
                            className="fixed z-[calc(var(--z-modal)+1)] w-44 overflow-y-auto overflow-x-hidden rounded-[var(--shell-radius)] border bg-[var(--color-card-bg)] py-1 shadow-lg"
                            style={{
                                borderColor: 'var(--shell-border)',

                                /*
                                  Rising into place, briefly.

                                  A menu that simply exists on the next frame
                                  leaves the reader to work out where it came
                                  from; one that rises from its button says so.
                                  120ms — long enough to be seen and short
                                  enough that nobody waits for it.
                                */
                                animation: 'context-flyout-slide-up 120ms ease-out',
                                transformOrigin:
                                    at?.side === 'above' ? 'bottom right' : 'top right',

                                /*
                                  Off-screen until it has been measured.
                                  
                                  It has to be in the document to have a height,
                                  and it cannot be placed until it has one. The
                                  measuring happens in a layout effect, before
                                  the browser paints, so this frame is never
                                  seen -- but `visibility` rather than a missing
                                  panel, because an element that is not there
                                  cannot be measured either.
                                */
                                visibility: at ? 'visible' : 'hidden',
                                top: at?.top ?? 0,
                                left: at?.left ?? 0,
                                maxHeight: at?.maxHeight,
                            }}
                            onClick={(event) => event.stopPropagation()}
                        >
                            {/*
                              Three words, no sentences.

                              The descriptions under each line doubled the menu's
                              height to explain things their own verbs already
                              said. "Preview" needs no gloss; a menu somebody
                              reads is a menu they are slower to use.
                            */}
                            <button
                                type="button"
                                className={`${item} text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]`}
                                onClick={() => {
                                    onToggle();
                                    onOpen();
                                }}
                            >
                                <Icon name="eye" size={15} weight="duotone" className="shrink-0 opacity-70" />
                                <span>Preview</span>
                            </button>

                            <button
                                type="button"
                                className={`${item} text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]`}
                                onClick={() => {
                                    onToggle();
                                    onEdit();
                                }}
                            >
                                <Icon name="pencil" size={15} weight="duotone" className="shrink-0 opacity-70" />
                                <span>Edit</span>
                            </button>

                            {/*
                              Sync, for a shop that has something to sync with.

                              Two of them, because they fetch different things:
                              one asks the shop for what has changed, the other
                              for everything. An arrow cannot say which, so the
                              words do — and the difference matters when the
                              second can take minutes on a large catalogue.
                            */}
                            {store.is_connected && (
                                <>
                                    <div
                                        className="my-1 h-px"
                                        style={{ background: 'var(--shell-border)' }}
                                    />

                                    <button
                                        type="button"
                                        className={`${item} text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]`}
                                        disabled={isPending}
                                        onClick={() => {
                                            onToggle();
                                            onSync(false);
                                        }}
                                    >
                                        <Icon
                                            name="arrows-clockwise"
                                            size={15}
                                            className="shrink-0 opacity-70"
                                        />
                                        <span>Sync changes</span>
                                    </button>

                                    <button
                                        type="button"
                                        className={`${item} text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]`}
                                        disabled={isPending}
                                        onClick={() => {
                                            onToggle();
                                            onSync(true);
                                        }}
                                    >
                                        <Icon
                                            name="arrow-clockwise"
                                            size={15}
                                            className="shrink-0 opacity-70"
                                        />
                                        <span>Sync everything</span>
                                    </button>
                                </>
                            )}

                            {/*
                              Set apart, and coloured.

                              It is the one item here that cannot be undone from
                              this screen, and a destructive line that looks
                              exactly like the two above it is a line somebody
                              reaches by muscle memory.
                            */}
                            <div
                                className="my-1 h-px"
                                style={{ background: 'var(--shell-border)' }}
                            />

                            <button
                                type="button"
                                className={`${item} hover:bg-[var(--color-danger-subtle)]`}
                                style={{ color: 'var(--color-danger-text)' }}
                                onClick={() => {
                                    onToggle();
                                    onDelete();
                                }}
                            >
                                <Icon name="trash" size={15} weight="duotone" className="shrink-0" />
                                <span>Delete</span>
                            </button>
                        </div>
                    </>,
                    document.body,
                )}
        </>
    );
}


type Storefront = {
    id: string;
    name: string;
    domain: string;
    /** The shop's real address, from the connection behind it. Blank if none. */
    external_url?: string;
    /** The connection feeding this shop, if any. */
    connection_id?: string | null;
    is_connected?: boolean;
    type: string;
    status: string;
    products_count: number;
    total_orders: number;
    total_revenue: number;
    /** The same takings converted into the books, for adding shops together. */
    books_revenue: number;
    /** The shop's own symbol, so its takings can be shown in its own money. */
    currency_symbol: string;
    /** The short tag chosen for this shop, or null when none has been. */
    code: string | null;
    /** What to show — the chosen tag, or initials when none has been chosen. */
    code_display: string | null;
    /** This shop's own mark, when it has set one. */
    logo_url?: string | null;
    logo_id?: string | null;
    theme: string;
    language: string;
    currency: string;
    created_at: string;
    last_updated: string;
};

type Vocabulary = { value: string; label: string };

type StorefrontsResponse = {
    data: Storefront[];
    /** What the column accepts — sent so the form and filter cannot disagree. */
    types: Vocabulary[];
    statuses: Vocabulary[];
    currencies: Array<{ code: string; name: string; symbol: string }>;
    summary: {
        total_storefronts: number;
        active_count: number;
        total_products: number;
        total_revenue: number;
    };
    /**
     * A fortnight of daily figures behind the headline numbers.
     *
     * Counted from the orders on every request rather than stored, so they
     * cannot disagree with the totals above them — see the endpoint.
     */
    trends?: {
        revenue: number[];
        orders: number[];
        products: number[];
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Storefronts() {
    useDocumentTitle('Storefronts');

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState('');
    const [newDomain, setNewDomain] = useState('');
    const [newCode, setNewCode] = useState('');
    const [newLogo, setNewLogo] = useState<string | null>(null);
    const [newLogoUrl, setNewLogoUrl] = useState<string | null>(null);
    const [newType, setNewType] = useState('online');
    const [newStatus, setNewStatus] = useState('active');
    const [storeTab, setStoreTab] = useState<'details' | 'fields' | 'statuses'>('details');

    /*
     * What the open panel has to say about itself.
     *
     * Held here rather than in the panel because it is shown here — beside Sync,
     * where it describes the connection. Cleared when the tab changes, so the
     * field mapping's counts never linger over the status list.
     */
    const [panelCounts, setPanelCounts] = useState<{ mapped: number; unmapped: number } | null>(null);
    const [showSyncMenu, setShowSyncMenu] = useState(false);
    const [inlineSyncMenu, setInlineSyncMenu] = useState<string | null>(null);
    
    // Currency change confirmation
    const [showCurrencyWarning, setShowCurrencyWarning] = useState(false);
    const [pendingCurrency, setPendingCurrency] = useState<string | null>(null);

    // What the drawer is editing. Seeded when a shop is opened.
    const [edit, setEdit] = useState({
        name: '',
        code: '',
        custom_domain: '',
        currency: '',
        type: 'online',
        status: 'active',
        /*
         * The chosen picture, as a media id.
         *
         * Three states, deliberately: undefined is "not touched" and is left
         * out of the request entirely, null is "remove it", and a string is a
         * new one. Collapsing the first two would clear a shop's logo every
         * time somebody edited its name.
         */
        logo: undefined as string | null | undefined,
        /** Only for the preview beside the button; never sent. */
        logo_url: null as string | null,
    });

    /** True while a chosen file is on its way to the media library. */
    const [logoUploading, setLogoUploading] = useState(false);

    /*
     * Uploaded to the media library, then referenced.
     *
     * The library already stores files, records dimensions, makes thumbnails
     * and knows how to build a URL for whichever disk is configured. Posting
     * the image straight at the storefront would be a second, worse copy of all
     * of that — and one that breaks the day the disk changes.
     */
    const uploadLogo = async (file: File, into: 'edit' | 'create' = 'edit'): Promise<void> => {
        setLogoUploading(true);

        try {
            const body = new FormData();
            body.append('file', file);

            const result = (await api.post('/media', body)) as {
                data: { id: string; url: string };
            };

            setEdit((current) => ({
                ...current,
                logo: result.data.id,
                logo_url: result.data.url,
            }));
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'That image could not be uploaded.');
        } finally {
            setLogoUploading(false);
        }
    };

    /*
     * The drawer reads before it writes.
     *
     * Opening a shop to check something is the common case; changing it is the
     * rarer one. Presenting a form every time makes the common case work to read
     * and invites edits nobody meant — so the details are shown plainly, and the
     * pencil turns them into fields.
     */
    const [editing, setEditing] = useState(false);

    const queryClient = useQueryClient();

    const saveShop = useMutation({
        mutationFn: () => {
            // logo_url is for the preview only, and logo is omitted entirely
            // unless it was touched - see the three states on the state above.
            const { logo_url: _preview, logo, ...rest } = edit;

            return api.patch(`/storefronts/${selectedStorefront?.id}`, {
                ...rest,
                ...(logo === undefined ? {} : { logo }),
            });
        },
        onSuccess: () => {
            toast.success('Saved.');
            setEditing(false);
            void queryClient.invalidateQueries({ queryKey: ['storefronts'] });
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    /*
     * Status, not the flag.
     *
     * is_active is what the rest of the application reads; the endpoint keeps it
     * in step. Setting the flag here directly would leave a shop reading
     * "Maintenance" while its status column still said active.
     */
    const setActive = useMutation({
        mutationFn: (active: boolean) =>
            api.patch(`/storefronts/${selectedStorefront?.id}`, {
                status: active ? 'active' : 'inactive',
            }),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['storefronts'] });
            setSelectedId(null);
        },
    });

    /*
     * Bring this shop's records in, from the shop itself.
     *
     * The same sync Settings runs — "something is missing from this shop" is
     * noticed while looking at the shop, not while looking at a list of
     * connections.
     * 
     * Supports both incremental and full sync:
     * - Incremental: Only fetches records modified since last sync
     * - Full: Fetches all records from the beginning (ignores last sync time)
     */
    const syncShop = useMutation({
        mutationFn: (params: { id?: string; full?: boolean }) =>
            api.post<{ data: { written?: number; skipped?: number } }>(
                `/storefronts/${params.id ?? selectedId}/sync`,
                { full: params?.full ?? false },
            ),
        onSuccess: (result, variables) => {
            const written = result.data?.written ?? 0;
            const skipped = result.data?.skipped ?? 0;
            const syncType = variables?.full ? 'Full sync' : 'Incremental sync';
            const brought = `${syncType}: Brought in ${written} record${written === 1 ? '' : 's'}`;

            if (skipped > 0) {
                toast.warning(`${brought} — ${skipped} could not be imported.`);
            } else {
                toast.success(`${brought}.`);
            }

            void queryClient.invalidateQueries({ queryKey: ['storefronts'] });
        },
        // The driver's own sentence, not a bare status code.
        onError: (error: Error) => toast.error(error.message || 'That sync did not finish.'),
    });

    const removeShop = useMutation({
        /*
         * Told which shop, rather than reading whichever drawer is open.
         *
         * It took the id from the open drawer, which was fine while the only
         * way to delete was from inside one. The row's menu deletes without
         * opening anything, and left as it was it would have deleted nothing —
         * or, worse, whatever had been looked at last.
         */
        mutationFn: (id?: string) => api.delete(`/storefronts/${id ?? selectedStorefront?.id}`),
        onSuccess: () => {
            toast.success('Shop removed.');
            void queryClient.invalidateQueries({ queryKey: ['storefronts'] });
            setSelectedId(null);
        },
        // The refusal when orders sit behind it explains itself, so show it.
        onError: (error: Error) => toast.error(error.message || 'That shop could not be removed.'),
    });

    const create = useMutation({
        mutationFn: () =>
            api.post('/storefronts', {
                name: newName,
                code: newCode || null,
                custom_domain: newDomain || null,
                type: newType,
                status: newStatus,
            }),
        onSuccess: () => {
            toast.success(`${newName} created.`);
            setNewName('');
            setNewDomain('');
            setNewCode('');
            setCreating(false);
            void queryClient.invalidateQueries({ queryKey: ['storefronts'] });
        },
        onError: (error: Error) => toast.error(error.message || 'That shop could not be created.'),
    });
    const [sortBy, setSortBy] = useState<string | null>('created_at');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('desc');

    /*
     * The period every figure on this screen describes.
     *
     * Null is all of it, which is the honest default: a shop's lifetime takings
     * are the right answer to "how much has this made" until somebody asks
     * something narrower.
     */
    const [range, setRange] = useState<DateRange | null>(null);

    // Whether the filters are showing. They were four controls on the row at
    // all times, for something touched once a session.
    const [filtersOpen, setFiltersOpen] = useState(false);

    const filterButton = useRef<HTMLButtonElement>(null);
    const filterPanel = useRef<HTMLDivElement>(null);

    /*
     * Placed against the window, not hung off the button.
     *
     * `absolute top-full` puts a panel below its trigger and trusts there to be
     * room. On a short window there is not, and the Clear button at the bottom
     * of it -- the one somebody opened the panel to reach -- was the part that
     * went past the fold.
     */
    const filterAt = useFlyoutPosition({
        open: filtersOpen,
        trigger: filterButton,
        panel: filterPanel,
    });

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: [
            'storefronts',
            { search, typeFilter, statusFilter, sortBy, sortDirection, range },
        ],
        queryFn: ({ signal }) =>
            api.get<StorefrontsResponse>('/storefronts', {
                params: {
                    from: range?.start ? toIsoDay(range.start) : undefined,
                    to: range?.end ? toIsoDay(range.end) : undefined,
                    search,
                    type: typeFilter || undefined,
                    status: statusFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const storefronts = data?.data ?? [];

    /*
     * Looked up rather than remembered.
     *
     * Holding the row in state meant the drawer showed whatever was true when it
     * was opened: saving refetched the list and the drawer carried on displaying
     * the old name until the page was reloaded. Derived from the list, it is
     * always the current row.
     */
    const selectedStorefront = storefronts.find((shop) => shop.id === selectedId) ?? null;

    const summary = data?.summary;
    const trends = data?.trends;

    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    const handleClearFilters = () => {
        setSearch('');
        setTypeFilter('');
        setStatusFilter('');
    };

    const hasFilters = search || typeFilter || statusFilter;

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney, both: money } = useMoney();


    /*
     * Read from the server rather than repeated here.
     *
     * The old copy listed four kinds and the column now accepts six — a list
     * typed twice is a list that disagrees with itself, and the half that is
     * wrong is whichever nobody opened.
     */
    const types = data?.types ?? [];
    const statuses = data?.statuses ?? [];
    const currencies = data?.currencies ?? [];

    const typeLabels: Record<string, string> = Object.fromEntries(
        types.map((t) => [t.value, t.label]),
    );

    const statusVariants: Record<string, 'success' | 'neutral' | 'warning' | 'info'> = {
        active: 'success',
        inactive: 'neutral',
        maintenance: 'warning',
        draft: 'info',
    };

    const statusLabels: Record<string, string> = Object.fromEntries(
        statuses.map((t) => [t.value, t.label]),
    );

    return (
        <div className="flex h-full flex-col">
            {/* No horizontal padding on any section: the filter bar carries its own,
                and everything else matching it is what makes the page line up
                with the header rather than sitting inset from it. */}
            <div className="pt-6">
                <PageHeader
                    title="Storefronts"
                    /*
                     * No description.
                     *
                     * "Manage multiple storefronts for different brands,
                     * regions, or categories" told somebody looking at a list
                     * of their own shops what a shop is. A line that is only
                     * read once, by somebody who did not need it, is a line
                     * that costs every later visit a little height.
                     */
                    actions={
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => setCreating(true)}
                        >
                            <Icon name="plus" size={16} />
                            <span>Create storefront</span>
                        </button>
                    }
                />
            </div>

            {/*
              Four shapes while the figures are on their way.

              They appeared only once the data had landed, so the row above the
              table went from nothing to four cards and pushed everything under
              it down the page — the one thing on this screen that moved after
              it had finished loading, on a screen where everything else already
              held its place.
            */}
            {!summary && isLoading && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                    <KPICardSkeleton />
                </div>
            )}

            {summary && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {/*
                      Two of these carry a line and two do not, on purpose.

                      Storefronts and Active are counts of a handful of things
                      that change a few times a year. A fortnight of daily
                      points would be a flat line under both, which reads as "no
                      activity" rather than as "nothing was expected here" — and
                      a card with a line beside one without is a clearer
                      statement than four flat lines in a row.
                    */}
                    <KPICard
                        icon="storefront"
                        label="Total Storefronts"
                        value={summary.total_storefronts.toLocaleString()}
                        variant="brand"
                    />
                    <KPICard
                        label="Active"
                        value={summary.active_count.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Total Products"
                        value={compactCount(summary.total_products)}
                        valueTitle={summary.total_products.toLocaleString()}
                        icon="package"
                        variant="info"
                        spark={trends?.products}
                    />
                    {/*
                      Compacted, with the figure itself on the hover.

                      A card is a glance. "৳75,815.01" is eleven characters of
                      precision nobody reads at a glance, and by the time a
                      business is taking millions it stops fitting the card and
                      starts reflowing the row it sits in. The exact amount is
                      the thing somebody occasionally needs, so it is a hover
                      away rather than gone.
                    */}
                    <KPICard
                        label="Total Revenue"
                        value={money(summary.total_revenue).short}
                        valueTitle={money(summary.total_revenue).exact}
                        icon="currency-dollar"
                        variant="success"
                        spark={trends?.revenue}
                    />
                </div>
            )}

            {/*
              ── One card, filters and rows together ────────────────────────

              They were two cards with a gap between, which drew a border and a
              strip of page between a search box and the rows it searches. They
              are one thing — a way into a list and the list — and they read as
              one thing now, divided rather than separated.
            */}
            {/*
              As tall as its rows, and no taller.

              `flex-1` made the card fill the page, so one shop sat above a
              hundred and sixty pixels of nothing inside a border — a box
              promising rows that were not coming. The rows decide the height;
              the scrolling region caps it when there are enough of them to
              need capping.
            */}
            <div className="card mt-6 flex flex-col">
                <FilterBar
                    compact
                    className="!rounded-none !border-0 !border-b"

                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search storefronts..."
                    /*
                     * The controls to the right, the search to the left.
                     *
                     * They sat together on the left, which left the rest of the
                     * row empty and put four things of different kinds in one
                     * run: a box you type into and three you choose from. Split,
                     * the row says what it is at a glance — find something on
                     * one side, narrow the list on the other.
                     */
                    trailingSearch={
                        /*
                          The period sits with the search, because both narrow
                          what is being looked at rather than how it is shown.
                        */
                        <DateRangePicker value={range} onChange={setRange} />
                    }
                    actions={
                        <>
                            {/*
                              Behind a button, with a mark when it is doing
                              something.

                              Two selects on the row at all times cost the width
                              of their widest option for a choice made once a
                              session — and left the row reading as four controls
                              of equal weight, when one of them is the search.
                            */}
                            <div className="relative">
                                <button
                                    ref={filterButton}
                                    type="button"
                                    onClick={() => setFiltersOpen(!filtersOpen)}
                                    /*
                                     * The brand colour on hover, because this
                                     * one opens something.
                                     *
                                     * Every other control on the row does its
                                     * work where it stands. This is the one that
                                     * puts a panel over the page, and colouring
                                     * the hover is the cheapest way to say so
                                     * before it is pressed rather than after.
                                     */
                                    className={cn(
                                        'btn btn-secondary hover:!border-[var(--color-brand)] hover:!bg-[var(--color-brand-hover)] hover:!text-[var(--color-text-on-accent)]',

                                        /*
                                          Filters on: the button wears the
                                          colour it takes on hover, and keeps
                                          it.
                                          
                                          It was a small dot beside the word,
                                          which is a footnote about the button
                                          rather than a change to it -- and at
                                          six pixels, one you have to already
                                          be looking for. A filled button reads
                                          from across the table, which is where
                                          somebody wonders why the count is low.
                                        */
                                        hasFilters &&
                                            '!border-[var(--color-brand)] !bg-[var(--color-brand-hover)] !text-[var(--color-text-on-accent)]',
                                    )}
                                    aria-expanded={filtersOpen}
                                    aria-haspopup="dialog"
                                >
                                    <Icon name="funnel" size={14} weight="duotone" />
                                    <span>Filter</span>

                                    {/*
                                      Turned when it is open, which is the one
                                      thing a caret can say that a static arrow
                                      cannot: not "there is more here" but "the
                                      more is showing".
                                    */}
                                    <Icon
                                        name="caret-down"
                                        size={12}
                                        className={`transition-transform ${filtersOpen ? 'rotate-180' : ''}`}
                                    />
                                </button>

                                {filtersOpen &&
                                    createPortal(
                                        <>
                                        <FlyoutGuard
                                            onClose={() => setFiltersOpen(false)}
                                        />

                                        <div
                                            ref={filterPanel}
                                            data-flyout-panel
                                            className="fixed z-[var(--z-flyout-panel)] w-64 space-y-3 overflow-y-auto rounded-[var(--shell-radius)] border bg-[var(--color-card-bg)] p-3 shadow-lg"
                                            style={{
                                                borderColor: 'var(--shell-border)',

                                                // The same arrival as the row's
                                                // menu: two things that open the
                                                // same way should look the same
                                                // doing it.
                                                animation:
                                                    'context-flyout-slide-up 120ms ease-out',
                                                transformOrigin:
                                                    filterAt?.side === 'above'
                                                        ? 'bottom right'
                                                        : 'top right',

                                                // Hidden for the frame it spends
                                                // being measured. See the row
                                                // menu for why it is visibility
                                                // rather than not rendering.
                                                visibility: filterAt ? 'visible' : 'hidden',
                                                top: filterAt?.top ?? 0,
                                                left: filterAt?.left ?? 0,
                                                maxHeight: filterAt?.maxHeight,
                                            }}
                                        >
                                            <FilterSelect
                                                label="Type"
                                                value={typeFilter}
                                                onChange={setTypeFilter}
                                                options={types}
                                                placeholder="All types"
                                            />
                                            <FilterSelect
                                                label="Status"
                                                value={statusFilter}
                                                onChange={setStatusFilter}
                                                options={statuses}
                                                placeholder="All statuses"
                                            />

                                            {hasFilters && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        handleClearFilters();
                                                        setFiltersOpen(false);
                                                    }}
                                                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                                >
                                                    <Icon name="x" size={14} />
                                                    <span>Clear filters</span>
                                                </button>
                                            )}
                                        </div>
                                        </>,
                                        document.body,
                                    )}
                            </div>

                            {/*
                              Fetching the list again, which is not the same as
                              syncing a shop — this asks the application what it
                              already knows, where Sync asks the shop. Kept at
                              the end of the row, away from anything that changes
                              a shop, because a refresh should be the safest
                              button on a screen.
                            */}
                            <button
                                type="button"
                                onClick={() => void refetch()}
                                className="btn btn-secondary px-2"
                                title="Refresh this list"
                                aria-label="Refresh this list"
                                disabled={isLoading}
                            >
                                <Icon
                                    name="arrow-clockwise"
                                    size={14}
                                    className={isLoading ? 'animate-spin' : undefined}
                                />
                            </button>
                        </>
                    }
                />

                {isError ? (
                    <div className="p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load storefronts.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    /*
                     * The clipping lives here, not on the card.
                     *
                     * The card had `overflow-hidden` to keep its rows inside its
                     * corners, and that also clipped everything the toolbar
                     * above them opens — the date picker's calendar came out
                     * half a calendar, cut off at the card's lower edge.
                     *
                     * Only the rows need clipping. The toolbar sits outside it,
                     * and its popovers hang over the page as they should.
                     */
                    <div className="max-h-[min(60vh,40rem)] overflow-auto rounded-b-[var(--shell-radius)]">
                        <Table
                            data={storefronts}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Storefront',
                                    render: (store) => (
                                        /*
                                          One line, with the address on the
                                          hover.

                                          A second line under every name makes
                                          every row twice as tall for something
                                          almost nobody reads — a domain is
                                          checked once when a shop is connected
                                          and then never again. Ten shops on
                                          screen instead of five is worth more
                                          than a URL nobody is looking at, and
                                          the ones who are looking still have it.
                                        */
                                        <button
                                            type="button"
                                            onClick={() => setSelectedId(store.id)}
                                            className="text-left font-medium text-[var(--color-text-main)] transition hover:text-[var(--color-brand)]"
                                            title={
                                                store.domain
                                                    ? `${store.name} — ${store.domain}`
                                                    : store.name
                                            }
                                        >
                                            {store.name}
                                        </button>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    accessor: (store) => typeLabels[store.type] ?? store.type,
                                },
                                {
                                    key: 'products_count',
                                    label: 'Products',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="tabular-nums">{store.products_count.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'total_orders',
                                    label: 'Orders',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="tabular-nums">{store.total_orders.toLocaleString()}</span>
                                    ),
                                },
                                {
                                    key: 'total_revenue',
                                    label: 'Revenue',
                                    align: 'right',
                                    sortable: true,
                                    render: (store) => (
                                        <span className="font-semibold tabular-nums" title={`${formatMoney(store.books_revenue)} in your books`}>
                                            {store.currency_symbol}
                                            {store.total_revenue.toLocaleString(undefined, {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            })}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    /*
                                      No dot on the badge. It is already a
                                      coloured pill with the word inside it, and
                                      a coloured dot inside a coloured pill says
                                      the same thing twice.
                                    */
                                    render: (store) => (
                                        <StatusBadge
                                            label={statusLabels[store.status] ?? store.status}
                                            variant={statusVariants[store.status] ?? 'neutral'}
                                        />
                                    ),
                                },
                                {
                                    key: 'actions',
                                    /*
                                      Named, like every other column.

                                      A blank heading over a column of buttons
                                      leaves somebody to work out what they are
                                      by clicking one, which is the wrong way
                                      round for a column whose contents change
                                      things.
                                    */
                                    label: 'Action',
                                    align: 'right',
                                    render: (store) => (
                                        <RowActions
                                            store={store}
                                            open={inlineSyncMenu === store.id}
                                            onToggle={() =>
                                                setInlineSyncMenu(
                                                    inlineSyncMenu === store.id ? null : store.id,
                                                )
                                            }
                                            onOpen={() => setSelectedId(store.id)}
                                            onSync={(full) =>
                                                syncShop.mutate({ id: store.id, full })
                                            }
                                            onEdit={() => {
                                                setSelectedId(store.id);
                                                setStoreTab('details');
                                                setEditing(true);
                                            }}
                                            onDelete={async () => {
                                                /*
                                                  Asked before, not undone after.

                                                  A shop carries its orders, its
                                                  mapping and its statuses, and
                                                  none of that comes back from a
                                                  toast with an Undo on it.
                                                */
                                                const sure = await confirm(
                                                    `Remove ${store.name}?`,
                                                    'Its field mapping and status mapping go with it. Orders already imported stay where they are.',
                                                );

                                                if (sure) {
                                                    removeShop.mutate(store.id);
                                                }
                                            }}
                                            isPending={
                                                syncShop.isPending || removeShop.isPending
                                            }
                                        />
                                    ),
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            /*
                              The name opens the shop, not the row.

                              A whole row that responds to a click makes every
                              pixel of it a target, including the cells somebody
                              is reading a figure out of and the gaps between
                              controls. The name is the thing that looks like a
                              way in, so it is the thing that is one.
                            */
                            getRowKey={(store) => store.id}
                            emptyState={
                                <EmptyState
                                    icon="storefront"
                                    title={hasFilters ? 'No storefronts match' : 'No storefronts yet'}
                                    body={
                                        hasFilters
                                            ? 'Try adjusting your filters to see more results.'
                                            : 'Create your first storefront to sell online.'
                                    }
                                    action={
                                        hasFilters ? (
                                            <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                Clear filters
                                            </button>
                                        ) : (
                                            <button className="btn btn-primary">
                                                <Icon name="plus" size={16} />
                                                <span>Create storefront</span>
                                            </button>
                                        )
                                    }
                                />
                            }
                        />
                    </div>
                )}
            </div>

            {/*
              Creating a shop asks for the two things that matter — what to call
              it, and where it lives if it lives anywhere. Everything else has a
              sensible default and can be changed in the drawer afterwards;
              a twenty-field form to add a shop is a form nobody finishes.
            */}
            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title="Create storefront"
                size="md"
            >
                <div className="space-y-4">
                    <div>
                        <label htmlFor="shop-name" className="mb-1.5 block text-sm font-medium">
                            Name
                        </label>
                        <input
                            id="shop-name"
                            className="field w-full"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            placeholder="Main Shop"
                            autoFocus
                        />
                    </div>

                    <div>
                        <label htmlFor="shop-domain" className="mb-1.5 block text-sm font-medium">
                            Address <span className="text-xs text-[var(--color-text-subtle)]">optional</span>
                        </label>
                        <input
                            id="shop-domain"
                            className="field w-full"
                            value={newDomain}
                            onChange={(e) => setNewDomain(e.target.value)}
                            placeholder="shop.example.com"
                        />
                    </div>

                    <div>
                        <label htmlFor="shop-code" className="mb-1.5 block text-sm font-medium">
                            Short code{' '}
                            <span className="text-xs text-[var(--color-text-subtle)]">optional</span>
                        </label>
                        <input
                            id="shop-code"
                            className="field w-full uppercase"
                            value={newCode}
                            maxLength={8}
                            onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                            placeholder="VB"
                        />
                        <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                            Shown beside this shop's order numbers. Left blank, initials of the name
                            are used.
                        </p>
                    </div>

                    {/*
                      Offered while the shop is being made, not only afterwards.

                      It was only on the edit form, which meant every new shop
                      started with the wrong mark on its invoices until somebody
                      noticed and went back for it — and the moment a person is
                      most willing to set a logo is the moment they are setting
                      everything else.
                    */}
                    <div>
                        <span className="mb-1.5 block text-sm font-medium">
                            Logo{' '}
                            <span className="text-xs text-[var(--color-text-subtle)]">optional</span>
                        </span>

                        <div className="flex items-center gap-3">
                            <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)]">
                                {newLogoUrl ? (
                                    <img
                                        src={newLogoUrl}
                                        alt=""
                                        className="max-h-full max-w-full object-contain"
                                    />
                                ) : (
                                    <Icon name="image" size={20} className="text-[var(--color-text-subtle)]" />
                                )}
                            </div>

                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <label className="btn btn-secondary cursor-pointer text-sm">
                                        {logoUploading ? 'Uploading…' : 'Choose image'}
                                        <input
                                            type="file"
                                            accept="image/*"
                                            className="hidden"
                                            disabled={logoUploading}
                                            onChange={(event) => {
                                                const file = event.target.files?.[0];
                                                event.target.value = '';

                                                if (file) {
                                                    void uploadLogo(file, 'create');
                                                }
                                            }}
                                        />
                                    </label>

                                    {newLogoUrl && (
                                        <button
                                            type="button"
                                            className="text-sm text-[var(--color-text-muted)] hover:text-[var(--color-danger)]"
                                            onClick={() => {
                                                setNewLogo(null);
                                                setNewLogoUrl(null);
                                            }}
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>

                                <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                    Shown on invoices for this shop&rsquo;s orders. Leave it empty to use the
                                    business logo.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="shop-type" className="mb-1.5 block text-sm font-medium">
                                Kind
                            </label>
                            <select
                                id="shop-type"
                                className="field w-full"
                                value={newType}
                                onChange={(e) => setNewType(e.target.value)}
                            >
                                {types.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="shop-status" className="mb-1.5 block text-sm font-medium">
                                State
                            </label>
                            <select
                                id="shop-status"
                                className="field w-full"
                                value={newStatus}
                                onChange={(e) => setNewStatus(e.target.value)}
                            >
                                {statuses.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={() => setCreating(false)}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => create.mutate()}
                        disabled={newName.trim() === '' || create.isPending}
                    >
                        Create
                    </button>
                </div>
            </Modal>

            <DetailDrawer
                open={!!selectedStorefront}
                onClose={() => setSelectedId(null)}
                title={selectedStorefront?.name ?? ''}
                subtitle={selectedStorefront?.domain ?? ''}
                /*
                 * Sync belongs beside the shop's name, not among the tabs.
                 *
                 * It acts on the whole connection, where the tabs only choose
                 * which part of it to look at — so sitting in the tab row made
                 * an action look like a fourth place to go, and left one row
                 * doing two unrelated jobs. In the header it reads as what it
                 * is: something done to this shop, next to the shop's name.
                 */
                actions={
                    selectedStorefront?.is_connected && selectedStorefront?.connection_id ? (
                        <>
                            {/*
                              What is mapped, in a box of its own beside Sync.

                              Bordered to match the button next to it, so the two
                              read as one group of things about this connection
                              rather than as a stray phrase that drifted into the
                              header. Divided down the middle because the two
                              numbers answer opposite questions, and a run of
                              small grey words does not say which is which.
                            */}
                            {storeTab !== 'details' && panelCounts && (
                                <div
                                    className="hidden items-stretch overflow-hidden rounded-[var(--shell-radius)] border text-xs sm:flex"
                                    style={{ borderColor: 'var(--shell-border)' }}
                                >
                                    <span className="px-2.5 py-1.5 text-[var(--color-text-muted)]">
                                        <span className="font-medium text-[var(--color-text-main)]">
                                            {panelCounts.mapped}
                                        </span>{' '}
                                        mapped
                                    </span>

                                    <span
                                        className="w-px"
                                        style={{ background: 'var(--shell-border)' }}
                                        aria-hidden="true"
                                    />

                                    <span
                                        className="px-2.5 py-1.5"
                                        style={{
                                            color:
                                                panelCounts.unmapped > 0
                                                    ? 'var(--color-warning-text)'
                                                    : 'var(--color-text-muted)',
                                        }}
                                    >
                                        <span className="font-medium">{panelCounts.unmapped}</span> not
                                        mapped
                                    </span>
                                </div>
                            )}

                    <div className="relative">
                        <div className="flex gap-0">
                            <button
                                type="button"
                                className="btn btn-secondary rounded-r-none border-r-0"
                                onClick={() => syncShop.mutate({ full: false })}
                                disabled={syncShop.isPending}
                            >
                                <Icon
                                    name={syncShop.isPending ? 'spinner' : 'arrows-clockwise'}
                                    size={14}
                                    className={syncShop.isPending ? 'animate-spin' : undefined}
                                />
                                <span>{syncShop.isPending ? 'Syncing…' : 'Sync'}</span>
                            </button>
                            <button
                                type="button"
                                className="btn btn-secondary rounded-l-none px-2"
                                onClick={() => setShowSyncMenu(!showSyncMenu)}
                                disabled={syncShop.isPending}
                            >
                                <Icon name="caret-down" size={12} />
                            </button>
                        </div>
                                    
                        {showSyncMenu && (
                            <>
                                <FlyoutGuard
                                    onClose={() => setShowSyncMenu(false)}
                                />
                                <div data-flyout-panel className="absolute right-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] shadow-lg">
                                    <button
                                        type="button"
                                        className="flex w-full items-start gap-3 px-4 py-3 text-left text-sm hover:bg-[var(--shell-hover)]"
                                        onClick={() => {
                                            setShowSyncMenu(false);
                                            syncShop.mutate({ full: false });
                                        }}
                                    >
                                        <Icon name="arrows-clockwise" size={16} className="mt-0.5 flex-shrink-0" />
                                        <div>
                                            <div className="font-medium text-[var(--color-text-main)]">Incremental Sync</div>
                                            <div className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                Only new or modified records
                                            </div>
                                        </div>
                                    </button>
                                    <button
                                        type="button"
                                        className="flex w-full items-start gap-3 border-t border-[var(--shell-border)] px-4 py-3 text-left text-sm hover:bg-[var(--shell-hover)]"
                                        onClick={() => {
                                            setShowSyncMenu(false);
                                            syncShop.mutate({ full: true });
                                        }}
                                    >
                                        <Icon name="arrow-clockwise" size={16} className="mt-0.5 flex-shrink-0" />
                                        <div>
                                            <div className="font-medium text-[var(--color-text-main)]">Full Sync</div>
                                            <div className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                All records from the beginning
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                        </>
                    ) : undefined
                }
                /*
                 * One width, whichever tab is open.
                 *
                 * It used to narrow for Details and widen for the other two,
                 * which meant the drawer jumped every time somebody moved
                 * between them — the shop's name sliding sideways under the
                 * cursor that had just clicked a tab beside it.
                 *
                 * Sized for the widest of the three rather than each in turn,
                 * because the alternative to a stable frame is a frame that
                 * argues with its own tabs. Details has room to spare and looks
                 * calmer for it.
                 */
                size="2xl"
                /*
                 * The tabs live in the drawer's own head now.
                 *
                 * They used to be the first thing in the scrolling body, which
                 * meant pinning them, measuring their height, publishing it for
                 * the toolbar beneath to stack against, and covering the strip
                 * of padding they scrolled through. All of that existed to make
                 * them behave like part of the head. Putting them in the head
                 * is the same effect and none of the machinery.
                 */
                tabs={
                    selectedStorefront?.is_connected && selectedStorefront?.connection_id
                        ? [
                              {
                                  key: 'details',
                                  label: 'Details',
                                  icon: 'info',
                                  content: null,
                              },
                              {
                                  key: 'fields',
                                  label: 'Field mapping',
                                  icon: 'arrows-clockwise',
                                  content: null,
                              },
                              {
                                  key: 'statuses',
                                  label: 'Statuses',
                                  icon: 'list',
                                  content: null,
                              },
                          ]
                        : undefined
                }
                activeTab={storeTab}
                onTabChange={(next) => {
                    setStoreTab(next as 'details' | 'fields' | 'statuses');

                    // The next panel will report its own.
                    setPanelCounts(null);
                }}
            >
                {selectedStorefront && (
                    <div className="flex min-h-0 flex-1 flex-col gap-4">
                        {/*
                          Mapping lives here, in the shop's own drawer, because
                          it is about this shop: its field names, its statuses,
                          its custom fields. A business with three shops maps
                          three different sets.
                        */}
                        {storeTab === 'fields' && selectedStorefront.connection_id && (
                            <FieldMapPanel
                                connectionId={selectedStorefront.connection_id}
                                onSummary={setPanelCounts}
                            />
                        )}

                        {storeTab === 'statuses' && selectedStorefront.connection_id && (
                            <StatusMapPanel
                                connectionId={selectedStorefront.connection_id}
                                onSummary={setPanelCounts}
                            />
                        )}

                        {storeTab === 'details' && (
                            <>
                                {/*
                                  Read first, edit on request. Opening a shop to
                                  check something is the common case; changing it
                                  is the rarer one. A form every time makes the
                                  common case work to read and invites edits
                                  nobody meant, so the pencil is the only way in.
                                */}
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-semibold">Details</h3>

                                    {!editing && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                // Seeded here, from the row as
                                                // it is now — not from whatever
                                                // was current when the drawer
                                                // was opened.
                                                setEdit({
                                                    name: selectedStorefront.name,
                                                    // The chosen tag, not the
                                                    // displayed one — a field
                                                    // must not look filled in
                                                    // with something nobody typed.
                                                    code: selectedStorefront.code ?? '',
                                                    custom_domain: selectedStorefront.domain,
                                                    currency: selectedStorefront.currency,
                                                    type: selectedStorefront.type,
                                                    status: selectedStorefront.status,
                                                    // undefined, not null: an
                                                    // untouched logo must never
                                                    // be sent as a removal.
                                                    logo: undefined,
                                                    logo_url: selectedStorefront.logo_url ?? null,
                                                });
                                                setEditing(true);
                                            }}
                                            className="text-[var(--color-text-muted)] hover:text-[var(--color-brand)]"
                                            aria-label="Edit these details"
                                            title="Edit"
                                        >
                                            <Icon name="pencil-simple" size={15} />
                                        </button>
                                    )}
                                </div>

                                {editing ? (
                                    <div className="space-y-4">
                                        <div>
                                            <label htmlFor="sf-name" className="mb-1.5 block text-sm font-medium">
                                                Name
                                            </label>
                                            <input
                                                id="sf-name"
                                                className="field w-full"
                                                value={edit.name}
                                                onChange={(e) => setEdit((c) => ({ ...c, name: e.target.value }))}
                                            />
                                        </div>

                                        <div>
                                            <label htmlFor="sf-addr" className="mb-1.5 block text-sm font-medium">
                                                Address
                                            </label>
                                            <input
                                                id="sf-addr"
                                                className="field w-full"
                                                value={edit.custom_domain}
                                                onChange={(e) =>
                                                    setEdit((c) => ({ ...c, custom_domain: e.target.value }))
                                                }
                                                placeholder="shop.example.com"
                                            />
                                        </div>

                                        {/*
                                          The tag this shop is known by, shown
                                          against its orders and products.

                                          Optional: left blank, initials of the
                                          name stand in, so nothing is ever
                                          untagged and this is a choice rather
                                          than a chore.
                                        */}
                                        <div>
                                            <label htmlFor="sf-code" className="mb-1.5 block text-sm font-medium">
                                                Short code
                                            </label>
                                            <input
                                                id="sf-code"
                                                className="field w-full uppercase"
                                                value={edit.code}
                                                maxLength={8}
                                                onChange={(e) =>
                                                    setEdit((c) => ({ ...c, code: e.target.value.toUpperCase() }))
                                                }
                                                placeholder={selectedStorefront.code_display ?? 'VB'}
                                            />
                                            <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                                Shown beside this shop's order numbers, so you can tell them from
                                                another shop's.
                                                {! selectedStorefront.code && selectedStorefront.code_display && (
                                                    <> Currently showing {selectedStorefront.code_display}, from the name.</>
                                                )}
                                            </p>
                                        </div>

                                        {/*
                                          The mark that goes on this shop's
                                          paperwork.

                                          ── Why it is offered per shop ────────

                                          An invoice is issued by the shop the
                                          order was placed in, and a business
                                          here can run several. One logo on the
                                          business would put the wrong brand on
                                          every order from the second shop —
                                          confidently wrong, in front of a
                                          customer, which is worse than none.

                                          Left blank the business logo is used,
                                          because most people run one shop and
                                          think of the brand as theirs.
                                        */}
                                        <div>
                                            <span className="mb-1.5 block text-sm font-medium">Logo</span>

                                            <div className="flex items-center gap-3">
                                                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)]">
                                                    {edit.logo_url ? (
                                                        <img
                                                            src={edit.logo_url}
                                                            alt=""
                                                            className="max-h-full max-w-full object-contain"
                                                        />
                                                    ) : (
                                                        <Icon
                                                            name="image"
                                                            size={20}
                                                            className="text-[var(--color-text-subtle)]"
                                                        />
                                                    )}
                                                </div>

                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <label className="btn btn-secondary cursor-pointer text-sm">
                                                            {logoUploading ? 'Uploading…' : 'Choose image'}
                                                            <input
                                                                type="file"
                                                                accept="image/*"
                                                                className="hidden"
                                                                disabled={logoUploading}
                                                                onChange={(event) => {
                                                                    const file = event.target.files?.[0];
                                                                    // Cleared so choosing the same file twice still
                                                                    // fires a change event.
                                                                    event.target.value = '';

                                                                    if (file) {
                                                                        void uploadLogo(file);
                                                                    }
                                                                }}
                                                            />
                                                        </label>

                                                        {edit.logo_url && (
                                                            <button
                                                                type="button"
                                                                className="text-sm text-[var(--color-text-muted)] hover:text-[var(--color-danger)]"
                                                                onClick={() =>
                                                                    setEdit((c) => ({
                                                                        ...c,
                                                                        logo: null,
                                                                        logo_url: null,
                                                                    }))
                                                                }
                                                            >
                                                                Remove
                                                            </button>
                                                        )}
                                                    </div>

                                                    <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                                        Shown on invoices for this shop&rsquo;s orders. Leave it empty
                                                        to use the business logo.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label htmlFor="sf-type" className="mb-1.5 block text-sm font-medium">
                                                    Kind
                                                </label>
                                                <select
                                                    id="sf-type"
                                                    className="field w-full"
                                                    value={edit.type}
                                                    onChange={(e) => setEdit((c) => ({ ...c, type: e.target.value }))}
                                                >
                                                    {types.map((o) => (
                                                        <option key={o.value} value={o.value}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div>
                                                <label htmlFor="sf-state" className="mb-1.5 block text-sm font-medium">
                                                    State
                                                </label>
                                                <select
                                                    id="sf-state"
                                                    className="field w-full"
                                                    value={edit.status}
                                                    onChange={(e) => setEdit((c) => ({ ...c, status: e.target.value }))}
                                                >
                                                    {statuses.map((o) => (
                                                        <option key={o.value} value={o.value}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div>
                                            <label htmlFor="sf-cur" className="mb-1.5 block text-sm font-medium">
                                                Currency
                                            </label>
                                            <select
                                                id="sf-cur"
                                                className="field w-full disabled:opacity-60 disabled:cursor-not-allowed"
                                                value={edit.currency || ''}
                                                onChange={(e) => {
                                                    const newCurrency = e.target.value;
                                                    // If currency is changing and there are orders, show warning
                                                    if (newCurrency !== selectedStorefront.currency && selectedStorefront.total_orders > 0) {
                                                        setPendingCurrency(newCurrency);
                                                        setShowCurrencyWarning(true);
                                                    } else {
                                                        setEdit((c) => ({ ...c, currency: newCurrency }));
                                                    }
                                                }}
                                                disabled={!!selectedStorefront.integration_id}
                                            >
                                                {!edit.currency && (
                                                    <option value="">Not set - will auto-detect from orders</option>
                                                )}
                                                {currencies.map((c) => (
                                                    <option key={c.code} value={c.code}>
                                                        {c.code} — {c.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                                {selectedStorefront.integration_id ? (
                                                    <>Auto-detected from orders. Currency from order payloads is always preserved.</>
                                                ) : (
                                                    <>Set manually or auto-detected from first order. Used as fallback if orders have no currency.</>
                                                )}
                                            </p>
                                        </div>

                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className="btn btn-primary"
                                                onClick={() => saveShop.mutate()}
                                                disabled={saveShop.isPending || edit.name.trim() === ''}
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-secondary"
                                                onClick={() => setEditing(false)}
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <dl className="divide-y divide-[var(--shell-border)] text-sm">
                                        <Detail label="Address" value={selectedStorefront.domain} />
                                        <Detail
                                            label="Kind"
                                            value={typeLabels[selectedStorefront.type] ?? selectedStorefront.type}
                                        />
                                        <Detail
                                            label="State"
                                            value={
                                                <StatusBadge
                                                    label={
                                                        statusLabels[selectedStorefront.status] ??
                                                        selectedStorefront.status
                                                    }
                                                    variant={statusVariants[selectedStorefront.status] ?? 'neutral'}
                                                />
                                            }
                                        />
                                        <Detail label="Currency" value={selectedStorefront.currency} />
                                        <Detail
                                            label="Short code"
                                            value={
                                                selectedStorefront.code_display ?? '—'
                                            }
                                        />
                                        <Detail
                                            label="Connected"
                                            value={selectedStorefront.is_connected ? 'Yes' : 'Nothing connected'}
                                        />
                                        <Detail
                                            label="Orders"
                                            value={selectedStorefront.total_orders.toLocaleString()}
                                        />
                                        <Detail
                                            label="Revenue"
                                            value={`${selectedStorefront.currency_symbol}${selectedStorefront.total_revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                                        />
                                        <Detail
                                            label="Products"
                                            value={selectedStorefront.products_count.toLocaleString()}
                                        />
                                    </dl>
                                )}

                                <div className="flex flex-wrap gap-2 border-t border-[var(--shell-border)] pt-4">
                                    {/* The shop's own address, from the
                                        connection behind it. */}
                                    {selectedStorefront.external_url && (
                                        <a
                                            href={selectedStorefront.external_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="btn btn-secondary"
                                        >
                                            <Icon name="arrow-square-out" size={15} />
                                            <span>Visit shop</span>
                                        </a>
                                    )}

                                    <button
                                        type="button"
                                        className="btn btn-secondary"
                                        onClick={() => setActive.mutate(selectedStorefront.status !== 'active')}
                                    >
                                        {selectedStorefront.status === 'active' ? 'Deactivate' : 'Activate'}
                                    </button>

                                    <button
                                        type="button"
                                        className="btn btn-secondary"
                                        onClick={() => removeShop.mutate(selectedStorefront?.id)}
                                    >
                                        <Icon name="trash" size={14} />
                                        <span>Remove</span>
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                )}
            </DetailDrawer>

            {/* Currency Change Warning Modal */}
            <Modal
                open={showCurrencyWarning}
                onClose={() => {
                    setShowCurrencyWarning(false);
                    setPendingCurrency(null);
                }}
                title="Currency Change Options"
                size="md"
            >
                <div className="space-y-4">
                    <div className="rounded-[var(--shell-radius)] border border-[var(--color-warning)] bg-[var(--color-warning-muted)] p-4">
                        <div className="flex items-start gap-3">
                            <Icon name="warning" size={20} className="shrink-0 text-[var(--color-warning)]" />
                            <div>
                                <p className="font-medium text-[var(--color-text-main)]">
                                    Changing Currency: {selectedStorefront?.currency} → {pendingCurrency}
                                </p>
                                <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                    This storefront has {selectedStorefront?.total_orders} existing orders in {selectedStorefront?.currency}.
                                </p>
                            </div>
                        </div>
                    </div>

                    <p className="text-sm text-[var(--color-text-body)]">
                        How should we handle the existing order values?
                    </p>

                    <div className="space-y-3">
                        {/* Option 1: Keep same values */}
                        <button
                            type="button"
                            className="w-full rounded-[var(--shell-radius)] border border-[var(--shell-border)] p-4 text-left transition hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-muted)]"
                            onClick={() => {
                                setEdit((c) => ({ ...c, currency: pendingCurrency || '' }));
                                setShowCurrencyWarning(false);
                                setPendingCurrency(null);
                                toast.info('Currency updated. Order values remain unchanged.');
                            }}
                        >
                            <div className="flex items-start gap-3">
                                <Icon name="arrows-counter-clockwise" size={18} className="mt-0.5 shrink-0 text-[var(--color-brand)]" />
                                <div className="flex-1">
                                    <p className="font-medium">Keep Same Numeric Values</p>
                                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                        Example: {selectedStorefront?.currency} 131 → {pendingCurrency} 131
                                    </p>
                                    <p className="mt-2 text-xs text-[var(--color-text-subtle)]">
                                        <strong>Use this if:</strong> Your store was misconfigured. The amounts were always meant to be in {pendingCurrency}, 
                                        but were incorrectly labeled as {selectedStorefront?.currency}.
                                    </p>
                                </div>
                            </div>
                        </button>

                        {/* Option 2: Convert values */}
                        <button
                            type="button"
                            className="w-full rounded-[var(--shell-radius)} border border-[var(--shell-border)] p-4 text-left transition hover:border-[var(--color-brand)] hover:bg-[var(--color-brand-muted)]"
                            onClick={() => {
                                // For now, we keep values the same (conversion would require backend support)
                                setEdit((c) => ({ ...c, currency: pendingCurrency || '' }));
                                setShowCurrencyWarning(false);
                                setPendingCurrency(null);
                                toast.warning('Currency updated. Note: Conversion requires manual adjustment of existing orders.');
                            }}
                        >
                            <div className="flex items-start gap-3">
                                <Icon name="arrows-left-right" size={18} className="mt-0.5 shrink-0 text-[var(--color-info)]" />
                                <div className="flex-1">
                                    <p className="font-medium">Convert Existing Values</p>
                                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                        Example: {selectedStorefront?.currency} 131 → {pendingCurrency} ~1.09 (using exchange rate)
                                    </p>
                                    <p className="mt-2 text-xs text-[var(--color-text-subtle)]">
                                        <strong>Use this if:</strong> Your store actually changed currencies. The amounts in {selectedStorefront?.currency} 
                                        were correct, and need to be converted to {pendingCurrency} equivalent.
                                    </p>
                                    <p className="mt-2 text-xs text-[var(--color-warning)]">
                                        Note: Automatic conversion not yet implemented. Existing orders will keep their original values. 
                                        Consider this when reviewing historical data.
                                    </p>
                                </div>
                            </div>
                        </button>
                    </div>

                    <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-bg-subtle)] p-3">
                        <p className="text-xs text-[var(--color-text-muted)]">
                            <strong>Important:</strong> This only affects how existing orders are displayed. 
                            New orders will automatically use the currency from your connected store's API.
                        </p>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => {
                            setShowCurrencyWarning(false);
                            setPendingCurrency(null);
                        }}
                    >
                        Cancel
                    </button>
                </div>
            </Modal>
        </div>
    );
}

/** One fact, read-only. */
function Detail({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2">
            <dt className="text-[var(--color-text-muted)]">{label}</dt>
            <dd className="text-right font-medium">{value}</dd>
        </div>
    );
}
