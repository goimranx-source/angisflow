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
};

type Variant = {
    id: number;
    sku: string | null;
    name: string;
    price: number;
    currency: string | null;
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
    const [picking, setPicking] = useState(false);
    const [term, setTerm] = useState('');
    const box = useRef<HTMLDivElement>(null);

    const { data: found, isFetching } = useQuery<{ data: Variant[] }>({
        queryKey: ['catalogue', term],
        queryFn: ({ signal }) =>
            api.get(`/orders/catalogue/search?q=${encodeURIComponent(term)}`, { signal }),
        enabled: picking,
        staleTime: 30_000,
    });

    useEffect(() => {
        if (!picking) {
            return;
        }

        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setPicking(false);

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

    const add = (variant?: Variant) => {
        onChange([
            ...lines,
            {
                id: null,
                variant_id: variant?.id ?? null,
                sku: variant?.sku ?? null,
                description: variant?.name ?? '',
                quantity: 1,
                unit_price: variant?.price ?? 0,
            },
        ]);

        setPicking(false);
        setTerm('');
    };

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
                        <th className="px-4 py-2 text-left font-semibold">Item</th>
                        <th className="w-20 py-2 pl-2 text-right font-semibold">Qty</th>
                        <th className="w-32 py-2 pl-2 text-right font-semibold">Unit price</th>
                        <th className="w-28 py-2 pl-2 text-right font-semibold">Amount</th>
                        <th className="w-10 py-2 pr-2" />
                    </tr>
                </thead>

                <tbody>
                    {lines.length === 0 && (
                        <tr>
                            <td
                                colSpan={5}
                                className="px-4 py-6 text-center text-xs text-[var(--color-text-subtle)]"
                            >
                                Nothing on this order yet.
                            </td>
                        </tr>
                    )}

                    {lines.map((line, index) => (
                        <tr
                            key={line.id ?? `new-${index}`}
                            className="group border-b border-[var(--shell-border)] last:border-0 hover:bg-[var(--color-site-bg)]"
                        >
                            <td className="px-2 py-1.5">
                                <input
                                    className={cell}
                                    value={line.description}
                                    placeholder="Item description"
                                    onChange={(event) => patch(index, { description: event.target.value })}
                                />
                                {line.sku && (
                                    <span className="block px-2 font-mono text-[11px] text-[var(--color-text-subtle)]">
                                        {line.sku}
                                    </span>
                                )}
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
                        </tr>
                    ))}
                </tbody>

                {lines.length > 0 && (
                    <tfoot>
                        <tr className="border-t border-[var(--shell-border)] bg-[var(--color-site-bg)]">
                            <td colSpan={3} className="px-4 py-2.5 text-right text-[var(--color-text-muted)]">
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
                    onClick={() => setPicking((was) => !was)}
                >
                    <Icon name={picking ? 'x' : 'plus'} size={14} />
                    {picking ? 'Close' : 'Add item'}
                </button>

                {/*
                  Opened in the flow, not floating over it.

                  A dropdown here lives inside a scrolling column, and an
                  absolutely positioned panel is clipped by it — which is exactly
                  what happened: the list appeared cut off at the top of the
                  card. Expanding in place cannot be clipped, needs no measuring,
                  and pushes the form down rather than covering the line somebody
                  is comparing against.
                */}
                {picking && (
                    <div className="mt-3 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] p-2">
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
                                    className="flex w-full items-center justify-between gap-3 border-b border-[var(--shell-border)] px-3 py-2 text-left text-sm last:border-0 hover:bg-[var(--shell-hover)]"
                                    onClick={() => add(variant)}
                                >
                                    <span className="min-w-0">
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
                          Not everything sold is in the catalogue — a one-off, a
                          surcharge, something entered before the product
                          existed. Refusing those would make the picker a wall
                          rather than a shortcut.
                        */}
                        <button
                            type="button"
                            className="mt-2 w-full px-1 text-left text-xs text-[var(--color-text-muted)] hover:text-[var(--color-brand)]"
                            onClick={() => add()}
                        >
                            Add a line that is not in the catalogue
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
