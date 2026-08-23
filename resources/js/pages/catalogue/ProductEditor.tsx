import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

export type EditableProduct = {
    id: string;
    name: string;
    sku: string;
    description?: string;
    barcode?: string;
    unit?: string;
    price: number;
    cost?: number;
    is_active: boolean;
};

/**
 * Changing a product, and watching it reach the shops.
 *
 * ── Why this is worth its own panel ──────────────────────────────────────────
 *
 * The catalogue screen could show a product but not change one — every action
 * on it was a console.log and a TODO. That made the whole two-way catalogue
 * sync untestable from the application it belongs to: the only way to prove a
 * price change reached WooCommerce was to write one in a console.
 *
 * ── What it deliberately does not do ─────────────────────────────────────────
 *
 * Nothing about syncing. Saving writes the product; an observer on the model
 * notices and sends it to every shop that sells it. Wiring a push in here would
 * mean the next screen that edits a product has to remember to do the same, and
 * would eventually forget — which is exactly how the order editor shipped
 * without one.
 */
export function ProductEditor({
    product,
    onSaved,
}: {
    product: EditableProduct;
    onSaved?: () => void;
}) {
    const queryClient = useQueryClient();

    const [form, setForm] = useState<Record<string, unknown>>({});
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        setForm({
            name: product.name,
            sku: product.sku,
            description: product.description ?? '',
            barcode: product.barcode ?? '',
            unit: product.unit ?? '',
            price: product.price,
            cost: product.cost ?? 0,
            is_active: product.is_active,
        });
        setDirty(false);
    }, [product]);

    const save = useMutation({
        mutationFn: () => api.patch(`/products/${product.id}`, form),
        onSuccess: (result) => {
            const shaped = (result ?? null) as { message?: string } | null;

            toast.success(shaped?.message ?? 'Product saved.');
            setDirty(false);

            void queryClient.invalidateQueries({ queryKey: ['products'] });
            // The push is queued by the observer, so the progress card has
            // something new to report the moment this lands.
            void queryClient.invalidateQueries({ queryKey: ['pushes', 'active'] });

            onSaved?.();
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    const set = (key: string, value: unknown) => {
        setForm((current) => ({ ...current, [key]: value }));
        setDirty(true);
    };

    const val = (key: string): string => {
        const raw = form[key];

        return raw === null || raw === undefined ? '' : String(raw);
    };

    const Field = ({
        name,
        label,
        type = 'text',
        hint,
    }: {
        name: string;
        label: string;
        type?: string;
        hint?: string;
    }) => (
        <div>
            <label
                htmlFor={`p-${name}`}
                className="mb-1.5 block text-[13px] font-medium text-[var(--color-text-body)]"
            >
                {label}
            </label>
            <input
                id={`p-${name}`}
                type={type}
                step={type === 'number' ? 'any' : undefined}
                className="field w-full"
                value={val(name)}
                onChange={(event) =>
                    set(name, type === 'number' ? Number(event.target.value) : event.target.value)
                }
            />
            {hint && <p className="mt-1 text-xs text-[var(--color-text-subtle)]">{hint}</p>}
        </div>
    );

    return (
        <div className="space-y-5">
            <section className="overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)]">
                <header className="border-b border-[var(--shell-border)] bg-[var(--color-site-bg)] px-4 py-2.5">
                    <h3 className="text-[13px] font-semibold text-[var(--color-text-main)]">Details</h3>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Field name="name" label="Name" />
                    </div>

                    <Field name="sku" label="SKU" />
                    <Field name="barcode" label="Barcode" />

                    <Field name="price" label="Price" type="number" />
                    <Field name="cost" label="Cost" type="number" hint="What you pay, not what you charge." />

                    <Field name="unit" label="Unit" />

                    <label className="flex items-center gap-2.5 pt-6">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.is_active === true}
                            onChange={(event) => set('is_active', event.target.checked)}
                        />
                        <span className="text-[13px] font-medium text-[var(--color-text-body)]">
                            Listed for sale
                        </span>
                    </label>

                    <div className="sm:col-span-2">
                        <label
                            htmlFor="p-description"
                            className="mb-1.5 block text-[13px] font-medium text-[var(--color-text-body)]"
                        >
                            Description
                        </label>
                        <textarea
                            id="p-description"
                            rows={4}
                            className="field w-full"
                            value={val('description')}
                            onChange={(event) => set('description', event.target.value)}
                        />
                    </div>
                </div>
            </section>

            {/*
              Said plainly, because it is the point of the screen.

              Somebody editing a price here needs to know it is not a private
              change — it goes to every shop selling this product, and the
              progress card in the corner is where it can be watched.
            */}
            <p className="flex items-start gap-2 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] p-3 text-xs text-[var(--color-text-muted)]">
                <span className="mt-0.5 shrink-0 text-[var(--color-brand)]">
                    <Icon name="arrows-left-right" size={13} />
                </span>
                <span>
                    Saving sends this product to every connected shop that sells it. Watch the corner of
                    the screen to see it arrive.
                </span>
            </p>

            <div className="flex items-center justify-end gap-3 border-t border-[var(--shell-border)] pt-4">
                {dirty && (
                    <span className="mr-auto text-xs text-[var(--color-text-muted)]">Unsaved changes</span>
                )}

                <button
                    type="button"
                    className="btn btn-primary"
                    disabled={!dirty || save.isPending}
                    onClick={() => save.mutate()}
                >
                    {save.isPending ? 'Saving…' : 'Save and send to shops'}
                </button>
            </div>
        </div>
    );
}
