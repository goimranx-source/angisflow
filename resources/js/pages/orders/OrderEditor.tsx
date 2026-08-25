import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
    FieldGrid,
    FieldGroup,
    MoneyField,
    ReadOnlyField,
    SelectField,
    SwitchField,
    TextAreaField,
    TextField,
} from '@/components/ui/Form/Fields';
import { Icon } from '@/components/ui/Icon';
import { InfoHint } from '@/components/ui/InfoHint';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { CustomField, type CustomFieldDef } from './CustomField';
import { LineItems, type OrderLine } from './LineItems';
import { placementFor, type Placement } from '@/pages/orders/fieldPlacement';
import { controlFor, isMedia, isWide } from './fieldTypes';

type Editor = {
    data: {
        id: string;
        number: string;
        values: Record<string, unknown>;
        custom: Record<string, unknown>;
        custom_fields: CustomFieldDef[];
        lines: OrderLine[];
        mapped: string[];

        /**
         * What each box actually is, from the mapping this shop uses.
         *
         * The form used to decide for itself and got it wrong — see
         * OrderFormFields on the server. A country arrives here as `country`
         * with 250 answers attached, and this shop's billing city as `area`
         * with 581.
         */
        fields: Record<
            string,
            {
                label: string;
                type: string;
                mapped: boolean;
                options: Array<{ value: string; label: string; note?: string }> | null;
            }
        >;
        shop: string | null;
        symbol: string;
        statuses: Array<{ value: string; label: string; custom?: boolean }>;
        storefronts: Array<{ id: string; name: string }>;
        couriers: Array<{ id: string; label: string | null }>;
        dispatch: { courier: string | null; status: string | null } | null;
    };
};

