import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { CustomField, type CustomFieldDef } from './CustomField';
import { isMedia, isWide } from './fieldTypes';

type Editor = {
    data: {
        id: string;
        number: string;
        values: Record<string, unknown>;
        custom: Record<string, unknown>;
        custom_fields: CustomFieldDef[];
        mapped: string[];
        shop: string | null;
        symbol: string;
        statuses: Array<{ value: string; label: string; custom?: boolean }>;
    };
};

/**
 * Editing everything an order carries.
 *
 * ── Why two columns and not a longer form ────────────────────────────────────
 *
 * Because an order has two kinds of content and they do not read the same way.
 * Most of it is fields — short, labelled, scanned in pairs — and a single
 * column of those on a wide screen is a narrow ribbon with half the window
 * empty beside it. The rest is what the order *has*: the lines, pictures,
 * attachments. Those want space and are looked at rather than filled in.
 *
 * So the form runs down the left in pairs, and everything visual sits in a
 * column of its own on the right where it can be seen at a useful size instead
 * of interrupting the fields.
 *
 * ── Why the built-in fields are laid out by hand ─────────────────────────────
 *
 * Every shop has a status, an address, a total, and those deserve a considered
 * order — who the customer is, where it goes, what state it is in, what it
 * costs. Generating that from a schema would produce a correct form nobody
 * enjoys using. Custom fields are the opposite: unknown at build time, so they
 * are placed by type. The two approaches meet in the middle rather than one
 * being forced to do the other's job.
 */
