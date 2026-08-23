import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Fragment, useEffect, useState, type CSSProperties } from 'react';

import { Icon } from '@/components/ui/Icon';
import { FieldOptions, type FieldOption } from '@/pages/storefront/FieldOptions';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

type PathOption = {
    path: string;
    sample: string | null;
    /** The shop's own name for it — "Date Created Gmt" rather than the path. */
    label?: string | null;
    /** The shop refuses writes to it, whether or not it says so at the time. */
    readonly?: boolean;
    /** The type the shop's description implies. */
    suggest?: string | null;
    /** The fixed set of values it accepts, when it has one. */
    choices?: FieldOption[];
    /** What the shop's documentation says this field is. */
    note?: string | null;
    /** For a place code, the name it stands for — 'BD-58' arrives with 'Satkhira'. */
    reads_as?: string | null;
    /** Described by the shop, but absent from every record read so far. */
    unused?: boolean;
};
type Target = { value: string; label: string; transform: string; custom?: boolean };
type MapRow = {
    source: string;
    target: string;
    transform: string;
    direction: string;
    label: string | null;
    enabled: boolean;
    also: string[];
    /** The choices, for a type that has any. */
    options: FieldOption[];
    /** Shown on the order or product edit screen. Never affects syncing. */
    visible: boolean;
    display_label?: string;
};

type Sample = {
    entity: string;
    paths: PathOption[];
    /** 'live' — a real record from this shop; 'sample' — the platform's; 'none'. */
    source: string;
    captured_at: string | null;
    targets: Target[];
    transforms: Record<string, string>;
    /** Types that are meaningless without choices — sent so this list cannot drift. */
    needs_options: string[];
    /** Types whose value is a picture or a file. */
    media_types: string[];
    /** Whether this shop was able to describe its own fields. */
    described: boolean;
    /** Types that are a dropdown this application already holds the choices for. */
    resolved_options?: string[];
    maps: MapRow[];
};

/** Chosen in the target list to define a field rather than pick one. */
const NEW_FIELD = '__new_field__';

/** The containers a shop keeps its own invented fields in. */
const CUSTOM_CONTAINERS = ['meta_data', 'note_attributes', 'metafields', 'custom_fields'];

/**
 * The sections a mapping row belongs to, in the order they are shown.
 *
 * ── Why group at all ─────────────────────────────────────────────────────────
 *
 * Forty rows in one list is forty rows to read before finding the one about a
 * delivery address. Grouped, it is six short lists with headings, and the
 * question somebody actually arrived with — "where does the customer's phone
 * number come from?" — is answered by looking in one place.
 *
 * The order is the order somebody thinks about an order in: what it is, what it
 * cost, who placed it, where it goes, what is in it, and then whatever this
 * particular shop adds on top.
 */
const GROUPS: Array<{ key: string; label: string; hint: string }> = [
    { key: 'record', label: 'The order itself', hint: 'Number, status, dates' },
    { key: 'money', label: 'Money', hint: 'Totals, tax, currency' },
    { key: 'customer', label: 'Customer', hint: 'Who placed it' },
    { key: 'billing', label: 'Billing address', hint: 'Where the invoice goes' },
    { key: 'delivery', label: 'Delivery address', hint: 'Where the goods go' },
    { key: 'items', label: 'Line items', hint: 'What was bought' },
    { key: 'custom', label: 'Shop fields', hint: "This shop's own fields" },
];

/**
 * Which section a row belongs in, from where its value lands.
 *
 * Decided on the target rather than the source, because the target is this
 * application's own vocabulary and is therefore the half that is consistent. A
 * shop is free to call its delivery postcode anything at all; where it lands is
 * always `shipping_postcode`.
 *
 * Custom fields are the exception and are grouped by the shop's own key prefix,
 * since that is the only structure they have — `billing_thana` belongs with the
 * billing address, not in a bucket of leftovers at the bottom.
 */
function groupFor(row: { target: string; source: string }): string {
    const target = row.target;

    if (target.startsWith('custom.')) {
        const key = (row.source.split('.').pop() ?? '').replace(/^_+/, '');

        if (key.startsWith('billing_')) return 'billing';
        if (key.startsWith('shipping_')) return 'delivery';

        return 'custom';
    }

    if (target.startsWith('customer.billing_')) return 'billing';
    if (target.startsWith('customer.')) return 'customer';
    if (target.startsWith('shipping_')) return 'delivery';
    if (target.startsWith('variant.')) return 'money';
    if (target.startsWith('line') || target.startsWith('items')) return 'items';

    if (
        target.endsWith('_minor') ||
        ['currency', 'is_cod', 'payment_status', 'tax_rate'].includes(target)
    ) {
        return 'money';
    }

    return 'record';
}

