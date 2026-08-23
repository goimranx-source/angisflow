import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { CustomField, type CustomFieldDef } from './CustomField';
import { LineItems, type OrderLine } from './LineItems';
import { isMedia, isWide } from './fieldTypes';

type Editor = {
    data: {
        id: string;
        number: string;
        values: Record<string, unknown>;
        custom: Record<string, unknown>;
        custom_fields: CustomFieldDef[];
        lines: OrderLine[];
        mapped: string[];
        shop: string | null;
        symbol: string;
        statuses: Array<{ value: string; label: string; custom?: boolean }>;
    };
};

/**
 * Editing everything an order carries.
 *
 * ── Why two columns ──────────────────────────────────────────────────────────
 *
 * An order holds two kinds of content that do not read the same way. Most of it
 * is fields — short, labelled, scanned in pairs — and a single column of those
 * on a wide screen is a narrow ribbon with half the window empty beside it. The
 * rest is what the order *has*: pictures, attachments, where it came from.
 * Those want space and are looked at rather than filled in.
 *
 * ── Why every field, and not a chosen few ────────────────────────────────────
 *
 * Because a shop can map any of them, and a form offering three of the twelve
 * fields a shop sends is a form that cannot edit what the shop is allowed to
 * change. The built-in ones are laid out by hand — in the order somebody thinks
 * about them, not the order the table stores them — and the business's own are
 * placed by type, since they are unknown here.
 */