export function OrderEditor({
    orderId,
    onClose,
}: {
    orderId: string;
    onClose: () => void;
}) {
    const queryClient = useQueryClient();

    const { data, isLoading, isError } = useQuery<Editor>({
        queryKey: ['order-editor', orderId],
        queryFn: ({ signal }) => api.get(`/orders/${orderId}/editor`, { signal }),
        // Always fresh: an order edited from two places must not be saved from
        // a form that was filled in before the other change landed.
        staleTime: 0,
        refetchOnWindowFocus: false,
    });

    const [form, setForm] = useState<Record<string, unknown>>({});
    const [custom, setCustom] = useState<Record<string, unknown>>({});
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (!data) {
            return;
        }

        setForm({ ...data.data.values });
        setCustom({ ...data.data.custom });
        setDirty(false);
    }, [data]);

    const save = useMutation({
        mutationFn: () => api.patch(`/orders/${orderId}`, { ...form, custom }),
        onSuccess: (result) => {
            const shaped = (result ?? null) as { message?: string } | null;

            toast.success(shaped?.message ?? 'Order saved.');
            setDirty(false);

            void queryClient.invalidateQueries({ queryKey: ['orders'] });
            void queryClient.invalidateQueries({ queryKey: ['order-editor', orderId] });
            void queryClient.invalidateQueries({ queryKey: ['pushes', 'active'] });

            onClose();
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    if (isLoading) {
        return (
            <div className="flex h-64 items-center justify-center">
                <Icon name="circle-notch" size={22} className="animate-spin text-[var(--color-text-muted)]" />
            </div>
        );
    }

    if (isError || !data) {
        return (
            <p className="py-10 text-center text-sm text-[var(--color-text-muted)]">
                This order could not be opened for editing.
            </p>
        );
    }

    const editor = data.data;

    const set = (key: string, value: unknown) => {
        setForm((current) => ({ ...current, [key]: value }));
        setDirty(true);
    };

    const setCustomValue = (key: string, value: unknown) => {
        setCustom((current) => ({ ...current, [key]: value }));
        setDirty(true);
    };

    const value = (key: string): string => {
        const raw = form[key];

        return raw === null || raw === undefined ? '' : String(raw);
    };

    /*
     * A field this shop actually sends is worth marking.
     *
     * Nothing is hidden on the strength of it — an order can be edited here
     * whether or not the shop fills the field — but a small mark tells somebody
     * which of these will travel back and which are only ours.
     */
    const synced = (key: string) =>
        editor.mapped.includes(key) ? (
            <span
                // inline-flex, not a bare icon: an svg is block-level by
                // default and would drop the marker onto its own line under
                // the label rather than sitting beside it.
                className="inline-flex items-center text-[var(--color-text-subtle)]"
                title="This shop sends this field"
            >
                <Icon name="arrows-left-right" size={11} />
            </span>
        ) : null;

    const field = (key: string, label: string, type = 'text', extra?: React.ReactNode) => (
        <div>
            <label
                htmlFor={`f-${key}`}
                className="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-[var(--color-text-main)]"
            >
                {label}
                {synced(key)}
            </label>
            {extra ?? (
                <input
                    id={`f-${key}`}
                    type={type}
                    step={type === 'number' ? 'any' : undefined}
                    className="field w-full"
                    value={value(key)}
                    onChange={(event) => set(key, event.target.value === '' ? null : event.target.value)}
                />
            )}
        </div>
    );

    const money = (key: string, label: string) =>
        field(
            key,
            label,
            'number',
            <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--color-text-muted)]">
                    {editor.symbol}
                </span>
                <input
                    id={`f-${key}`}
                    type="number"
                    step="any"
                    className="field w-full pl-7"
                    value={value(key)}
                    onChange={(event) => set(key, event.target.value === '' ? null : Number(event.target.value))}
                />
            </div>,
        );

    const section = (title: string, children: React.ReactNode) => (
        <section>
            <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                {title}
            </h3>
            {children}
        </section>
    );

    const mediaFields = editor.custom_fields.filter((f) => isMedia(f.type));
    const plainFields = editor.custom_fields.filter((f) => !isMedia(f.type));

    return (
        <div className="flex h-full flex-col">
            <div className="grid flex-1 gap-6 overflow-y-auto lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                {/* ── The form ─────────────────────────────────────────────── */}
                <div className="space-y-7">
                    {section(
                        'Customer',
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('customer_name', 'Name')}
                            {field('customer_email', 'Email', 'email')}
                            {field('customer_phone', 'Phone', 'tel')}
                        </div>,
                    )}

                    {section(
                        'Delivery',
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('shipping_name', 'Recipient')}
                            {field('shipping_phone', 'Phone', 'tel')}
                            <div className="sm:col-span-2">{field('shipping_address', 'Address')}</div>
                            {field('shipping_city', 'City')}
                            {field('shipping_postcode', 'Postcode')}
                            {field('shipping_country', 'Country')}
                        </div>,
                    )}

                    {section(
                        'Order',
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    htmlFor="f-status"
                                    className="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-[var(--color-text-main)]"
                                >
                                    Status
                                    {synced('status')}
                                </label>
                                <select
                                    id="f-status"
                                    className="field w-full"
                                    value={value('status')}
                                    onChange={(event) => set('status', event.target.value)}
                                >
                                    {editor.statuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                            {s.custom ? ' (Custom)' : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label
                                    htmlFor="f-payment"
                                    className="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-[var(--color-text-main)]"
                                >
                                    Payment
                                    {synced('payment_status')}
                                </label>
                                <select
                                    id="f-payment"
                                    className="field w-full"
                                    value={value('payment_status')}
                                    onChange={(event) => set('payment_status', event.target.value)}
                                >
                                    <option value="paid">Paid</option>
                                    <option value="unpaid">Unpaid</option>
                                </select>
                            </div>

                            <div>
                                <label
                                    htmlFor="f-fulfilment"
                                    className="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-[var(--color-text-main)]"
                                >
                                    Fulfilment
                                    {synced('fulfilment_status')}
                                </label>
                                <select
                                    id="f-fulfilment"
                                    className="field w-full"
                                    value={value('fulfilment_status')}
                                    onChange={(event) => set('fulfilment_status', event.target.value)}
                                >
                                    <option value="unfulfilled">Not dispatched</option>
                                    <option value="fulfilled">Dispatched</option>
                                </select>
                            </div>

                            {field('ordered_on', 'Order date', 'date')}
                            {field('external_ref', 'External reference')}

                            <label className="flex items-center gap-2.5 pt-6">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={form.is_cod === true}
                                    onChange={(event) => set('is_cod', event.target.checked)}
                                />
                                <span className="text-sm font-medium text-[var(--color-text-main)]">
                                    Cash on delivery
                                </span>
                            </label>
                        </div>,
                    )}

                    {section(
                        `Money — ${editor.values.currency ?? ''}`,
                        <div className="grid gap-4 sm:grid-cols-3">
                            {money('subtotal', 'Subtotal')}
                            {money('discount', 'Discount')}
                            {money('shipping', 'Shipping')}
                            {money('tax', 'Tax')}
                            {money('total', 'Total')}
                            {money('paid', 'Paid')}
                        </div>,
                    )}

                    {section(
                        'Notes',
                        <textarea
                            className="field w-full"
                            rows={3}
                            value={value('notes')}
                            onChange={(event) => set('notes', event.target.value || null)}
                        />,
                    )}

                    {/*
                      This business's own fields, placed by type.

                      Wide types get the full row; the rest pair up like the
                      built-in fields above, so a custom field does not announce
                      itself as an afterthought.
                    */}
                    {plainFields.length > 0 &&
                        section(
                            'Additional fields',
                            <div className="grid gap-4 sm:grid-cols-2">
                                {plainFields.map((f) => (
                                    <div key={f.key} className={isWide(f.type) ? 'sm:col-span-2' : undefined}>
                                        <CustomField
                                            field={f}
                                            value={custom[f.key]}
                                            onChange={(next) => setCustomValue(f.key, next)}
                                        />
                                    </div>
                                ))}
                            </div>,
                        )}
                </div>

                {/* ── What the order carries ───────────────────────────────── */}
                <div className="space-y-7 lg:border-l lg:border-[var(--shell-border)] lg:pl-6">
                    {section(
                        'Where it came from',
                        <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] p-3 text-sm">
                            <p className="font-medium text-[var(--color-text-main)]">
                                {editor.shop ?? 'Walk-in / counter'}
                            </p>
                            <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                Order {editor.number}
                            </p>
                            {editor.mapped.length > 0 && (
                                <p className="mt-2 flex items-center gap-1.5 border-t border-[var(--shell-border)] pt-2 text-xs text-[var(--color-text-muted)]">
                                    <Icon name="arrows-left-right" size={12} />
                                    {editor.mapped.length} fields sync with this shop
                                </p>
                            )}
                        </div>,
                    )}

                    {mediaFields.length > 0
                        ? section(
                              'Attachments',
                              <div className="space-y-4">
                                  {mediaFields.map((f) => (
                                      <CustomField
                                          key={f.key}
                                          field={f}
                                          value={custom[f.key]}
                                          onChange={(next) => setCustomValue(f.key, next)}
                                      />
                                  ))}
                              </div>,
                          )
                        : section(
                              'Attachments',
                              <p className="rounded-[var(--shell-radius)] border border-dashed border-[var(--shell-border)] p-4 text-center text-xs text-[var(--color-text-subtle)]">
                                  Pictures and files appear here when this business defines a field for
                                  them.
                              </p>,
                          )}
                </div>
            </div>

            {/* ── Save ─────────────────────────────────────────────────────── */}
            <div className="mt-5 flex items-center justify-end gap-3 border-t border-[var(--shell-border)] pt-4">
                {dirty && (
                    <span className="mr-auto text-xs text-[var(--color-text-muted)]">Unsaved changes</span>
                )}

                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    Cancel
                </button>

                <button
                    type="button"
                    className="btn btn-primary"
                    disabled={!dirty || save.isPending}
                    onClick={() => save.mutate()}
                >
                    {save.isPending ? 'Saving…' : 'Save changes'}
                </button>
            </div>
        </div>
    );
}