/**
 * Where a discovered field would be stored here.
 *
 * ── Why the leading underscore goes ──────────────────────────────────────────
 *
 * WordPress marks meta private by prefixing it, and the underscore carries no
 * meaning outside WordPress — a field called `_delivery_slot` there is a
 * delivery slot here.
 *
 * ── And why that has a consequence ───────────────────────────────────────────
 *
 * Dropping it makes `_order_source` and `order_source` land on the same target,
 * which is correct — WooCommerce plugins routinely write both, and they hold the
 * same value. It also means the two can never both be mapped, because a target
 * holds one rule. Whoever offers these has to check the target, not the path.
 */
function targetFor(path: string): string {
    return `custom.${(path.split('.').pop() ?? 'field').replace(/^_+/, '')}`;
}

/**
 * A first guess at what a field is, from the one value the shop sent.
 *
 * ── Why guess at all ─────────────────────────────────────────────────────────
 *
 * WooCommerce meta is a flat list of key and value. Nothing in it says that
 * Expected Delivery is a date — Woo keeps no registry of order meta, and the
 * plugins that add checkout fields each keep their definitions to themselves.
 * So either every discovered field starts life as a text box, or the value is
 * read and something better is offered.
 *
 * Deliberately conservative. A wrong guess is a text box where a date picker
 * belonged and is corrected in one click; a confident wrong guess is a date
 * picker refusing a value that was never a date.
 *
 * What it cannot do is spot a dropdown. One order shows one value, and a single
 * "facebook" is indistinguishable from free text — which is precisely why the
 * choices are typed in rather than inferred.
 */
function guessType(sample: string | null): string {
    const text = (sample ?? '').trim();

    if (text === '') return 'trim';

    if (/^https?:\/\/\S+$/i.test(text)) {
        if (/\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i.test(text)) return 'image';
        if (/\.(mp4|mov|webm|m4v)(\?|$)/i.test(text)) return 'video';
        if (/\.(pdf|docx?|xlsx?|csv|zip)(\?|$)/i.test(text)) return 'file';

        return 'url';
    }

    if (/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(text)) return 'email';

    // Both orders written, plus the ISO form. Day-first and month-first cannot
    // be told apart from a single value, so only the type is decided here — the
    // reading of it belongs to the transform, which knows the shop's locale.
    if (/^\d{4}-\d{2}-\d{2}$/.test(text) || /^\d{1,2}[/.-]\d{1,2}[/.-]\d{4}$/.test(text)) {
        return 'date';
    }

    if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(text)) return 'datetime';

    // Only the unambiguous words. '0' and '1' are left alone, because a
    // quantity of 1 turning into a switch is worse than a flag showing as a
    // number.
    if (/^(yes|no|true|false)$/i.test(text)) return 'boolean';

    if (/^#?[0-9a-f]{6}$/i.test(text)) return 'colour';

    if (/^-?\d+$/.test(text)) return 'integer';
    if (/^-?\d+\.\d+$/.test(text)) return 'decimal';

    if (/[\r\n]/.test(text) || text.length > 120) return 'textarea';

    return 'trim';
}

/*
 * Two, not three.
 *
 * The buyer's details arrive on the order — every platform repeats them there —
 * so they are mapped among the order's fields as "Customer — Email" and the like.
 * A separate customer tab meant configuring the same shop twice and then having
 * to work out which of the two an arriving order obeyed.
 */
const ENTITIES = [
    { key: 'order', label: 'Orders' },
    { key: 'product', label: 'Products' },
];

/**
 * Which of this shop's fields become which of ours.
 *
 * ── Why the source is a list and not a text box ──────────────────────────────
 *
 * Nobody knows offhand that their delivery slot lives at
 * `meta_data._delivery_slot` with a leading underscore. The paths offered here
 * are read out of a real record this shop sent — every one of them exists, with
 * the value found at it shown alongside — so mapping is recognising something
 * rather than recalling it, and a typo is impossible because nothing is typed.
 *
 * ── Custom fields ────────────────────────────────────────────────────────────
 *
 * A target of "Custom field" stores the value against the link between this
 * record and this shop, rather than on the record. That is deliberate: the same
 * product sold through two shops can carry a different value in each, and there
 * is no single true one to put on the product itself.
 */
