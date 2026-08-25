import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';

export type OrderLine = {
    id?: number | null;
    variant_id?: number | null;
    sku?: string | null;
    description: string;
    quantity: number;
    unit_price: number;
    total?: number;

    /** The product's picture, from this application or from the shop. */
    image?: string | null;

    /**
     * What the product normally sells for, per unit.
     *
     * Null for a line typed by hand with no product behind it, which is then
     * taken to have been sold at its own price — see OrderTotals, which works
     * the order's discount out of the gap between these two.
     */
    list_price?: number | null;
};

type Variant = {
    id: number;
    sku: string | null;
    name: string;
    price: number;
    currency: string | null;
    image?: string | null;
};

/**
 * What was bought, and what it cost.
 *
 * ── Why this belongs in the order editor ─────────────────────────────────────
 *
 * Because an order *is* its lines. Everything else on the form is context
 * around them — who it goes to, what state it is in — and a screen that edits
 * all of that while leaving the lines alone can correct the paperwork around a
 * sale but not the sale. Adding a forgotten item, fixing a price typed wrong,
 * removing something out of stock: those are the ordinary reasons an order
 * needs editing at all.
 *
 * ── Why the amount is shown but never typed ──────────────────────────────────
 *
 * A line's amount is its quantity times its price. Offering it as a field would
 * let the three disagree, and a line whose own arithmetic does not hold is one
 * nobody can check. The server derives it again on save for the same reason.
 */
