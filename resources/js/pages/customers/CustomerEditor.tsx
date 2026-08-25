import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
    FieldGrid,
    FieldGroup,
    SelectField,
    TextAreaField,
    TextField,
} from '@/components/ui/Form/Fields';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

type Customer = {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    company: string | null;
    tax_number: string | null;
    billing_address: string | null;
    billing_city: string | null;
    billing_postcode: string | null;
    billing_country: string | null;
    payment_terms_days: number | null;
    notes: string | null;
};

/**
 * The countries, fetched once and shared by every form that needs them.
 *
 * The endpoint answers with the whole record — code, name, currency, time
 * zones — because other callers use the rest of it. Reshaped here rather than
 * there, so a picker's idea of an option does not become an API's problem.
 */
function useCountries() {
    const { data } = useQuery<{ data: Array<{ code: string; name: string }> }>({
        queryKey: ['countries'],
        queryFn: ({ signal }) => api.get('/workspaces/countries', { signal }),
        staleTime: Infinity,
    });

    return useMemo(
        () => (data?.data ?? []).map((one) => ({ value: one.code, label: one.name })),
        [data],
    );
}

/**
 * Editing a customer, wherever you arrived from.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * Two reasons, and the second is the interesting one.
 *
 * The Customers page had an Edit button with no `onClick`. It looked like the
 * feature and did nothing, which is worse than not offering it.
 *
 * And the order form was editing customers. Ten boxes on it — name, email,
 * phone, company, tax number, four lines of billing address, notes — wrote
 * straight to the customer record, which meant a change made while looking at
 * one order silently altered the other twelve that person had placed, with
 * nothing on the screen saying so. A customer is a record in its own right; the
 * order references one.
 *
 * ── Why the same component serves both ───────────────────────────────────────
 *
 * Because it is the same job. What differs is only where closing takes you, and
 * that is the caller's business: on the Customers page it goes back to the
 * list, and opened from an order it goes back to the order, which never went
 * away underneath.
 */
