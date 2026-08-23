import { useState, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';


import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    StatusBadge,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { FieldMapPanel } from '@/pages/storefront/FieldMapPanel';
import { StatusMapPanel } from '@/pages/storefront/StatusMapPanel';
import { useMoney } from '@/hooks/useMoney';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

// Inline sync button with dropdown
function InlineSyncButton({ 
    storeId, 
    isOpen, 
    onToggle,
    onSync, 
    isPending,
}: { 
    storeId: string; 
    isOpen: boolean; 
    onToggle: () => void;
    onSync: (full: boolean) => void;
    isPending: boolean;
}) {
    const buttonRef = useRef<HTMLButtonElement>(null);
    
    const buttonRect = buttonRef.current?.getBoundingClientRect();
    const dropdownHeight = 120;
    const spaceBelow = buttonRect ? window.innerHeight - buttonRect.bottom : 999;
    const spaceAbove = buttonRect?.top ?? 0;
    const showAbove = isOpen && spaceBelow < dropdownHeight && spaceAbove > spaceBelow;

    return (
        <div onClick={(e) => e.stopPropagation()}>
            <button
                ref={buttonRef}
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle();
                }}
                disabled={isPending}
            >
                <Icon
                    name={isPending ? 'spinner' : 'arrows-clockwise'}
                    size={12}
                    className={isPending ? 'animate-spin' : undefined}
                />
                <Icon name="caret-down" size={10} />
            </button>
            
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
        mutationFn: () => api.delete(`/storefronts/${selectedStorefront?.id}`),
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

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['storefronts', { search, typeFilter, statusFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<StorefrontsResponse>('/storefronts', {
                params: {
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
    const { format: formatMoney } = useMoney();


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
                    description="Manage multiple storefronts for different brands, regions, or categories"
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

            {summary && (
                <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                        value={summary.total_products.toLocaleString()}
                        icon="package"
                        variant="info"
                    />
                    <KPICard
                        label="Total Revenue"
                        value={formatMoney(summary.total_revenue)}
                        icon="currency-dollar"
                        variant="success"
                    />
                </div>
            )}

            <div className="mt-6">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search storefronts..."
                    filters={
                        <>
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
                                    onClick={handleClearFilters}
                                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                >
                                    <Icon name="x" size={14} />
                                    <span>Clear</span>
                                </button>
                            )}
                        </>
                    }
                />
            </div>

            <div className="flex-1 overflow-auto pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">Failed to load storefronts.</p>
                        <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        <Table
                            data={storefronts}
                            loading={isLoading}
                            skeletonRows={10}
                            columns={[
                                {
                                    key: 'name',
                                    label: 'Storefront',
                                    render: (store) => (
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">{store.name}</p>
                                            <p className="mt-0.5 text-sm text-[var(--color-brand)]">{store.domain}</p>
                                        </div>
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
                                    render: (store) => (
                                        <StatusBadge
                                            label={statusLabels[store.status] ?? store.status}
                                            variant={statusVariants[store.status] ?? 'neutral'}
                                            dot
                                        />
                                    ),
                                },
                                {
                                    key: 'actions',
                                    label: '',
                                    align: 'right',
                                    render: (store) => store.is_connected ? (
                                        <InlineSyncButton
                                            storeId={store.id}
                                            isOpen={inlineSyncMenu === store.id}
                                            onToggle={() => setInlineSyncMenu(inlineSyncMenu === store.id ? null : store.id)}
                                            onSync={(full) => syncShop.mutate({ id: store.id, full })}
                                            isPending={syncShop.isPending}
                                        />
                                    ) : null,
                                },
                            ]}
                            sortBy={sortBy}
                            sortDirection={sortDirection}
                            onSort={handleSort}
                            onRowClick={(store) => setSelectedId(store.id)}
                            clickable
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
                 * Wider for the two tabs that are grids rather than summaries.
                 *
                 * Details is a dozen labelled values and reads well narrow.
                 * Field mapping is forty rows of five decisions each, and at
                 * the narrower width it scrolled sideways — which on a mapping
                 * screen means the column saying where a value lands is off
                 * screen while you choose where it comes from.
                 */
                size={storeTab === 'details' ? 'md' : 'xl'}
            >
                {selectedStorefront && (
                    <div className="space-y-5">
                        {/*
                          Mapping lives here, in the shop's own drawer, because
                          it is about this shop: its field names, its statuses,
                          its custom fields. A business with three shops maps
                          three different sets.
                        */}
                        {selectedStorefront.is_connected && selectedStorefront.connection_id && (
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--shell-border)] pb-3">
                                <div className="flex flex-wrap gap-1.5">
                                {(['details', 'fields', 'statuses'] as const).map((key) => (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() => setStoreTab(key)}
                                        className="rounded-[var(--shell-radius)] border px-3 py-1.5 text-sm transition"
                                        style={{
                                            borderColor:
                                                storeTab === key ? 'var(--color-brand)' : 'var(--shell-border)',
                                            color: storeTab === key ? 'var(--color-brand)' : undefined,
                                        }}
                                    >
                                        {key === 'details'
                                            ? 'Details'
                                            : key === 'fields'
                                              ? 'Field mapping'
                                              : 'Statuses'}
                                    </button>
                                ))}
                                </div>

                                {/*
                                  Sync sits with the tabs, not in the details
                                  pane, because it is what the other two tabs
                                  are for: field mapping reads its paths from a
                                  real record, and the status list is learned
                                  from real orders. Buried under Details, the
                                  one button that fetches them was unreachable
                                  from the screens that need them.
                                  
                                  Dropdown provides Incremental and Full sync:
                                  - Incremental: Only new/modified since last sync
                                  - Full: All records from beginning (for recovery)
                                */}
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
                                            <div
                                                className="fixed inset-0 z-10"
                                                onClick={() => setShowSyncMenu(false)}
                                            />
                                            <div className="absolute right-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] shadow-lg">
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
                            </div>
                        )}

                        {storeTab === 'fields' && selectedStorefront.connection_id && (
                            <FieldMapPanel connectionId={selectedStorefront.connection_id} />
                        )}

                        {storeTab === 'statuses' && selectedStorefront.connection_id && (
                            <StatusMapPanel connectionId={selectedStorefront.connection_id} />
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
                                                    dot
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
                                        onClick={() => removeShop.mutate()}
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