export function LineItems({
    lines,
    currency,
    symbol,
    onChange,
}: {
    lines: OrderLine[];
    currency: string;
    symbol: string;
    onChange: (next: OrderLine[]) => void;
}) {
    /*
     * ── What the picker is open for ─────────────────────────────────────────
     *
     * A row index means "change the product on this line"; 'new' means "add
     * one"; null means closed. One picker for both, because they are the same
     * question — which product? — and two would drift.
     */
    const [picking, setPicking] = useState<number | 'new' | null>(null);
    const [term, setTerm] = useState('');

    /*
     * Lines whose description was typed rather than chosen.
     *
     * A line's title is not a text box any more — it names a product, and a
     * product is picked. But not everything sold is in the catalogue: a
     * one-off, a delivery surcharge, something entered before the product
     * existed. Those keep a text box, and this remembers which they are so the
     * box does not turn back into a button on the next render.
     */
    const [freeText, setFreeText] = useState<Set<number>>(new Set());

    const box = useRef<HTMLDivElement>(null);

    const { data: found, isFetching } = useQuery<{ data: Variant[] }>({
        queryKey: ['catalogue', term],
        queryFn: ({ signal }) =>
            api.get(`/orders/catalogue/search?q=${encodeURIComponent(term)}`, { signal }),
        enabled: picking !== null,
        staleTime: 30_000,
    });

    useEffect(() => {
        if (picking === null) {
            return;
        }

        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setPicking(null);

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [picking]);

    const money = (value: number) =>
        `${symbol}${value.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;

    const patch = (index: number, changes: Partial<OrderLine>) => {
        onChange(lines.map((line, i) => (i === index ? { ...line, ...changes } : line)));
    };

    /** Everything about a line that comes from the product behind it. */
    const fromVariant = (variant?: Variant) => ({
        variant_id: variant?.id ?? null,
        sku: variant?.sku ?? null,
        description: variant?.name ?? '',
        image: variant?.image ?? null,
        list_price: variant?.price ?? null,
        unit_price: variant?.price ?? 0,
    });

    /**
     * Put a product on a line, or on a new one.
     *
     * ── Why the price comes with it ──────────────────────────────────────────
     *
     * Changing the product on a line and leaving the old product's price there
     * is the kind of mistake that reaches a customer. The quantity is kept,
     * because "three of these, no — three of those" is the actual thought; the
     * price is the new product's, because it is a different thing now.
     *
     * Anything typed over that afterwards is a deliberate discount, and shows
     * up as one against the list price this brings with it.
     */
    const choose = (variant?: Variant) => {
        if (picking === 'new') {
            onChange([...lines, { id: null, quantity: 1, ...fromVariant(variant) }]);

            if (variant === undefined) {
                // A line with no product behind it needs somewhere to type what
                // it is, so it opens as a text box.
                setFreeText((current) => new Set(current).add(lines.length));
            }
        } else if (picking !== null) {
            const row = picking;

            onChange(lines.map((line, i) => (i === row ? { ...line, ...fromVariant(variant) } : line)));

            setFreeText((current) => {
                const next = new Set(current);

                variant === undefined ? next.add(row) : next.delete(row);

                return next;
            });
        }

        setPicking(null);
        setTerm('');
    };


    /**
     * The catalogue, opened where it was asked for.
     *
     * ── Why it expands in place rather than floating ─────────────────────────
     *
     * This lives inside a scrolling column, and an absolutely positioned panel
     * is clipped by it — which is exactly what happened the first time: the
     * list appeared cut off at the top of the card. Expanding in place cannot
     * be clipped, needs no measuring, and pushes the rows down rather than
     * covering the line somebody is comparing against.
     */
    const catalogue = (
        <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] p-2">
            <input
                autoFocus
                className="field mb-2 w-full"
                placeholder="Search your products by name or SKU…"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
            />

            <div className="max-h-56 overflow-y-auto rounded-[var(--shell-radius-sm)] bg-[var(--color-card-bg)]">
                {(found?.data ?? []).map((variant) => (
                    <button
                        key={variant.id}
                        type="button"
                        className="flex w-full items-center gap-3 border-b border-[var(--shell-border)] px-3 py-2 text-left text-sm last:border-0 hover:bg-[var(--shell-hover)]"
                        onClick={() => choose(variant)}
                    >
                        {/* The picture here too: picking the right one of four
                            similar names is easier by sight than by reading. */}
                        <span
                            className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-[var(--shell-radius-sm)] border bg-[var(--color-site-bg)]"
                            style={{ borderColor: 'var(--shell-border)' }}
                        >
                            {variant.image ? (
                                <img
                                    src={variant.image}
                                    alt=""
                                    loading="lazy"
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <Icon
                                    name="image"
                                    size={12}
                                    className="text-[var(--color-text-subtle)]"
                                />
                            )}
                        </span>

                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[var(--color-text-body)]">
                                {variant.name}
                            </span>
                            {variant.sku && (
                                <span className="block font-mono text-[11px] text-[var(--color-text-subtle)]">
                                    {variant.sku}
                                </span>
                            )}
                        </span>

                        <span className="shrink-0 tabular-nums text-[var(--color-text-muted)]">
                            {money(variant.price)}
                        </span>
                    </button>
                ))}

                {(found?.data ?? []).length === 0 && (
                    <p className="px-3 py-4 text-center text-xs text-[var(--color-text-subtle)]">
                        {isFetching ? 'Searching…' : 'Nothing matches.'}
                    </p>
                )}
            </div>

            {/*
              Not everything sold is in the catalogue — a one-off, a surcharge,
              something entered before the product existed. Refusing those would
              make the picker a wall rather than a shortcut.
            */}
            <button
                type="button"
                className="mt-2 w-full px-1 text-left text-xs text-[var(--color-text-muted)] hover:text-[var(--color-brand)]"
                onClick={() => choose()}
            >
                {picking === 'new'
                    ? 'Add a line that is not in the catalogue'
                    : 'Describe this line myself instead'}
            </button>
        </div>
    );

    const subtotal = lines.reduce((sum, line) => sum + line.quantity * line.unit_price, 0);

    /*
     * A cell input that does not look like one until it is wanted.
     *
     * A table of thirty bordered boxes reads as a form, not as an order —
     * the boxes become the pattern and the figures stop being scannable.
     * Bordered on hover and focus only, so it is still obviously editable
     * without shouting about it on every row.
     */
    const cell =
        'w-full rounded-[var(--shell-radius-sm)] border border-transparent bg-transparent px-2 py-1.5 ' +
        'transition-colors hover:border-[var(--shell-border)] focus:border-[var(--color-brand)] ' +
        'focus:bg-[var(--color-card-bg)] focus:outline-none';

    return (
        <div ref={box}>
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-[var(--shell-border)] text-[11px] uppercase tracking-wider text-[var(--color-text-muted)]">
                        <th className="px-4 py-2 text-left font-semibold" colSpan={2}>
                            Item
                        </th>
                        {/*
                          Sized to their contents, not shared out evenly.

                          A quantity is one or two digits and a price is five;
                          giving each a comfortable column left the description
                          — the only part that is prose, and the only part
                          anybody reads — with 157 pixels for a name that needs
                          231. Numbers are the cheapest thing on the row to
                          narrow, because a right-aligned column of them stays
                          readable until it actually clips.
                        */}
                        <th className="w-16 py-2 pl-2 text-right font-semibold">Qty</th>
                        <th className="w-24 py-2 pl-2 text-right font-semibold">Unit price</th>
                        <th className="w-24 py-2 pl-2 text-right font-semibold">Amount</th>
                        <th className="w-8 py-2 pr-2" />
                    </tr>
                </thead>

                <tbody>
                    {lines.length === 0 && (
                        <tr>
                            <td
                                colSpan={6}
                                className="px-4 py-6 text-center text-xs text-[var(--color-text-subtle)]"
                            >
                                Nothing on this order yet.
                            </td>
                        </tr>
                    )}

                    {/*
                      Two rows per line when its picker is open: the line, and
                      the catalogue directly beneath it. A fragment would need a
                      key on the wrapper and give the panel nowhere to span the
                      table's full width from.
                    */}
                    {lines.flatMap((line, index) => [
                        <tr
                            key={line.id ?? `new-${index}`}
                            className="group border-b border-[var(--shell-border)] last:border-0 hover:bg-[var(--color-site-bg)]"
                        >
                            {/*
                              ── The picture ──────────────────────────────────
                              Somebody checking an order against what is on the
                              shelf is matching a bottle, not a string, and
                              reads the picture faster than the words.

                              A framed placeholder rather than nothing when
                              there is no picture: an empty cell makes the rows
                              beside it look misaligned, where a frame reads as
                              "this product has no picture yet".
                            */}
                            <td className="py-1.5 pl-3 pr-0 align-middle">
                                <span
                                    className="flex size-9 items-center justify-center overflow-hidden rounded-[var(--shell-radius-sm)] border bg-[var(--color-site-bg)]"
                                    style={{ borderColor: 'var(--shell-border)' }}
                                >
                                    {line.image ? (
                                        <img
                                            src={line.image}
                                            alt=""
                                            loading="lazy"
                                            className="h-full w-full object-cover"
                                        />
                                    ) : (
                                        <Icon
                                            name="image"
                                            size={14}
                                            className="text-[var(--color-text-subtle)]"
                                        />
                                    )}
                                </span>
                            </td>

                            <td className="px-2 py-1.5">
                                {/*
                                  ── A product, not a string ──────────────────
                                  This was a text box, which let somebody rename
                                  "Mustard Oil 1l" to "Mustard Oil 2l" on an
                                  order and change nothing else — not the SKU,
                                  not the price, not what leaves the warehouse.
                                  The line would then describe one product and
                                  be priced as another, and nothing on the screen
                                  would say so.

                                  Naming a product is choosing one. Pressing this
                                  opens the catalogue for this row, and what
                                  comes back brings its SKU, its price and its
                                  picture with it.
                                */}
                                {freeText.has(index) ? (
                                    <input
                                        className={cell}
                                        value={line.description}
                                        placeholder="Describe this line"
                                        onChange={(event) =>
                                            patch(index, { description: event.target.value })
                                        }
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        className={`${cell} flex w-full items-center gap-1.5 text-left hover:bg-[var(--shell-hover)]`}
                                        onClick={() => {
                                            setPicking(picking === index ? null : index);
                                            setTerm('');
                                        }}
                                        title={`${line.description || 'Choose a product'} — press to change the product`}
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            {line.description || (
                                                <span className="text-[var(--color-text-subtle)]">
                                                    Choose a product
                                                </span>
                                            )}
                                        </span>

                                        <Icon
                                            name="caret-down"
                                            size={11}
                                            className="shrink-0 text-[var(--color-text-subtle)]"
                                        />
                                    </button>
                                )}

                                <span className="flex items-center gap-2 px-2">
                                    {line.sku && (
                                        <span className="font-mono text-[11px] text-[var(--color-text-subtle)]">
                                            {line.sku}
                                        </span>
                                    )}

                                    {/*
                                      What it normally sells for, when this line
                                      is not charging that.

                                      The difference is the order's discount, so
                                      showing it on the line is showing where the
                                      discount came from — otherwise the figure
                                      in the summary below has no visible cause.
                                    */}
                                    {typeof line.list_price === 'number' &&
                                        line.list_price > line.unit_price && (
                                            <span
                                                className="text-[11px] text-[var(--color-text-subtle)] line-through"
                                                title="Usual price"
                                            >
                                                {money(line.list_price)}
                                            </span>
                                        )}
                                </span>
                            </td>

                            <td className="py-1.5 pl-2 align-top">
                                <input
                                    type="number"
                                    min={0}
                                    step="any"
                                    className={`${cell} text-right tabular-nums`}
                                    value={line.quantity}
                                    onChange={(event) =>
                                        patch(index, { quantity: Number(event.target.value) })
                                    }
                                />
                            </td>

                            <td className="py-1.5 pl-2 align-top">
                                <input
                                    type="number"
                                    min={0}
                                    step="any"
                                    className={`${cell} text-right tabular-nums`}
                                    value={line.unit_price}
                                    onChange={(event) =>
                                        patch(index, { unit_price: Number(event.target.value) })
                                    }
                                />
                            </td>

                            {/* Derived, so the three can never disagree. */}
                            <td className="py-1.5 pl-2 pr-2 text-right align-middle font-medium tabular-nums text-[var(--color-text-main)]">
                                {money(line.quantity * line.unit_price)}
                            </td>

                            <td className="py-1.5 pr-3 text-right align-middle">
                                <button
                                    type="button"
                                    aria-label={`Remove ${line.description || 'this line'}`}
                                    title="Remove"
                                    // Revealed on hover: a column of red crosses
                                    // turns a price list into a demolition site.
                                    className="text-[var(--color-text-subtle)] opacity-0 transition group-hover:opacity-100 hover:text-[var(--color-danger)] focus:opacity-100"
                                    onClick={() => onChange(lines.filter((_, i) => i !== index))}
                                >
                                    <Icon name="x" size={14} />
                                </button>
                            </td>
                        </tr>,

                        picking === index ? (
                            <tr key={`picking-${index}`}>
                                <td colSpan={6} className="px-3 pb-3 pt-0">
                                    {catalogue}
                                </td>
                            </tr>
                        ) : null,
                    ])}
                </tbody>

                {lines.length > 0 && (
                    <tfoot>
                        <tr className="border-t border-[var(--shell-border)] bg-[var(--color-site-bg)]">
                            <td colSpan={4} className="px-4 py-2.5 text-right text-[var(--color-text-muted)]">
                                Lines total
                            </td>
                            <td className="py-2.5 pl-2 pr-2 text-right font-semibold tabular-nums text-[var(--color-text-main)]">
                                {money(subtotal)}
                            </td>
                            <td className="py-2.5 pr-3 text-right text-[11px] text-[var(--color-text-subtle)]">
                                {currency}
                            </td>
                        </tr>
                    </tfoot>
                )}
            </table>

            {/* ── Adding ─────────────────────────────────────────────────────── */}

            <div className="border-t border-[var(--shell-border)] px-4 py-3">
                <button
                    type="button"
                    className="btn btn-secondary text-sm"
                    onClick={() => {
                        setPicking(picking === 'new' ? null : 'new');
                        setTerm('');
                    }}
                >
                    <Icon name={picking === 'new' ? 'x' : 'plus'} size={14} />
                    {picking === 'new' ? 'Close' : 'Add item'}
                </button>

                {/* The same catalogue the rows open, for a new line. */}
                {picking === 'new' && <div className="mt-3">{catalogue}</div>}
            </div>
        </div>
    );
}