export function CustomerEditor({
    customerId,
    onClose,
    onSaved,
}: {
    customerId: string;
    onClose: () => void;
    /** Told the new name, so a caller showing it can update without refetching. */
    onSaved?: (customer: Customer) => void;
}) {
    const queryClient = useQueryClient();
    const countries = useCountries();

    const { data, isLoading, isError } = useQuery<{ data: Customer }>({
        queryKey: ['customer', customerId],
        queryFn: ({ signal }) => api.get(`/customers/${customerId}`, { signal }),
        staleTime: 0,
    });

    const [form, setForm] = useState<Record<string, unknown>>({});
    const [original, setOriginal] = useState<Record<string, unknown>>({});

    useEffect(() => {
        if (!data) {
            return;
        }

        const { id: _id, ...fields } = data.data;

        setForm({ ...fields });
        setOriginal({ ...fields });
    }, [data]);

    /*
     * What differs from what was loaded.
     *
     * The same treatment the order form gets, for the same reason: before
     * pressing Cancel on a form with eleven boxes, "something changed" is not
     * the half anybody wants.
     */
    const changed = useMemo(() => {
        const moved = new Set<string>();

        for (const key of Object.keys(form)) {
            if (String(original[key] ?? '') !== String(form[key] ?? '')) {
                moved.add(key);
            }
        }

        return moved;
    }, [form, original]);

    const save = useMutation({
        mutationFn: () => api.patch(`/customers/${customerId}`, form),
        onSuccess: (result) => {
            const saved = (result as { data: Customer }).data;

            toast.success('Customer saved.');

            void queryClient.invalidateQueries({ queryKey: ['customers'] });
            void queryClient.invalidateQueries({ queryKey: ['customer', customerId] });

            /*
             * The orders too. A customer's name is shown on every order they
             * have placed, and leaving those cached would show the old one
             * until something else happened to refresh them.
             */
            void queryClient.invalidateQueries({ queryKey: ['orders'] });
            void queryClient.invalidateQueries({ queryKey: ['order-editor'] });

            onSaved?.(saved);
            onClose();
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    if (isLoading) {
        return (
            <div className="flex h-48 items-center justify-center">
                <Icon
                    name="circle-notch"
                    size={22}
                    className="animate-spin text-[var(--color-text-muted)]"
                />
            </div>
        );
    }

    if (isError || !data) {
        return (
            <p className="py-10 text-center text-sm text-[var(--color-text-muted)]">
                This customer could not be opened.
            </p>
        );
    }

    const set = (key: string) => (next: unknown) =>
        setForm((current) => ({ ...current, [key]: next }));

    const val = (key: string): string => {
        const raw = form[key];

        return raw === null || raw === undefined ? '' : String(raw);
    };

    /** A dot beside a label that has moved. */
    const mark = (key: string) =>
        changed.has(key) ? (
            <span
                className="inline-flex size-1.5 rounded-full bg-[var(--color-warning)]"
                title="Changed, and not saved yet"
                aria-label="Changed"
            />
        ) : null;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto pb-2">
                <FieldGroup title="Who they are" icon="user">
                    <FieldGrid>
                        <TextField
                            label="Name"
                            badge={mark('name')}
                            value={val('name')}
                            onChange={set('name')}
                        />
                        <TextField
                            label="Company"
                            badge={mark('company')}
                            value={val('company')}
                            onChange={set('company')}
                        />
                        <TextField
                            label="Email"
                            type="email"
                            badge={mark('email')}
                            value={val('email')}
                            onChange={set('email')}
                        />
                        <TextField
                            label="Phone"
                            type="tel"
                            badge={mark('phone')}
                            value={val('phone')}
                            onChange={set('phone')}
                        />
                        <TextField
                            label="Tax number"
                            badge={mark('tax_number')}
                            value={val('tax_number')}
                            onChange={set('tax_number')}
                            info="Their VAT or BIN, printed on invoices where the law asks for it."
                        />
                        <TextField
                            label="Payment terms"
                            type="number"
                            badge={mark('payment_terms_days')}
                            value={val('payment_terms_days')}
                            onChange={set('payment_terms_days')}
                            hint="Days"
                            info="How long after an invoice this customer normally has to pay. Leave blank for on-delivery."
                        />
                    </FieldGrid>
                </FieldGroup>

                <FieldGroup
                    title="Billing address"
                    icon="receipt"
                    info="Where invoices go. An order can be delivered somewhere else — that address is kept on the order."
                >
                    <FieldGrid>
                        <TextField
                            label="Address"
                            wide
                            badge={mark('billing_address')}
                            value={val('billing_address')}
                            onChange={set('billing_address')}
                        />
                        <TextField
                            label="City"
                            badge={mark('billing_city')}
                            value={val('billing_city')}
                            onChange={set('billing_city')}
                        />
                        <TextField
                            label="Postcode"
                            badge={mark('billing_postcode')}
                            value={val('billing_postcode')}
                            onChange={set('billing_postcode')}
                        />
                        <SelectField
                            label="Country"
                            badge={mark('billing_country')}
                            value={val('billing_country')}
                            onChange={set('billing_country')}
                            placeholder="Not set"
                            options={[{ value: '', label: 'Not set' }, ...countries]}
                        />
                    </FieldGrid>
                </FieldGroup>

                <FieldGroup title="Notes" icon="note">
                    <TextAreaField
                        label="About this customer"
                        badge={mark('notes')}
                        value={val('notes')}
                        onChange={set('notes')}
                        info="Shown on every order they place. For something about one order, use that order's own note."
                    />
                </FieldGroup>
            </div>

            <div className="mt-4 flex shrink-0 items-center justify-end gap-3 border-t border-[var(--shell-border)] pt-4">
                {changed.size > 0 && (
                    <span className="mr-auto flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
                        <span className="size-1.5 rounded-full bg-[var(--color-warning)]" />
                        {changed.size} unsaved change{changed.size === 1 ? '' : 's'}
                    </span>
                )}

                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    Cancel
                </button>

                <button
                    type="button"
                    className="btn btn-primary"
                    disabled={changed.size === 0 || save.isPending}
                    onClick={() => save.mutate()}
                >
                    {save.isPending ? 'Saving…' : 'Save customer'}
                </button>
            </div>
        </div>
    );
}
