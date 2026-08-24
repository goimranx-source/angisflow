import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { CustomField, type CustomFieldDef } from './CustomField';
import { LineItems, type OrderLine } from './LineItems';
import { placementFor, type Placement } from '@/pages/orders/fieldPlacement';
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
        storefronts: Array<{ id: string; name: string }>;
        couriers: Array<{ id: string; label: string | null }>;
        dispatch: { courier: string | null; status: string | null } | null;
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
    const [courier, setCourier] = useState('');
    const [dispatching, setDispatching] = useState(false);

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

    /*
     * Handing the order to a courier.
     *
     * Its own request rather than a field on the form: dispatching creates a
     * shipment and cannot be undone by pressing Cancel, so folding it into
     * "unsaved changes" would misrepresent what pressing it does.
     */
    const sendToCourier = async (): Promise<void> => {
        setDispatching(true);

        try {
            await api.post(`/orders/${orderId}/dispatch`, { courier_id: courier });

            toast.success('Sent to the courier.');

            void queryClient.invalidateQueries({ queryKey: ['order-editor', orderId] });
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'That could not be sent.');
        } finally {
            setDispatching(false);
        }
    };

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
        flush,
        children,
    }: {
        title: string;
        hint?: string;
        /** For a table that should meet its own box, with no padding between. */
        flush?: boolean;
        children: ReactNode;
    }) => (
        <section className="overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-card-bg)]">
            <header className="flex items-baseline justify-between gap-3 border-b border-[var(--shell-border)] bg-[var(--color-site-bg)] px-4 py-2.5">
                <h3 className="text-[13px] font-semibold text-[var(--color-text-main)]">{title}</h3>
                {hint && (
                    <span className="text-[11px] tabular-nums text-[var(--color-text-muted)]">{hint}</span>
                )}
            </header>
            <div className={flush ? '' : 'p-4'}>{children}</div>
        </section>
    );

    const grid = (children: ReactNode) => <div className="grid gap-4 sm:grid-cols-2">{children}</div>;

    /*
     * ── A shop's own fields, sorted into the form's own sections ────────────
     *
     * Every one of them used to land in a box at the foot of the form called
     * "Additional fields" — a delivery instruction three sections below the
     * delivery address, a second phone nowhere near the first, a payment
     * reference under the notes. The form has sections for exactly these things
     * and was not using them.
     *
     * `placementFor` reads the field's type first and its name second, and
     * returns which section it belongs in. It is a guess and is allowed to be
     * wrong; the cost of wrong is a field one section from where somebody
     * looked, which is what the old behaviour cost on every field every time.
     *
     * Anything it cannot place stays at the bottom, which is the honest answer
     * rather than a confident wrong one.
     */
    const placed = editor.custom_fields.reduce<Record<Placement, CustomFieldDef[]>>(
        (into, field) => {
            const where = isMedia(field.type)
                ? 'media'
                : placementFor(field.key, field.type, field.label);

            into[where].push(field);

            return into;
        },
        { customer: [], delivery: [], payment: [], dates: [], media: [], other: [] },
    );

    const mediaFields = placed.media;

    /**
     * The extra fields belonging to one section, or nothing.
     *
     * Rendered inside the section's own grid, after its built-in fields, so a
     * shop's delivery slot sits with the delivery address rather than in a
     * different box with a different heading.
     */
    const extras = (where: Placement) =>
        placed[where].map((f) => (
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
        ));

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
                                {/*
                                  The shop, not a channel.

                                  Channel asked whether a sale was "online" or
                                  "phone" — a distinction nobody maintained and
                                  which said nothing the shop did not already
                                  say. The storefront is the real answer: it
                                  decides the tag on the order number, the
                                  currency, and where a push travels.
                                */}
                                <Choice
                                    name="storefront_id"
                                    label="Store"
                                    options={[
                                        { value: '', label: 'Walk-in / counter' },
                                        ...editor.storefronts.map((shop) => ({
                                            value: shop.id,
                                            label: shop.name,
                                        })),
                                    ]}
                                />
                                <Text name="ordered_on" label="Order date" type="date" />
                                <Text name="external_ref" label="External reference" />
                                {extras('dates')}

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
                                {extras('customer')}
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
                                {extras('delivery')}
                            </>,
                        )}
                    </Card>

                    {/*
                      Placed above the money, because it produces it.

                      Reading down the column the order is: what was bought,
                      then what that came to. The reverse asks somebody to
                      accept a total before seeing the lines behind it.
                    */}
                    <Card title="Items" hint={`${lines.length} line${lines.length === 1 ? '' : 's'}`} flush>
                        <LineItems
                            lines={lines}
                            currency={String(editor.values.currency ?? '')}
                            symbol={editor.symbol}
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
                            {extras('payment')}
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

                    {/*
                      What is left, and only what is left.

                      A field whose name says nothing this form recognises —
                      `order_source`, `_ga_tracked` — has no section it belongs
                      to, and putting it in one on a weak match would be a
                      confident lie. At the bottom, under a heading that says
                      where it came from, it is at least obviously extra.
                    */}
                    {placed.other.length > 0 && (
                        <Card title="Other details" hint="From this shop">
                            {grid(extras('other'))}
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

                    {/*
                      Sending it, rather than declaring it sent.

                      This replaced a Fulfilment dropdown offering "dispatched"
                      and "not dispatched" as though they were things somebody
                      decides. They are not — they are what becomes true once an
                      order is actually handed to a courier, and a field that
                      let you claim either without doing it could only ever
                      disagree with the courier.

                      Dispatching is its own action against its own endpoint, so
                      it is not part of the form's unsaved changes: it happens
                      when pressed, not when saved.
                    */}
                    <Card title="Delivery">
                        {editor.dispatch ? (
                            <div className="text-sm">
                                <p className="font-medium text-[var(--color-text-main)]">
                                    {editor.dispatch.courier ?? 'A courier'}
                                </p>
                                <p className="mt-0.5 text-xs capitalize text-[var(--color-text-muted)]">
                                    {editor.dispatch.status ?? 'Sent'}
                                </p>
                            </div>
                        ) : editor.couriers.length === 0 ? (
                            <p className="text-xs text-[var(--color-text-subtle)]">
                                No couriers are connected yet.
                            </p>
                        ) : (
                            <div>
                                <label
                                    htmlFor="f-send-to"
                                    className="mb-1.5 block text-[13px] font-medium text-[var(--color-text-body)]"
                                >
                                    Send to
                                </label>
                                <select
                                    id="f-send-to"
                                    className="field w-full"
                                    value={courier}
                                    disabled={dispatching}
                                    onChange={(event) => setCourier(event.target.value)}
                                >
                                    <option value="">Choose a courier…</option>
                                    {editor.couriers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.label ?? 'Courier'}
                                        </option>
                                    ))}
                                </select>

                                <button
                                    type="button"
                                    className="btn btn-secondary mt-2 w-full text-sm"
                                    disabled={courier === '' || dispatching}
                                    onClick={() => sendToCourier()}
                                >
                                    {dispatching ? 'Sending…' : 'Send this order'}
                                </button>

                                <p className="mt-2 text-[11px] text-[var(--color-text-subtle)]">
                                    Sends straight away — it is not part of your unsaved changes.
                                </p>
                            </div>
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