/** Money, the way this order's currency writes it. */
function money(symbol: string, amount: number): string {
    return `${symbol}${amount.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/**
 * Editing an order, on a form that says what it is doing.
 *
 * ── What was wrong with it ───────────────────────────────────────────────────
 *
 * Everything was equally prominent. Thirty-odd boxes in identical grey, six of
 * them money, four of those six not actually editable in any meaningful sense —
 * a total nobody should be typing, sitting in the same kind of box as a phone
 * number. Nothing said which order was open once you had scrolled past the top,
 * nothing said what you had changed, and four paragraphs of explanatory grey
 * text were wedged between the fields they explained.
 *
 * ── The three questions it now answers ───────────────────────────────────────
 *
 * *Which order is this?* A bar across the top that does not scroll away,
 * carrying the number, the shop, the customer and the status.
 *
 * *What have I changed?* Every field that differs from what was loaded is
 * marked, and the count is in the bar. Leaving without saving is then a
 * decision made with the facts rather than a guess.
 *
 * *What am I allowed to change?* Figures this form does not set are drawn as
 * facts rather than as greyed-out boxes — see ReadOnlyField, and OrderTotals
 * for why they are not editable.
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
    const [courier, setCourier] = useState('');
    const [dispatching, setDispatching] = useState(false);

    /*
     * What was loaded, kept beside what is on screen.
     *
     * A boolean "dirty" flag could say that something had changed; it could not
     * say what, which is the half somebody actually wants before pressing
     * Cancel on a form with thirty fields in it.
     */
    const [original, setOriginal] = useState<Record<string, unknown>>({});
    const [originalLines, setOriginalLines] = useState<OrderLine[]>([]);
    const [originalCustom, setOriginalCustom] = useState<Record<string, unknown>>({});

    useEffect(() => {
        if (!data) {
            return;
        }

        setForm({ ...data.data.values });
        setOriginal({ ...data.data.values });
        setCustom({ ...data.data.custom });
        setOriginalCustom({ ...data.data.custom });
        setLines(data.data.lines.map((line) => ({ ...line })));
        setOriginalLines(data.data.lines.map((line) => ({ ...line })));
    }, [data]);

    /** The fields that differ from what was loaded. */
    const changed = useMemo(() => {
        const moved = new Set<string>();

        for (const key of Object.keys(form)) {
            // Loosely, on purpose: an empty box and a null from the server are
            // the same absence, and marking that as a change would light up
            // half the form the moment it opened.
            const before = original[key] ?? '';
            const after = form[key] ?? '';

            if (String(before) !== String(after)) {
                moved.add(key);
            }
        }

        for (const key of Object.keys(custom)) {
            if (JSON.stringify(originalCustom[key] ?? '') !== JSON.stringify(custom[key] ?? '')) {
                moved.add(`custom.${key}`);
            }
        }

        return moved;
    }, [form, original, custom, originalCustom]);

    const linesChanged = useMemo(
        () => JSON.stringify(lines) !== JSON.stringify(originalLines),
        [lines, originalLines],
    );

    const dirty = changed.size > 0 || linesChanged;

    const save = useMutation({
        mutationFn: () => {
            /*
             * Only what this form is allowed to set.
             *
             * `number` identifies the order and is the shop's to issue. The four
             * derived figures are worked out from the lines on the way in — see
             * OrderTotals — and the endpoint now refuses them outright rather
             * than accepting and discarding them.
             */
            const {
                number: _identifier,
                subtotal: _subtotal,
                discount: _discount,
                total: _total,
                paid: _paid,
                currency: _currency,
                fulfilment_status: _fulfilment,
                channel: _channel,
                ...editable
            } = form;

            return api.patch(`/orders/${orderId}`, { ...editable, custom, lines });
        },
        onSuccess: (result) => {
            const shaped = (result ?? null) as { message?: string } | null;

            toast.success(shaped?.message ?? 'Order saved.');

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

    const set = (key: string) => (next: unknown) =>
        setForm((current) => ({ ...current, [key]: next }));

    const val = (key: string): string => {
        const raw = form[key];

        return raw === null || raw === undefined ? '' : String(raw);
    };

    /**
     * Whether this shop sends a field.
     *
     * Answered by the server, which knows both names for it: this form has one
     * Name box where the mapping offers first and last separately, and matching
     * on the form's own name reported that a shop syncing the name did not.
     */
    const syncs = (key: string): boolean => editor.fields[key]?.mapped === true;

    /**
     * The marks beside a label: whether it travels, and whether you moved it.
     *
     * Both are small and quiet. A form where every label carries a badge is a
     * form where the badges are wallpaper — these are worth noticing precisely
     * because most labels have none.
     */
    const marks = (key: string) => (
        <>
            {syncs(key) && (
                <span
                    className="inline-flex text-[var(--color-brand)]"
                    title="This shop sends this field, so your change travels back to it when you save"
                >
                    <Icon name="arrows-left-right" size={11} />
                </span>
            )}

            {changed.has(key) && (
                <span
                    className="inline-flex size-1.5 rounded-full bg-[var(--color-warning)]"
                    title="Changed, and not saved yet"
                    aria-label="Changed"
                />
            )}
        </>
    );


    /**
     * One box, drawn as whatever the mapping says it is.
     *
     * ── Why the form no longer chooses ───────────────────────────────────────
     *
     * It used to. Somebody wrote a text box for the country because a country
     * is words, and one for the billing city because a city is words — and this
     * shop's billing city arrives as `BD-58-05`, a code with 581 legal answers,
     * displayed raw in a box you could type anything into. The mapping screen
     * two clicks away had been rendering it as "Satkhira Sadar" the whole time,
     * because it read the type instead of the name.
     *
     * So the type decides, and it comes from the same place everything else in
     * this application reads it from. A field whose mapping this shop has
     * overridden takes the shop's type; everything else takes the declared
     * default. Adding a field, or re-typing one on the mapping screen, changes
     * this form without anybody editing it.
     */
    const Field = ({
        name,
        label,
        info,
        hint,
        wide,
        rows,
    }: {
        name: string;
        /** Overrides the mapping's own wording, which is written for that screen. */
        label?: string;
        info?: ReactNode;
        hint?: ReactNode;
        wide?: boolean;
        rows?: number;
    }) => {
        const meta = editor.fields[name];
        const control = controlFor(meta?.type);
        const shared = {
            label: label ?? meta?.label ?? name,
            badge: marks(name),
            info,
            hint,
            wide: wide ?? isWide(meta?.type),
        };

        if (control === 'options') {
            return (
                <SelectField
                    {...shared}
                    value={val(name)}
                    onChange={set(name)}
                    placeholder="Not set"
                    options={[
                        // A way back to no answer. Without it a country picked
                        // by mistake can be changed but never cleared.
                        { value: '', label: 'Not set' },
                        ...(meta?.options ?? []),
                    ]}
                />
            );
        }

        if (control === 'switch') {
            return (
                <SwitchField {...shared} value={form[name] === true} onChange={set(name)} />
            );
        }

        if (control === 'money') {
            return (
                <MoneyField
                    {...shared}
                    symbol={editor.symbol}
                    value={val(name)}
                    onChange={set(name)}
                />
            );
        }

        if (control === 'textarea' || control === 'code' || control === 'lines') {
            return (
                <TextAreaField
                    {...shared}
                    rows={rows ?? (control === 'code' ? 6 : 3)}
                    mono={control === 'code'}
                    value={val(name)}
                    onChange={set(name)}
                />
            );
        }

        return (
            <TextField
                {...shared}
                type={
                    control === 'email'
                        ? 'email'
                        : control === 'tel'
                          ? 'tel'
                          : control === 'url'
                            ? 'url'
                            : control === 'date'
                              ? 'date'
                              : control === 'datetime'
                                ? 'datetime-local'
                                : control === 'number'
                                  ? 'number'
                                  : 'text'
                }
                value={val(name)}
                onChange={set(name)}
            />
        );
    };

    /*
     * ── A shop's own fields, sorted into the form's own sections ────────────
     *
     * `placementFor` reads the field's type first and its name second, and
     * returns which section it belongs in. It is a guess and is allowed to be
     * wrong; the cost of wrong is a field one section from where somebody
     * looked, which is what the old behaviour — one box at the foot of the form
     * for all of them — cost on every field every time.
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

    const extras = (where: Placement) =>
        placed[where].map((f) => (
            <div key={f.key} className={isWide(f.type) ? 'sm:col-span-2' : undefined}>
                <CustomField
                    field={f}
                    value={custom[f.key]}
                    onChange={(next) => setCustom((current) => ({ ...current, [f.key]: next }))}
                />
            </div>
        ));

    /*
     * ── The arithmetic, mirrored from the server ────────────────────────────
     *
     * The same rule as OrderTotals, so the figures move as somebody edits a
     * line rather than after they save and reopen. The server's answer is the
     * one that gets stored; this is the preview of it, and the two have to
     * agree or the preview is a lie.
     */
    const totals = (() => {
        let atList = 0;
        let charged = 0;

        for (const line of lines) {
            const unit = Number(line.unit_price) || 0;
            const quantity = Number(line.quantity) || 0;

            // Below what was charged it is ignored: a product repriced downward
            // since the order was placed would otherwise show a negative
            // discount, which reads as the customer having paid extra.
            const list = Math.max(Number(line.list_price ?? unit) || 0, unit);

            atList += list * quantity;
            charged += unit * quantity;
        }

        const shipping = Number(form.shipping) || 0;
        const tax = Number(form.tax) || 0;
        const paid = Number(form.paid) || 0;
        const total = charged + shipping + tax;

        return {
            subtotal: atList,
            discount: atList - charged,
            total,
            paid,
            outstanding: Math.round((total - paid) * 100) / 100,
        };
    })();

    const statusOptions = editor.statuses.map((s) => ({
        value: s.value,
        label: s.label,
        note: s.custom ? 'custom' : undefined,
    }));

    const customerName = String(form.customer_name ?? '').trim();

    return (
        <div className="flex h-full min-h-0 flex-col">
            {/*
              ── Which order this is, wherever you have scrolled to ───────────

              A form thirty fields long scrolls its own title off the screen
              within a second of being opened, and from then on there is nothing
              to check against. This does not move.
            */}
            <div className="shrink-0 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-site-bg)] px-4 py-2.5">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="font-semibold text-[var(--color-text-main)]">
                        {editor.number}
                    </span>

                    <span className="text-[var(--shell-border)]">|</span>

                    <span className="truncate text-sm text-[var(--color-text-body)]">
                        {customerName === '' ? 'No customer name' : customerName}
                    </span>

                    <span className="truncate text-sm text-[var(--color-text-muted)]">
                        {editor.shop ?? 'Walk-in / counter'}
                    </span>

                    <span className="ml-auto flex items-center gap-2">
                        {dirty ? (
                            <span className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-warning-text,#92400e)]">
                                <span className="size-1.5 rounded-full bg-[var(--color-warning)]" />
                                {changed.size + (linesChanged ? 1 : 0)} unsaved change
                                {changed.size + (linesChanged ? 1 : 0) === 1 ? '' : 's'}
                            </span>
                        ) : (
                            <span className="text-xs text-[var(--color-text-subtle)]">
                                No changes yet
                            </span>
                        )}
                    </span>
                </div>
            </div>

            {/*
              A fixed rail rather than a share of the width.

              At one-third, the rail grew with the drawer and the form did not —
              and the form is where the width is needed, because an order line's
              description is prose in a table. The rail holds three short facts
              and a button; 17rem is enough for all of them and every pixel
              past that is taken from the thing being edited.
            */}
            <div className="mt-4 grid min-h-0 flex-1 items-start gap-5 overflow-y-auto pb-2 lg:grid-cols-[minmax(0,1fr)_17rem]">
                {/* ── What the order is ────────────────────────────────────── */}
                <div className="space-y-5">
                    <FieldGroup title="Order" icon="shopping-bag" hint={editor.number}>
                        <FieldGrid>
                            <SelectField
                                label="Status"
                                badge={marks('status')}
                                value={val('status')}
                                onChange={set('status')}
                                options={statusOptions}
                                info="Where the order has got to. Custom statuses are ones this business added; the rest are the ones every order can be in."
                            />

                            <SelectField
                                label="Payment"
                                badge={marks('payment_status')}
                                value={val('payment_status')}
                                onChange={set('payment_status')}
                                options={[
                                    { value: 'paid', label: 'Paid' },
                                    { value: 'unpaid', label: 'Unpaid' },
                                ]}
                                info="Set from the payments recorded against this order. Change it here only to correct a mistake."
                            />

                            <SelectField
                                label="Store"
                                badge={marks('storefront_id')}
                                value={val('storefront_id')}
                                onChange={set('storefront_id')}
                                options={[
                                    { value: '', label: 'Walk-in / counter' },
                                    ...editor.storefronts.map((shop) => ({
                                        value: shop.id,
                                        label: shop.name,
                                    })),
                                ]}
                                info="Which shop this sale belongs to. It decides the tag on the order number, the currency, and where a change travels when it is pushed back."
                            />

                            <Field name="ordered_on" label="Order date" />

                            <Field
                                name="external_ref"
                                label="External reference"
                                info="Your own reference for this order, if you use one. Not the shop's number."
                            />

                            {extras('dates')}

                            <Field
                                name="is_cod"
                                label="Cash on delivery"
                                wide
                                hint="The courier collects the money when the parcel is handed over."
                            />
                        </FieldGrid>
                    </FieldGroup>

                    <FieldGroup title="Customer" icon="user">
                        <FieldGrid>
                            <Field name="customer_name" label="Name" />
                            <Field name="customer_email" label="Email" />
                            <Field name="customer_phone" label="Phone" />
                            <Field name="customer_company" label="Company" />
                            <Field name="customer_tax_number" label="Tax number" />
                            {extras('customer')}
                        </FieldGrid>
                    </FieldGroup>

                    <FieldGroup
                        title="Billing address"
                        icon="receipt"
                        info="Held on the customer, not on this order — so a change here shows on their other orders too."
                    >
                        <FieldGrid>
                            <Field name="customer_billing_address" label="Address" wide />

                            {/*
                              Labelled City, typed by the mapping.

                              On this shop it arrives from `_shipping_thana` as
                              `BD-58-05` and is drawn as a picker of 581 thanas
                              showing "Satkhira Sadar"; on a shop that sends a
                              plain city name it is a text box. The label stays
                              the same because the question is the same.
                            */}
                            <Field name="customer_billing_city" label="City" />
                            <Field name="customer_billing_postcode" label="Postcode" />
                            <Field name="customer_billing_country" label="Country" />
                        </FieldGrid>
                    </FieldGroup>

                    <FieldGroup
                        title="Delivery address"
                        icon="map-pin"
                        info="Held on this order, so it can differ from the customer's usual address without changing it."
                    >
                        <FieldGrid>
                            <Field name="shipping_name" label="Recipient" />
                            <Field name="shipping_phone" label="Phone" />
                            <Field name="shipping_address" label="Address" wide />
                            <Field name="shipping_city" label="City" />
                            <Field name="shipping_postcode" label="Postcode" />
                            <Field name="shipping_country" label="Country" />
                            {extras('delivery')}
                        </FieldGrid>
                    </FieldGroup>

                    {/*
                      Above the money, because it produces it. Reading down the
                      column the order is: what was bought, then what that came
                      to. The reverse asks somebody to accept a total before
                      seeing the lines behind it.
                    */}
                    <FieldGroup
                        title="Items"
                        icon="package"
                        hint={`${lines.length} line${lines.length === 1 ? '' : 's'}`}
                        flush
                    >
                        <LineItems
                            lines={lines}
                            currency={String(editor.values.currency ?? '')}
                            symbol={editor.symbol}
                            onChange={setLines}
                        />
                    </FieldGroup>

                    <FieldGroup
                        title="Money"
                        icon="currency-circle-dollar"
                        hint={String(editor.values.currency ?? '')}
                    >
                        <FieldGrid>
                            <Field
                                name="shipping"
                                label="Shipping"
                                info="What you charged the customer for delivery — not what the courier charges you."
                            />
                            <Field name="tax" label="Tax" />
                            {extras('payment')}
                        </FieldGrid>

                        {/*
                          ── The four figures nobody types ────────────────────

                          Facts rather than greyed-out boxes. A disabled input
                          says "you may not change this", which invites the
                          question of who may; these are simply what the lines
                          add up to, and the way to alter one is to alter a
                          line. See OrderTotals.
                        */}
                        <div className="mt-4 border-t border-[var(--shell-border)] pt-4">
                            <div className="grid gap-4 sm:grid-cols-4">
                                <ReadOnlyField
                                    label="Subtotal"
                                    value={money(editor.symbol, totals.subtotal)}
                                    badge={marks('subtotal')}
                                    info="What these items normally sell for, before any discount."
                                />
                                <ReadOnlyField
                                    label="Discount"
                                    value={
                                        totals.discount > 0
                                            ? `−${money(editor.symbol, totals.discount)}`
                                            : money(editor.symbol, 0)
                                    }
                                    badge={marks('discount')}
                                    info="How much less than the usual price was charged, added up across the lines. Change a line's price to change it."
                                />
                                <ReadOnlyField
                                    label="Paid"
                                    value={money(editor.symbol, totals.paid)}
                                    badge={marks('paid')}
                                    info="The payments recorded against this order. Record a payment to change it."
                                />
                                <ReadOnlyField
                                    label="Total"
                                    strong
                                    value={money(editor.symbol, totals.total)}
                                    badge={marks('total')}
                                    hint={
                                        totals.outstanding > 0
                                            ? `${money(editor.symbol, totals.outstanding)} outstanding`
                                            : undefined
                                    }
                                />
                            </div>
                        </div>
                    </FieldGroup>

                    <FieldGroup title="Notes" icon="note">
                        <div className="space-y-4">
                            <Field
                                name="notes"
                                label="Order notes"
                                rows={3}
                                info="About this order. Kept here, and sent to the shop if it maps the field."
                            />
                            <Field
                                name="customer_notes"
                                label="Customer notes"
                                rows={2}
                                info="About the customer, on their record — so it shows on every order they place."
                            />
                        </div>
                    </FieldGroup>

                    {/*
                      What is left, and only what is left. A field whose name
                      says nothing this form recognises — `order_source`,
                      `_ga_tracked` — has no section it belongs to, and putting
                      it in one on a weak match would be a confident lie.
                    */}
                    {placed.other.length > 0 && (
                        <FieldGroup
                            title="Other details"
                            icon="dots-three-circle"
                            hint="From this shop"
                            info="Fields this shop sends that do not match anything on the form above. They are kept and sent back unchanged."
                        >
                            <FieldGrid>{extras('other')}</FieldGrid>
                        </FieldGroup>
                    )}
                </div>

                {/* ── What the order carries ───────────────────────────────── */}
                <div className="space-y-5">
                    <FieldGroup title="Source" icon="storefront">
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
                                    {editor.mapped.length} fields sync with this shop. The ones
                                    marked travel back when you save.
                                </span>
                            </p>
                        )}
                    </FieldGroup>

                    {/*
                      Sending it, rather than declaring it sent.

                      This replaced a Fulfilment dropdown offering "dispatched"
                      and "not dispatched" as though they were things somebody
                      decides. They are not — they are what becomes true once an
                      order is handed to a courier, and a field that let you
                      claim either without doing it could only ever disagree
                      with the courier.
                    */}
                    <FieldGroup
                        title="Delivery"
                        icon="truck"
                        info="Sending happens straight away when you press the button — it is not part of your unsaved changes."
                    >
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
                            <div className="space-y-2">
                                <SelectField
                                    label="Send to"
                                    value={courier}
                                    onChange={setCourier}
                                    disabled={dispatching}
                                    placeholder="Choose a courier…"
                                    options={editor.couriers.map((c) => ({
                                        value: c.id,
                                        label: c.label ?? 'Courier',
                                    }))}
                                />

                                <button
                                    type="button"
                                    className="btn btn-secondary w-full text-sm"
                                    disabled={courier === '' || dispatching}
                                    onClick={() => sendToCourier()}
                                >
                                    {dispatching ? 'Sending…' : 'Send this order'}
                                </button>
                            </div>
                        )}
                    </FieldGroup>

                    <FieldGroup
                        title="Attachments"
                        icon="paperclip"
                        hint={placed.media.length > 0 ? undefined : 'None defined'}
                    >
                        {placed.media.length > 0 ? (
                            <div className="space-y-4">
                                {placed.media.map((f) => (
                                    <CustomField
                                        key={f.key}
                                        field={f}
                                        value={custom[f.key]}
                                        onChange={(next) =>
                                            setCustom((current) => ({ ...current, [f.key]: next }))
                                        }
                                    />
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-[var(--color-text-subtle)]">
                                Pictures, files and videos appear here when this business defines a
                                field for them.
                            </p>
                        )}
                    </FieldGroup>
                </div>
            </div>

            {/* ── Save ─────────────────────────────────────────────────────── */}
            <div className="mt-4 flex shrink-0 items-center justify-end gap-3 border-t border-[var(--shell-border)] pt-4">
                {dirty && (
                    <span className="mr-auto flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
                        <InfoHint label="What will be saved">
                            Changed fields are marked with a dot beside their label. Cancelling
                            leaves the order exactly as it was.
                        </InfoHint>
                        Marked fields will be saved
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