export function OrderEditor({ orderId, onClose }: { orderId: string; onClose: () => void }) {
    const queryClient = useQueryClient();

    const { data, isLoading, isError } = useQuery<Editor>({
        queryKey: ['order-editor', orderId],
        queryFn: ({ signal }) => api.get(`/orders/${orderId}/editor`, { signal }),
        staleTime: 0,
        refetchOnWindowFocus: false,
    });

    const [form, setForm] = useState<Record<string, unknown>>({});
    const [custom, setCustom] = useState<Record<string, unknown>>({});
    const [lines, setLines] = useState<OrderLine[]>([]);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (!data) {
            return;
        }

        setForm({ ...data.data.values });
        setCustom({ ...data.data.custom });
        setLines(data.data.lines.map((line) => ({ ...line })));
        setDirty(false);
    }, [data]);

    const save = useMutation({
        mutationFn: () => {
            // number is shown for identification and is the shop's to issue;
            // sending it back would be this form claiming to set it.
            const { number: _identifier, ...editable } = form;

            return api.patch(`/orders/${orderId}`, { ...editable, custom, lines });
        },
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

    const set = (key: string, next: unknown) => {
        setForm((current) => ({ ...current, [key]: next }));
        setDirty(true);
    };

    const val = (key: string): string => {
        const raw = form[key];

        return raw === null || raw === undefined ? '' : String(raw);
    };

    /*
     * A field this shop actually sends is worth marking.
     *
     * Nothing is hidden on the strength of it, but it says which of these will
     * travel back when the order is saved and which are only ours.
     */
    const sync = (key: string) =>
        editor.mapped.includes(key) ? (
            <span
                className="inline-flex items-center text-[var(--color-brand)]"
                title="This shop sends this field, so changes travel back to it"
            >
                <Icon name="arrows-left-right" size={11} />
            </span>
        ) : null;

    // ── The pieces a row is built from ──────────────────────────────────────

    const Label = ({ id, text, mapKey }: { id: string; text: string; mapKey?: string }) => (
        <label
            htmlFor={id}
            className="mb-1.5 flex items-center gap-1.5 text-[13px] font-medium text-[var(--color-text-body)]"
        >
            {text}
            {mapKey ? sync(mapKey) : null}
        </label>
    );

    const Text = ({
        name,
        label,
        type = 'text',
        mapKey,
        readOnly,
    }: {
        name: string;
        label: string;
        type?: string;
        mapKey?: string;
        readOnly?: boolean;
    }) => (
        <div>
            <Label id={`f-${name}`} text={label} mapKey={mapKey ?? name} />
            <input
                id={`f-${name}`}
                type={type}
                readOnly={readOnly}
                className={`field w-full ${readOnly ? 'cursor-not-allowed opacity-60' : ''}`}
                value={val(name)}
                onChange={(event) => set(name, event.target.value === '' ? null : event.target.value)}
            />
        </div>
    );

    const Money = ({ name, label }: { name: string; label: string }) => (
        <div>
            <Label id={`f-${name}`} text={label} mapKey={`${name}_minor`} />
            <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--color-text-muted)]">
                    {editor.symbol}
                </span>
                <input
                    id={`f-${name}`}
                    type="number"
                    step="any"
                    className="field w-full pl-7 text-right tabular-nums"
                    value={val(name)}
                    onChange={(event) =>
                        set(name, event.target.value === '' ? null : Number(event.target.value))
                    }
                />
            </div>
        </div>
    );

    const Choice = ({
        name,
        label,
        options,
    }: {
        name: string;
        label: string;
        options: Array<{ value: string; label: string }>;
    }) => (
        <div>
            <Label id={`f-${name}`} text={label} mapKey={name} />
            <select
                id={`f-${name}`}
                className="field w-full"
                value={val(name)}
                onChange={(event) => set(name, event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );

    const Area = ({ name, label, rows = 3 }: { name: string; label: string; rows?: number }) => (
        <div>
            <Label id={`f-${name}`} text={label} mapKey={name} />
            <textarea
                id={`f-${name}`}
                rows={rows}
                className="field w-full"
                value={val(name)}
                onChange={(event) => set(name, event.target.value || null)}
            />
        </div>
    );

    /*
     * A card per group, rather than headings on an open page.
     *
     * Twelve customer fields and eight delivery fields in one flat column is a
     * wall — the eye has nothing to stop at, and two fields with similar names
     * a screen apart look like the same field twice. Boxing each group gives
     * every one a boundary and a name.
     */
    const Card = ({
        title,
        hint,
        children,
    }: {
        title: string;
        hint?: string;
        children: ReactNode;
    }) => (
        <section className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-card-bg)]">
            <header className="flex items-baseline justify-between gap-3 border-b border-[var(--shell-border)] px-4 py-2.5">
                <h3 className="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                    {title}
                </h3>
                {hint && <span className="text-[11px] text-[var(--color-text-subtle)]">{hint}</span>}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );

    const grid = (children: ReactNode) => <div className="grid gap-4 sm:grid-cols-2">{children}</div>;

    const mediaFields = editor.custom_fields.filter((f) => isMedia(f.type));
    const plainFields = editor.custom_fields.filter((f) => !isMedia(f.type));

    const statusOptions = editor.statuses.map((s) => ({
        value: s.value,
        label: s.custom ? `${s.label} (Custom)` : s.label,
    }));

    return (
        <div className="flex h-full flex-col">
            <div className="grid flex-1 items-start gap-5 overflow-y-auto pb-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                {/* ── Fields ───────────────────────────────────────────────── */}
                <div className="space-y-5">
                    <Card title="Order" hint={editor.number}>
                        {grid(
                            <>
                                <Choice name="status" label="Status" options={statusOptions} />
                                <Choice
                                    name="payment_status"
                                    label="Payment"
                                    options={[
                                        { value: 'paid', label: 'Paid' },
                                        { value: 'unpaid', label: 'Unpaid' },
                                    ]}
                                />
                                <Choice
                                    name="fulfilment_status"
                                    label="Fulfilment"
                                    options={[
                                        { value: 'unfulfilled', label: 'Not dispatched' },
                                        { value: 'fulfilled', label: 'Dispatched' },
                                    ]}
                                />
                                <Choice
                                    name="channel"
                                    label="Channel"
                                    options={[
                                        { value: 'online', label: 'Online' },
                                        { value: 'pos', label: 'Point of sale' },
                                        { value: 'phone', label: 'Phone' },
                                        { value: 'api', label: 'API' },
                                    ]}
                                />
                                <Text name="ordered_on" label="Order date" type="date" />
                                <Text name="external_ref" label="External reference" />

                                <label className="flex items-center gap-2.5 sm:col-span-2">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.is_cod === true}
                                        onChange={(event) => set('is_cod', event.target.checked)}
                                    />
                                    <span className="flex items-center gap-1.5 text-[13px] font-medium text-[var(--color-text-body)]">
                                        Cash on delivery
                                        {sync('is_cod')}
                                    </span>
                                </label>
                            </>,
                        )}
                    </Card>

                    <Card title="Customer">
                        {grid(
                            <>
                                <Text name="customer_name" label="Name" mapKey="customer.name" />
                                <Text
                                    name="customer_email"
                                    label="Email"
                                    type="email"
                                    mapKey="customer.email"
                                />
                                <Text
                                    name="customer_phone"
                                    label="Phone"
                                    type="tel"
                                    mapKey="customer.phone"
                                />
                                <Text
                                    name="customer_company"
                                    label="Company"
                                    mapKey="customer.company"
                                />
                                <Text
                                    name="customer_tax_number"
                                    label="Tax number"
                                    mapKey="customer.tax_number"
                                />
                            </>,
                        )}
                    </Card>

                    <Card title="Billing address" hint="On the customer record">
                        {grid(
                            <>
                                <div className="sm:col-span-2">
                                    <Text
                                        name="customer_billing_address"
                                        label="Address"
                                        mapKey="customer.billing_address"
                                    />
                                </div>
                                <Text
                                    name="customer_billing_city"
                                    label="City"
                                    mapKey="customer.billing_city"
                                />
                                <Text
                                    name="customer_billing_postcode"
                                    label="Postcode"
                                    mapKey="customer.billing_postcode"
                                />
                                <Text
                                    name="customer_billing_country"
                                    label="Country"
                                    mapKey="customer.billing_country"
                                />
                            </>,
                        )}
                    </Card>

                    <Card title="Delivery address" hint="On this order">
                        {grid(
                            <>
                                <Text name="shipping_name" label="Recipient" />
                                <Text name="shipping_phone" label="Phone" type="tel" />
                                <div className="sm:col-span-2">
                                    <Text name="shipping_address" label="Address" />
                                </div>
                                <Text name="shipping_city" label="City" />
                                <Text name="shipping_postcode" label="Postcode" />
                                <Text name="shipping_country" label="Country" />
                            </>,
                        )}
                    </Card>

                    {/*
                      Placed above the money, because it produces it.

                      Reading down the column the order is: what was bought,
                      then what that came to. The reverse asks somebody to
                      accept a total before seeing the lines behind it.
                    */}
                    <Card title="Items" hint={`${lines.length} line${lines.length === 1 ? '' : 's'}`}>
                        <LineItems
                            lines={lines}
                            currency={String(editor.values.currency ?? '')}
                            onChange={(next) => {
                                setLines(next);
                                setDirty(true);
                            }}
                        />
                    </Card>

                    <Card title="Money" hint={String(editor.values.currency ?? '')}>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Money name="subtotal" label="Subtotal" />
                            <Money name="discount" label="Discount" />
                            <Money name="shipping" label="Shipping" />
                            <Money name="tax" label="Tax" />
                            <Money name="total" label="Total" />
                            <Money name="paid" label="Paid" />
                        </div>
                        <p className="mt-3 border-t border-[var(--shell-border)] pt-3 text-xs text-[var(--color-text-subtle)]">
                            Most shops derive these from the order&rsquo;s lines and will recalculate them
                            from their own copy.
                        </p>
                    </Card>

                    <Card title="Notes">
                        <div className="space-y-4">
                            <Area name="notes" label="Order notes" />
                            <Area name="customer_notes" label="Customer notes" rows={2} />
                        </div>
                    </Card>

                    {plainFields.length > 0 && (
                        <Card title="Additional fields" hint="Defined by this business">
                            {grid(
                                plainFields.map((f) => (
                                    <div key={f.key} className={isWide(f.type) ? 'sm:col-span-2' : undefined}>
                                        <CustomField
                                            field={f}
                                            value={custom[f.key]}
                                            onChange={(next) => {
                                                setCustom((current) => ({ ...current, [f.key]: next }));
                                                setDirty(true);
                                            }}
                                        />
                                    </div>
                                )),
                            )}
                        </Card>
                    )}
                </div>

                {/* ── What the order carries ───────────────────────────────── */}
                <div className="space-y-5">
                    <Card title="Source">
                        <p className="text-sm font-medium text-[var(--color-text-main)]">
                            {editor.shop ?? 'Walk-in / counter'}
                        </p>
                        <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                            Order {editor.number}
                        </p>
                        {editor.mapped.length > 0 && (
                            <p className="mt-3 flex items-start gap-1.5 border-t border-[var(--shell-border)] pt-3 text-xs text-[var(--color-text-muted)]">
                                <span className="mt-0.5 text-[var(--color-brand)]">
                                    <Icon name="arrows-left-right" size={12} />
                                </span>
                                <span>
                                    {editor.mapped.length} fields sync with this shop. Marked fields
                                    travel back when you save.
                                </span>
                            </p>
                        )}
                    </Card>

                    <Card title="Attachments" hint={mediaFields.length > 0 ? undefined : 'None defined'}>
                        {mediaFields.length > 0 ? (
                            <div className="space-y-4">
                                {mediaFields.map((f) => (
                                    <CustomField
                                        key={f.key}
                                        field={f}
                                        value={custom[f.key]}
                                        onChange={(next) => {
                                            setCustom((current) => ({ ...current, [f.key]: next }));
                                            setDirty(true);
                                        }}
                                    />
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-[var(--color-text-subtle)]">
                                Pictures, files and videos appear here when this business defines a field
                                for them.
                            </p>
                        )}
                    </Card>
                </div>
            </div>

            {/* ── Save ─────────────────────────────────────────────────────── */}
            <div className="mt-4 flex items-center justify-end gap-3 border-t border-[var(--shell-border)] pt-4">
                {dirty && (
                    <span className="mr-auto flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
                        <Icon name="circle" size={8} className="text-[var(--color-brand)]" />
                        Unsaved changes
                    </span>
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