export function FieldMapPanel({ connectionId }: { connectionId: string }) {
    const queryClient = useQueryClient();

    const [entity, setEntity] = useState('order');
    const [rows, setRows] = useState<MapRow[]>([]);

    /*
     * A filter, because the fastest route to one row among forty is to type
     * part of its name. Matched against both halves of the mapping and against
     * the value, so "satkhira" finds the district field by what is in it rather
     * than by what anybody decided to call it.
     */
    const [query, setQuery] = useState('');
    const [naming, setNaming] = useState<Record<number, string>>({});

    const { data, isLoading } = useQuery({
        queryKey: ['integration', connectionId, 'sample-paths', entity],
        queryFn: () =>
            api.get<{ data: Sample }>(`/settings/integrations/${connectionId}/sample-paths`, {
                params: { entity },
            }),
    });

    const sample = data?.data;

    useEffect(() => {
        if (sample) setRows(sample.maps);
    }, [sample]);

    /*
     * Naming a field this tool does not have.
     *
     * Typed in words — "Manage Stock" — because that is what it is called on
     * every screen afterwards. The stored key is derived from the name by the
     * server, so it never depends on how somebody happened to type it, and the
     * field becomes available to every shop this business connects rather than
     * to the one row it was invented on.
     */
    const defineField = useMutation({
        mutationFn: (payload: { label: string; type: string }) =>
            api.post<{ data: { target: string } }>(`/settings/integrations/${connectionId}/fields`, {
                ...payload,
                entity,
            }),
        onSuccess: (result, variables) => {
            toast.success(`${variables.label} added.`);
            void queryClient.invalidateQueries({
                queryKey: ['integration', connectionId, 'sample-paths', entity],
            });

            return result;
        },
        onError: (error: Error) => toast.error(error.message || 'That field could not be added.'),
    });

    const save = useMutation({
        mutationFn: () =>
            api.put<{ data: MapRow[]; skipped?: { refused: string[]; collided: string[] } }>(
                `/settings/integrations/${connectionId}/field-maps`,
                { entity, maps: rows },
            ),
        onSuccess: (result) => {
            /*
             * Say when less was kept than was sent.
             *
             * A save that stores fewer rows than it was given, and reports
             * success anyway, is indistinguishable from one that worked — until
             * the screen reloads and a row is missing, which reads as the
             * application losing work rather than refusing it.
             */
            const collided = result.skipped?.collided ?? [];
            const refused = result.skipped?.refused ?? [];

            if (collided.length > 0) {
                toast.error(
                    `Saved, but ${collided.length} row${collided.length === 1 ? '' : 's'} could not be kept — ` +
                        `these point at the same field here: ${collided.join('; ')}. ` +
                        'Give one of each pair a different field, or remove it.',
                );
            } else if (refused.length > 0) {
                toast.error(
                    `Saved, but ${refused.length} row${refused.length === 1 ? '' : 's'} had no field to write to: ` +
                        refused.join(', '),
                );
            } else {
                toast.success('Mapping saved.');
            }

            void queryClient.invalidateQueries({ queryKey: ['integration', connectionId] });
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    const update = (index: number, patch: Partial<MapRow>) =>
        setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    const remove = (index: number) => setRows((current) => current.filter((_, i) => i !== index));

    const add = () =>
        setRows((current) => [
            ...current,
            {
                source: '',
                target: '',
                transform: 'trim',
                direction: 'both',
                label: null,
                enabled: true,
                also: [],
                options: [],
                visible: true,
            },
        ]);

    /*
     * The custom fields this shop sends that nothing is reading yet.
     *
     * The standard fields need no suggesting — they are mapped by default and
     * already in the table. What is never mapped, and cannot be, is whatever
     * this particular shop's plugins added: meta_data, note_attributes,
     * metafields. Those are exactly the paths somebody came to this screen for,
     * and they are the ones nobody can guess the names of.
     *
     * Suggested, not applied: each becomes a row pointing at a custom field of
     * the same name, and nothing is saved until Save is pressed.
     */
    const suggestions = (() => {
        /*
         * Both what is mapped and where it lands.
         *
         * Checking only the source produced a button that could never be
         * finished. Every one of these shops writes its custom fields twice,
         * once plainly and once with a leading underscore, and both spellings
         * reduce to the same target. Offering the twin of something already
         * mapped meant saving it, having it evict the row that was there, and
         * finding the evicted one offered in its place — the same count, for
         * ever, with a field quietly lost on each round.
         */
        const mappedSources = new Set(rows.map((row) => row.source));
        const mappedTargets = new Set(rows.map((row) => row.target));

        return (sample?.paths ?? []).filter((option) => {
            const container = option.path.split('.')[0] ?? '';

            if (!CUSTOM_CONTAINERS.includes(container)) return false;
            if (mappedSources.has(option.path)) return false;

            // Already arriving under another name. Nothing to add.
            return !mappedTargets.has(targetFor(option.path));
        });
    })();

    const suggest = () =>
        setRows((current) => [
            ...current,
            ...suggestions.map((option) => ({
                ...{
                    // The shop's own word on the type and the choices, where it
                    // had one. Custom meta rarely does — no schema lists a
                    // plugin's invented keys — so this mostly falls through to
                    // the guess below, and costs nothing when it does.
                    transform: option.suggest ?? guessType(option.sample),
                    options: option.choices ?? [],
                    direction: option.readonly ? 'in' : 'both',
                },
                source: option.path,
                // Named after the field itself, minus any leading underscore —
                // WordPress hides its private meta that way and the underscore
                // means nothing here.
                target: targetFor(option.path),
                label: null,
                enabled: true,
                also: [],
                visible: true,
            })),
        ]);

    /*
     * Which types are asking for choices.
     *
     * Read from the response rather than listed here, so adding a type on the
     * server is the whole change. The fallback matters only for the moment
     * before the first response lands.
     */
    const needsOptions = (transform: string): boolean =>
        (sample?.needs_options ?? ['select', 'radio', 'checkbox']).includes(transform);

    /*
     * Country, district and area are dropdowns whose choices are already known.
     *
     * The same control as a select, with the opposite problem: there, nobody but
     * this shop's owner can supply the list; here, asking them to would be
     * asking them to type out 250 countries and 581 thanas that this application
     * is already holding.
     */
    const resolvesOptions = (transform: string): boolean =>
        (sample?.resolved_options ?? ['country', 'state', 'area']).includes(transform);

    /**
     * Rows aimed at a field another row already claims.
     *
     * Marked while editing rather than only on save, because the two rows are
     * usually far apart in a list of forty and the conflict is invisible until
     * something is lost. WordPress makes this ordinary: a shop writes
     * `order_source` and `_order_source`, both reduce to the same field here,
     * and only one of them can win.
     */
    const contested = (() => {
        const count = new Map<string, number>();

        for (const row of rows) {
            if (row.target === '' || row.target === NEW_FIELD) continue;
            count.set(row.target, (count.get(row.target) ?? 0) + 1);
        }

        return count;
    })();

    /*
     * The rows, filtered and then arranged into sections.
     *
     * Index is carried alongside each row rather than recomputed, because every
     * edit addresses a row by its position in the unfiltered list — reordering
     * for display must not change what a change applies to.
     */
    const visible = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => {
            const needle = query.trim().toLowerCase();

            if (needle === '') return true;

            const found = sample?.paths.find((p) => p.path === row.source);
            const label = sample?.targets.find((t) => t.value === row.target)?.label ?? '';

            return [row.source, row.target, label, found?.sample, found?.reads_as]
                .some((piece) => (piece ?? '').toLowerCase().includes(needle));
        });

    const grouped = GROUPS.map((group) => ({
        ...group,
        entries: visible.filter(({ row }) => groupFor(row) === group.key),
    })).filter((group) => group.entries.length > 0);

    /*
     * What the screen is showing, counted.
     *
     * Three numbers worth knowing before scrolling: how much is mapped at all,
     * how much of it will appear when somebody edits a record, and how many
     * rows are asking for something before they will work.
     */
    const summary = {
        mapped: rows.length,
        hidden: rows.filter((row) => row.visible === false).length,
        needing: rows.filter(
            (row) => needsOptions(row.transform) && (row.options?.length ?? 0) === 0,
        ).length,
    };

    /**
     * Where the column headings sit once the page scrolls.
     *
     * Directly beneath the toolbar, whose height it measured for itself — that
     * height changes when the shop-fields button appears or the counts wrap, so
     * a guessed offset would leave a gap on one screen and hide the first row on
     * another.
     */
    const headCell: CSSProperties = {
        position: 'sticky',
        top: 'var(--toolbar-height, 6rem)',
        zIndex: 10,
        background: 'var(--color-card-bg)',
    };

    /** What the shop said about the field this row reads from. */
    const described = (row: MapRow): PathOption | undefined =>
        sample?.paths.find((p) => p.path === row.source);

    /*
     * Address rows still stored as plain text.
     *
     * ── Why this is offered rather than done ─────────────────────────────────
     *
     * These mappings were saved before anything recognised an address, so they
     * hold BD-58 as text — not because anybody chose that, but because text was
     * all there was. Changing them is almost certainly right.
     *
     * Almost is the problem. A saved mapping is somebody's decision until proved
     * otherwise, and a screen that silently rewrites decisions on load is one
     * nobody can trust with the ones they made deliberately. So the offer is
     * made, the count is stated, and it takes a click and a save — which is two
     * more actions than doing it quietly and the difference between a tool that
     * helps and one that meddles.
     */
    const upgradable = rows
        .map((row, index) => ({ row, index, place: described(row)?.suggest }))
        .filter(({ row, place }) =>
            place !== undefined &&
            place !== null &&
            ['country', 'state', 'area'].includes(place) &&
            row.transform !== place);

    const upgradePlaces = () =>
        setRows((current) =>
            current.map((row, i) => {
                const found = upgradable.find((u) => u.index === i);

                return found ? { ...row, transform: found.place as string } : row;
            }),
        );


    /*
     * Everything the shop's description implies for a row that points at it.
     *
     * ── Why the direction is forced ──────────────────────────────────────────
     *
     * A read-only field is not a mapping that fails loudly. WooCommerce accepts
     * the write, answers 200, and discards it — which is exactly how product
     * prices appeared to sync for an afternoon while every one of them was
     * being thrown away. If the shop says it will not take a value, the only
     * honest direction is inbound.
     *
     * ── Why the type is only a default ───────────────────────────────────────
     *
     * Applied when the row is still on the untouched default, and left alone
     * otherwise. Somebody who set a field to Textarea did so deliberately, and
     * a description arriving later must not quietly undo them.
     */
    const applyDescription = (row: MapRow, option: PathOption | undefined): Partial<MapRow> => {
        if (!option) return {};

        const patch: Partial<MapRow> = {};

        if (option.readonly) patch.direction = 'in';

        if (option.suggest && row.transform === 'trim') patch.transform = option.suggest;

        // The one case where choices need not be typed: the shop stated them.
        if (option.choices?.length && !row.options?.length) patch.options = option.choices;

        return patch;
    };

    // What the sample value becomes once the row's transform has run. Shown so a
    // wrong mapping is visible before it is saved rather than after a sync.
    const preview = (row: MapRow): string => {
        const found = sample?.paths.find((p) => p.path === row.source);

        return found?.sample ?? '—';
    };

    return (
        <div className="space-y-4">
            {/*
              The toolbar sticks, because the alternative is scrolling back to
              the top of forty rows to save the change just made at the bottom.
            */}
            <div
                className="sticky top-0 z-20 -mx-1 space-y-3 px-1 pb-3 pt-1"
                style={{ background: 'var(--color-card-bg)' }}
                ref={(node) => {
                    /*
                     * The toolbar measures itself, and the column headings stick
                     * directly beneath it.
                     *
                     * Its height is not a constant worth guessing at: it changes
                     * when the shop-fields button appears, when the counts wrap
                     * on a narrow panel, when a longer label pushes to two
                     * lines. A guessed offset would leave a gap on one screen
                     * and hide the first row on another, so it is read from the
                     * element that actually has it.
                     */
                    if (node) {
                        node.style.setProperty(
                            '--toolbar-height',
                            `${Math.round(node.getBoundingClientRect().height)}px`,
                        );
                        node.parentElement?.style.setProperty(
                            '--toolbar-height',
                            `${Math.round(node.getBoundingClientRect().height)}px`,
                        );
                    }
                }}
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {/* A segmented control rather than two bordered buttons:
                        these are two views of one screen, not two actions. */}
                    <div
                        className="inline-flex rounded-[var(--shell-radius)] border p-0.5"
                        style={{ borderColor: 'var(--shell-border)' }}
                    >
                        {ENTITIES.map((option) => (
                            <button
                                key={option.key}
                                type="button"
                                onClick={() => setEntity(option.key)}
                                className="rounded-[var(--shell-radius-sm)] px-3 py-1 text-sm font-medium transition"
                                style={
                                    entity === option.key
                                        ? {
                                              background: 'var(--color-brand)',
                                              color: 'var(--color-text-on-accent)',
                                          }
                                        : { color: 'var(--color-text-muted)' }
                                }
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Only offered when there is something to offer — a
                            button that does nothing when pressed teaches people
                            to stop pressing buttons. */}
                        {suggestions.length > 0 && (
                            <button type="button" className="btn btn-secondary" onClick={suggest}>
                                <Icon name="sparkle" size={13} />
                                Add {suggestions.length} shop field
                                {suggestions.length === 1 ? '' : 's'}
                            </button>
                        )}

                        <button type="button" className="btn btn-secondary" onClick={add}>
                            <Icon name="plus" size={13} />
                            Add row
                        </button>
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

                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative min-w-[14rem] flex-1">
                        <Icon
                            name="magnifying-glass"
                            size={13}
                            className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 opacity-50"
                        />
                        <input
                            className="field w-full pl-8 text-sm"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Find a field — by its name here, its name there, or its value"
                            aria-label="Filter the mapping"
                        />
                    </div>

                    {/*
                      Three counts, stated before anybody scrolls.

                      The third is the one that matters: a dropdown with no
                      choices behind it looks configured and behaves as a text
                      box, and this is the only place that difference is visible
                      without opening every row.
                    */}
                    <div className="flex items-center gap-3 text-xs text-[var(--color-text-muted)]">
                        <span>{summary.mapped} mapped</span>

                        {summary.hidden > 0 && (
                            <span className="flex items-center gap-1">
                                <Icon name="eye" size={11} />
                                {summary.hidden} hidden when editing
                            </span>
                        )}

                        {summary.needing > 0 && (
                            <span
                                className="flex items-center gap-1"
                                style={{ color: 'var(--color-warning-text)' }}
                            >
                                <Icon name="warning" size={11} />
                                {summary.needing} need choices
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/*
              Where the paths came from. A mapping built against the platform's
              documented sample rather than a real record deserves a second look
              once orders start arriving, so the screen says which it is.
            */}
            {/*
              What this shop was able to say for itself.

              Worth stating plainly, because the difference is large and
              otherwise invisible: a shop that describes its own fields offers
              every one it has, including the ones no record has used, while a
              shop that cannot offers only what has actually been through it.
            */}
            {/*
              The offer, with the count, so it is clear what would change.
            */}
            {upgradable.length > 0 && (
                <div
                    className="flex flex-wrap items-center justify-between gap-2 rounded-[var(--shell-radius)] border p-2.5"
                    style={{ borderColor: 'var(--shell-border)' }}
                >
                    <p className="text-xs text-[var(--color-text-muted)]">
                        {upgradable.length} address field
                        {upgradable.length === 1 ? ' is' : 's are'} stored as plain text, so
                        they show as codes like <code>BD-58</code> instead of names like{' '}
                        <code>Satkhira</code>.
                    </p>

                    <button type="button" className="btn btn-secondary" onClick={upgradePlaces}>
                        <Icon name="sparkle" size={13} />
                        Show names — then Save
                    </button>
                </div>
            )}

            {sample?.described && (
                <p className="flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
                    <Icon name="check" size={12} />
                    This shop describes its own fields, so everything it has is listed below —
                    including fields no order or product has used yet.
                </p>
            )}

            {sample && sample.source !== 'live' && (
                <p className="text-xs text-[var(--color-text-muted)]">
                    {sample.source === 'sample'
                        ? 'Fields below come from a standard example — this shop has not sent a record yet. They will work; check them once real orders arrive.'
                        : 'Nothing to read fields from yet. Sync once, or wait for this shop to send something.'}
                </p>
            )}

            {isLoading && <div className="h-40 animate-pulse rounded-[var(--shell-radius)] bg-[var(--shell-muted)]" />}

            {/*
              Horizontal scrolling only where it is needed.

              `overflow-x: auto` computes `overflow-y: auto` alongside it, and
              that makes a scroll container — which silently stops the column
              headings sticking, because sticky positions against the nearest
              scrolling ancestor and that ancestor never scrolls vertically.

              The table is `w-full` with a 40rem floor, so it only genuinely
              overflows on a panel narrower than that. Above the breakpoint the
              wrapper gets out of the way and the headings pin properly; below
              it, scrolling sideways matters more than pinned headings do.
            */}
            {sample && (
                <div className="overflow-x-auto rounded-[var(--shell-radius)] md:overflow-visible">
                    {/*
                      Fixed layout, with the widths stated.

                      Left to size itself, a table gives each column whatever its
                      widest content asks for — and one path in this shop is
                      `meta_data._wc_order_attribution_session_start_time`, which
                      claimed 360px of a 870px panel and pushed two columns off
                      the edge. `truncate` cannot prevent that: it hides overflow
                      inside a box whose width the content had already decided.

                      Stated widths make truncation mean something, and mean the
                      table is the same shape whichever shop is being mapped.
                    */}
                    <table
                        className="table table-framed w-full min-w-[40rem] table-fixed"
                        /*
                         * ── The one line that makes the headings pin ─────────
                         *
                         * `.table-framed` sets `overflow: hidden` on the table,
                         * to clip its rows inside its rounded corners. A box
                         * with overflow other than visible is a scroll
                         * container, and a sticky element positions against the
                         * nearest one — so every heading cell was sticking to
                         * the table itself, which never scrolls.
                         *
                         * The failure gave no sign of itself: position computed
                         * as sticky, top computed to the right offset, and the
                         * row scrolled away regardless. A plain sticky div in
                         * the same place pinned perfectly, which is what
                         * narrowed it to the table.
                         *
                         * The corners are rounded by the wrapper instead, which
                         * costs nothing — the rows have no background of their
                         * own to spill past them.
                         */
                        style={{ overflow: 'visible' }}
                    >
                        <colgroup>
                            <col style={{ width: '29%' }} />
                            <col style={{ width: '22%' }} />
                            <col style={{ width: '19%' }} />
                            <col style={{ width: '11%' }} />
                            <col style={{ width: '11%' }} />
                            <col style={{ width: '7%' }} />
                        </colgroup>

                        {/*
                          Pinned under the toolbar.

                          Six columns of dropdowns look much alike once the
                          headings scroll away, and "Becomes" and "Treated as"
                          are not guessable from their contents — both are a
                          select of words. Forty rows is far enough to lose them.
                        */}
                        {/*
                          Pinned under the toolbar — on the cells, not the row.

                          A sticky <thead> is honoured by some engines and
                          quietly ignored by others, and the failure is silent:
                          position computes as sticky, top computes to the right
                          offset, and the row scrolls away regardless. Sticky
                          <th> is the form that has always worked, because a cell
                          is an ordinary box where a section of a table is not.

                          Worth having at all because six columns of dropdowns
                          look much alike once the headings are gone, and
                          "Becomes" and "Treated as" are not guessable from their
                          contents — both are a select full of words.
                        */}
                        <thead>
                            <tr>
                                {/*
                                  The field and what it currently holds share a
                                  column.

                                  They were separate, and together they cost
                                  nearly a third of the width — on a 900px panel
                                  that pushed Direction off the right edge, so
                                  the column saying which way a value travels was
                                  invisible while choosing where it travels to.
                                  Stacked, they read as one thing, which is what
                                  they are: this shop's field, and its value.
                                */}
                                <th style={headCell}>This shop&rsquo;s field</th>
                                <th style={headCell}>Becomes</th>
                                <th style={headCell}>Treated as</th>
                                <th style={headCell}>Way</th>
                                {/* Not "Enabled". Every row here syncs; this
                                    governs only whether somebody editing an
                                    order is shown a box for it. */}
                                <th className="text-center" style={headCell}>
                                    On edit page
                                </th>
                                <th style={headCell} />
                            </tr>
                        </thead>

                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center text-[var(--color-text-muted)]">
                                        Nothing mapped yet.
                                    </td>
                                </tr>
                            )}

                            {/*
                              Nothing matched the filter — said plainly, because
                              an empty table with a full search box otherwise
                              reads as the mapping having been lost.
                            */}
                            {rows.length > 0 && grouped.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center text-[var(--color-text-muted)]">
                                        No field matches &ldquo;{query}&rdquo;.
                                    </td>
                                </tr>
                            )}

                            {grouped.map((group) => (
                                <Fragment key={group.key}>
                                    {/*
                                      A heading row rather than a separate table
                                      per section, so every column stays aligned
                                      down the whole screen — six tables side by
                                      side would each size their own columns and
                                      nothing would line up.
                                    */}
                                    <tr>
                                        <th
                                            colSpan={6}
                                            className="!py-2 text-left"
                                            style={{ background: 'var(--shell-tint)' }}
                                        >
                                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-main)]">
                                                {group.label}
                                            </span>
                                            <span className="ml-2 text-[11px] font-normal text-[var(--color-text-muted)]">
                                                {group.hint} · {group.entries.length}
                                            </span>
                                        </th>
                                    </tr>

                                    {group.entries.map(({ row, index }) => (
                                    <Fragment key={index}>
                                    <tr>
                                        <td>
                                            <select
                                                className="field w-full"
                                                value={row.source}
                                                onChange={(e) => {
                                                    const source = e.target.value;
                                                    const option = sample.paths.find(
                                                        (p) => p.path === source,
                                                    );

                                                    update(index, {
                                                        source,
                                                        ...applyDescription(row, option),
                                                    });
                                                }}
                                            >
                                                <option value="">—</option>
                                                {/* A path already mapped but no
                                                    longer present in the sample
                                                    still shows, or the row would
                                                    silently blank itself. */}
                                                {!sample.paths.some((p) => p.path === row.source) &&
                                                    row.source !== '' && (
                                                        <option value={row.source}>{row.source}</option>
                                                    )}
                                                {sample.paths.map((option) => (
                                                    <option key={option.path} value={option.path}>
                                                        {option.path}
                                                        {/* Marked, because a field with no value
                                                            beside it looks broken otherwise. It is
                                                            not — this shop has simply never had a
                                                            coupon, or a variable product. */}
                                                        {option.unused ? ' — not used yet' : ''}
                                                        {option.readonly ? ' — read-only' : ''}
                                                    </option>
                                                ))}
                                            </select>

                                            {/*
                                              What the field holds right now,
                                              under the field it belongs to.
                                            */}
                                            <div
                                                className="mt-1 truncate text-[11px] text-[var(--color-text-muted)]"
                                                title={described(row)?.note ?? undefined}
                                            >
                                                {described(row)?.reads_as ? (
                                                    <>
                                                        {/*
                                                          The place, read as a
                                                          place. BD-58 is not
                                                          something anybody can
                                                          check at a glance, and
                                                          recognising these
                                                          fields exists so that
                                                          nobody has to. The code
                                                          stays beside it because
                                                          it is what the shop
                                                          actually stores.
                                                        */}
                                                        <span className="text-[var(--color-text-body)]">
                                                            {described(row)?.reads_as}
                                                        </span>
                                                        <code className="ml-1.5 text-[10px] opacity-60">
                                                            {preview(row)}
                                                        </code>
                                                    </>
                                                ) : described(row)?.unused ? (
                                                    // Not a fault — the shop
                                                    // describes a field no record
                                                    // has used, which is why it
                                                    // can be mapped before the
                                                    // first coupon exists.
                                                    <span className="italic opacity-70">
                                                        described, not used yet
                                                    </span>
                                                ) : (
                                                    preview(row)
                                                )}
                                            </div>
                                        </td>


                                        <td>
                                            <select
                                                className="field w-full"
                                                value={row.target}
                                                onChange={(e) => {
                                                    const target = e.target.value;
                                                    const known = sample.targets.find((t) => t.value === target);

                                                    // The right treatment for the
                                                    // chosen field, so money and
                                                    // dates are handled without
                                                    // anyone having to know.
                                                    update(index, {
                                                        target,
                                                        transform: known?.transform ?? row.transform,
                                                    });
                                                }}
                                            >
                                                <option value="">—</option>
                                                {sample.targets.map((target) => (
                                                    <option key={target.value} value={target.value}>
                                                        {target.label}
                                                    </option>
                                                ))}
                                                <option value={NEW_FIELD}>＋ New field…</option>
                                            </select>

                                            {(contested.get(row.target) ?? 0) > 1 && (
                                                <div
                                                    className="mt-1 flex items-start gap-1 text-[11px] leading-tight"
                                                    style={{ color: 'var(--color-danger, #b91c1c)' }}
                                                >
                                                    <Icon name="warning" size={11} className="mt-0.5 shrink-0" />
                                                    <span>
                                                        Another row already writes to this field. Only one
                                                        will be kept.
                                                    </span>
                                                </div>
                                            )}

                                            {/*
                                              A custom field needs a name of its
                                              own. Left to the path it came from,
                                              two shops sending the same thing
                                              under different keys would store it
                                              under two names and no report could
                                              bring them together.
                                            */}
                                            {row.target === NEW_FIELD && (
                                                <input
                                                    className="field mt-1.5 w-full text-xs"
                                                    value={naming[index] ?? ''}
                                                    onChange={(e) =>
                                                        setNaming((c) => ({ ...c, [index]: e.target.value }))
                                                    }
                                                    onKeyDown={(e) => {
                                                        if (e.key !== 'Enter') return;
                                                        e.preventDefault();

                                                        const label = (naming[index] ?? '').trim();

                                                        if (label === '') return;

                                                        defineField.mutate(
                                                            { label, type: row.transform },
                                                            {
                                                                onSuccess: (result) => {
                                                                    // Point this row at the field it just
                                                                    // created, so pressing Enter finishes
                                                                    // the job rather than starting another.
                                                                    update(index, { target: result.data.target });
                                                                    setNaming((c) => ({ ...c, [index]: '' }));
                                                                },
                                                            },
                                                        );
                                                    }}
                                                    placeholder="Manage Stock — then press Enter"
                                                    aria-label="Name this field"
                                                    autoFocus
                                                />
                                            )}
                                        </td>

                                        <td>
                                            <select
                                                className="field w-full"
                                                value={row.transform}
                                                onChange={(e) => update(index, { transform: e.target.value })}
                                            >
                                                {Object.entries(sample.transforms).map(([value, label]) => (
                                                    <option key={value} value={value}>
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>

                                        <td>
                                            {/*
                                              Shortened, and titled.

                                              "Bring in only" spelled out cost
                                              more width than the column it sat
                                              in had to give. The arrows say the
                                              same thing in a quarter of the
                                              space, and the full wording is one
                                              hover away for anybody who has not
                                              met them before.
                                            */}
                                            <select
                                                className="field w-full"
                                                value={row.direction}
                                                onChange={(e) => update(index, { direction: e.target.value })}
                                                title={
                                                    row.direction === 'in'
                                                        ? 'Bring in only — changes here are never sent back'
                                                        : row.direction === 'out'
                                                          ? 'Send out only — changes there are never brought in'
                                                          : 'Both ways'
                                                }
                                                aria-label="Which way this field travels"
                                            >
                                                <option value="both">⇄ Both</option>
                                                <option value="in">← In</option>
                                                <option value="out">→ Out</option>
                                            </select>
                                        </td>

                                        <td className="text-center">
                                            {/*
                                              Checked unless somebody says
                                              otherwise.

                                              A shop sends its plugin's
                                              bookkeeping and audit trails
                                              alongside the eight fields a
                                              person actually fills in, and
                                              starting everything hidden means
                                              an edit screen that shows nothing
                                              until each field is found and
                                              ticked. Starting everything shown
                                              means a screen that is complete
                                              from the first sync and gets
                                              tidier as the noise is unticked.
                                            */}
                                            <input
                                                type="checkbox"
                                                checked={row.visible !== false}
                                                onChange={(e) =>
                                                    update(index, { visible: e.target.checked })
                                                }
                                                aria-label={`Show ${
                                                    row.display_label ?? (row.source || 'this field')
                                                } when editing`}
                                                className="size-4 cursor-pointer align-middle accent-[var(--color-brand)]"
                                            />
                                        </td>

                                        <td className="text-right">
                                            <button
                                                type="button"
                                                className="btn btn-secondary"
                                                onClick={() => remove(index)}
                                                aria-label="Remove row"
                                            >
                                                <Icon name="trash" size={13} />
                                            </button>
                                        </td>
                                    </tr>

                                    {/*
                                      A second row, only for the types that need
                                      one.

                                      Under the mapping rather than inside its
                                      cell, because a list of choices grows and
                                      a table column does not — twelve delivery
                                      areas inside a 10rem cell is a scrollbar
                                      nobody finds.
                                    */}
                                    {needsOptions(row.transform) && (
                                        <tr>
                                            <td />
                                            <td colSpan={5} className="pt-0">
                                                <FieldOptions
                                                    value={row.options ?? []}
                                                    onChange={(options) => update(index, { options })}
                                                />
                                            </td>
                                        </tr>
                                    )}

                                    {/*
                                      Said rather than left blank, because a
                                      dropdown with no Choices box beside it
                                      looks like one somebody forgot to fill in.
                                    */}
                                    {resolvesOptions(row.transform) && (
                                        <tr>
                                            <td />
                                            <td colSpan={5} className="pt-0">
                                                <p className="flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
                                                    <Icon name="check" size={12} />
                                                    {row.transform === 'country'
                                                        ? 'Chosen from 250 countries — nothing to enter.'
                                                        : row.transform === 'state'
                                                          ? 'Chosen from the districts of whichever country the address names.'
                                                          : 'Chosen from the areas within whichever district the address names.'}
                                                </p>
                                            </td>
                                        </tr>
                                    )}
                                    </Fragment>
                                    ))}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
