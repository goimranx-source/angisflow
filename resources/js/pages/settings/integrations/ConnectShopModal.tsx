import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { WebhookPanel } from './WebhookPanel';

import type { Connection, ConfigField, Kind, Platform } from './types';

/**
 * Connecting a shop, or changing one that is already connected.
 *
 * ── The form is not written here ─────────────────────────────────────────────
 *
 * Every field below comes from the chosen platform's own driver, over the
 * catalogue endpoint. WooCommerce asks for four things and a bespoke REST site
 * asks for thirteen, and this file knows neither number. That is what makes
 * "any platform" achievable: connecting a shop nobody anticipated is somebody
 * filling in a form, not us shipping a release.
 *
 * ── Test before save, always ─────────────────────────────────────────────────
 *
 * Saving credentials that do not work produces a connection that looks fine and
 * fails overnight, with the person who typed them long gone. So the test runs
 * against the unsaved values, and a failure is shown in the words the driver
 * chose — "the shop answered but refused these credentials" rather than a 401.
 */
export function ConnectShopModal({
    platforms,
    kinds,
    stores,
    connection,
    onClose,
    onSaved,
}: {
    platforms: Platform[];
    kinds: Kind[];
    stores: Array<{ id: string; name: string }>;
    connection: Connection | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const queryClient = useQueryClient();

    const editing = connection !== null;

    // Multi-step state
    const [step, setStep] = useState(1);
    const totalSteps = editing ? 1 : 3; // Edit mode is single step

    /*
     * What is being connected, before which platform.
     *
     * An integration is not only a shop — a courier, or anything else with an
     * API, is connected the same way. The kind decides which platforms are
     * offered and whether a storefront is asked for at all, so it is the first
     * question and it is shown on an existing connection too.
     */
    const [kind, setKind] = useState(connection?.kind ?? 'store');
    const [provider, setProvider] = useState(connection?.provider ?? '');
    const [name, setName] = useState(connection?.name ?? '');

    /*
     * Which of our shops this feeds.
     *
     * '__new' means "create one from the name below" — offered because a
     * business connecting its first shop has no storefront to pick, and sending
     * somebody away to create one mid-form is how setup gets abandoned.
     */
    const [store, setStore] = useState(connection?.store?.id ?? (stores.length > 0 ? stores[0]!.id : '__new'));
    const [newStoreName, setNewStoreName] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [tested, setTested] = useState<{ ok: boolean; message: string } | null>(null);

    const platform = useMemo(
        () => platforms.find((p) => p.key === provider) ?? null,
        [platforms, provider],
    );

    const offered = useMemo(
        () => platforms.filter((p) => p.kinds.includes(kind)),
        [platforms, kind],
    );

    const [platformSearch, setPlatformSearch] = useState('');

    /*
     * The offered platforms, filtered and gathered under their headings.
     *
     * Groups keep the order the catalogue gives them rather than sorting
     * alphabetically: the ones most businesses actually run should be read
     * first, and an alphabet puts Amazon above WooCommerce for nobody's
     * benefit.
     */
    const grouped = useMemo(() => {
        const term = platformSearch.trim().toLowerCase();

        const matching =
            term === ''
                ? offered
                : offered.filter(
                      (p) => p.label.toLowerCase().includes(term) || p.key.includes(term),
                  );

        const order: string[] = [];
        const buckets = new Map<string, typeof matching>();

        for (const platform of matching) {
            const name = platform.group ?? 'Anything else';

            if (!buckets.has(name)) {
                buckets.set(name, []);
                order.push(name);
            }

            buckets.get(name)!.push(platform);
        }

        return order.map((name) => [name, buckets.get(name)!] as const);
    }, [offered, platformSearch]);

    // The fields of a connection being edited come from the connection itself,
    // so the form still works if the catalogue has not arrived yet.
    const fields: ConfigField[] = connection?.fields ?? platform?.fields ?? [];

    useEffect(() => {
        if (!connection) {
            setValues({});
            return;
        }

        // A stored secret comes back masked and must stay masked: putting the
        // marker into the input would save the marker as the credential.
        const seed: Record<string, string> = {};
        for (const field of connection.fields) {
            if (field.secret) continue;
            const value = connection.configuration[field.key];
            if (value !== undefined && value !== null) seed[field.key] = String(value);
        }
        setValues(seed);
    }, [connection]);

    // A changed value invalidates the last test — a green tick beside
    // credentials that have since been edited is worse than no tick.
    const set = (key: string, value: string) => {
        setValues((current) => ({ ...current, [key]: value }));
        setTested(null);
    };

    const test = useMutation({
        mutationFn: () =>
            api.post<{ ok: boolean; message: string }>('/settings/integrations/test', {
                provider,
                configuration: values,
                ...(connection ? { id: connection.id } : {}),
            }),
        onSuccess: (result) => setTested({ ok: true, message: result.message ?? 'Connected.' }),
        onError: (error: Error) => setTested({ ok: false, message: error.message }),
    });

    // Either an existing shop's id, or a name to create one from — never both.
    const storePayload = () =>
        store === '__new'
            ? { new_store_name: (newStoreName.trim() || name.trim()), storefront_id: null }
            : { storefront_id: store };

    const save = useMutation({
        mutationFn: () =>
            editing
                ? api.patch(`/settings/integrations/${connection.id}`, {
                      name,
                      configuration: values,
                      ...storePayload(),
                  })
                : api.post('/settings/integrations', {
                      provider,
                      kind,
                      name,
                      configuration: values,
                      bidirectional: true, // Default to both ways sync
                      ...storePayload(),
                  }),
        onSuccess: () => {
            toast.success(editing ? 'Connection updated.' : `${name} connected.`);
            void queryClient.invalidateQueries({ queryKey: ['settings', 'integrations'] });
            onSaved();
        },
        onError: (error: Error) => toast.error(error.message || 'That could not be saved.'),
    });

    const missing = fields.filter(
        (f) => f.required && !f.secret && !String(values[f.key] ?? '').trim(),
    );

    // Validation per step
    const canProceedStep1 = kind !== '' && provider !== '';
    const canProceedStep2 = name.trim() !== '' && (kind !== 'store' || store !== '' || newStoreName.trim() !== '');
    const canProceedStep3 = editing || missing.length === 0;

    const handleNext = () => {
        if (step === 1 && canProceedStep1) setStep(2);
        else if (step === 2 && canProceedStep2) setStep(3);
    };

    const handleBack = () => {
        if (step > 1) setStep(step - 1);
    };

    const getStepTitle = () => {
        if (editing) return `${connection.name} settings`;
        switch (step) {
            case 1: return 'Choose Platform';
            case 2: return 'Name & Storefront';
            case 3: return 'API Credentials';
            default: return 'Connect Integration';
        }
    };

    return (
        <Modal open onClose={onClose} title={getStepTitle()} size="lg">
            {/* Progress Indicator */}
            {!editing && (
                <div className="mb-6 flex items-center justify-between">
                    {[1, 2, 3].map((s) => (
                        <div key={s} className="flex flex-1 items-center">
                            <div className="flex items-center gap-2">
                                <div
                                    className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium transition ${
                                        s < step
                                            ? 'bg-[var(--color-success)] text-white'
                                            : s === step
                                            ? 'bg-[var(--color-brand)] text-white'
                                            : 'border border-[var(--shell-border)] text-[var(--color-text-muted)]'
                                    }`}
                                >
                                    {s < step ? <Icon name="check" size={14} /> : s}
                                </div>
                                <span className={`hidden text-xs sm:inline ${s === step ? 'font-medium' : 'text-[var(--color-text-muted)]'}`}>
                                    {s === 1 ? 'Platform' : s === 2 ? 'Details' : 'Credentials'}
                                </span>
                            </div>
                            {s < 3 && (
                                <div
                                    className={`mx-2 h-0.5 flex-1 transition ${
                                        s < step ? 'bg-[var(--color-success)]' : 'bg-[var(--shell-border)]'
                                    }`}
                                />
                            )}
                        </div>
                    ))}
                </div>
            )}

            <div className="space-y-5">
                {/* STEP 1: Choose Platform */}
                {(editing || step === 1) && (
                    <>
                        <div>
                            <label className="mb-2 block text-sm font-medium">What are you connecting?</label>
                            <div className="flex flex-wrap gap-2">
                                {kinds.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        disabled={editing}
                                        onClick={() => {
                                            setKind(option.value);
                                            setProvider('');
                                            setValues({});
                                            setTested(null);
                                        }}
                                        title={option.help}
                                        className="rounded-[var(--shell-radius)] border px-3 py-1.5 text-sm transition disabled:opacity-60 hover:border-[var(--color-brand)]"
                                        style={{
                                            borderColor:
                                                kind === option.value ? 'var(--color-brand)' : 'var(--shell-border)',
                                            color: kind === option.value ? 'var(--color-brand)' : undefined,
                                        }}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {!editing && (
                            <div>
                                <label className="mb-2 block text-sm font-medium">Select Platform</label>

                                {/*
                                  Searchable, because the list is long.

                                  Thirty-six platforms in one grid is a wall
                                  somebody reads rather than scans, and the one
                                  they run is as likely to be at the bottom as
                                  the top. Typing three letters of it beats any
                                  arrangement.
                                */}
                                <input
                                    className="field mb-3 w-full"
                                    placeholder="Search platforms…"
                                    value={platformSearch}
                                    onChange={(event) => setPlatformSearch(event.target.value)}
                                />

                                {grouped.length === 0 && (
                                    <p className="rounded-[var(--shell-radius)] border border-dashed border-[var(--shell-border)] p-4 text-center text-sm text-[var(--color-text-subtle)]">
                                        Nothing matches. Anything with a REST API can still be connected as
                                        a custom site.
                                    </p>
                                )}

                                {grouped.map(([groupName, items]) => (
                                    <div key={groupName} className="mb-4 last:mb-0">
                                        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                                            {groupName}
                                        </p>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {items.map((option) => (
                                        <button
                                            key={option.key}
                                            type="button"
                                            onClick={() => {
                                                setProvider(option.key);
                                                setValues({});
                                                setTested(null);
                                                if (!name.trim()) setName(option.label);
                                            }}
                                            className="rounded-[var(--shell-radius)] border p-4 text-left transition hover:border-[var(--color-brand)] hover:shadow-sm"
                                            style={{
                                                borderColor:
                                                    provider === option.key ? 'var(--color-brand)' : 'var(--shell-border)',
                                                backgroundColor:
                                                    provider === option.key ? 'var(--color-brand-muted)' : undefined,
                                            }}
                                        >
                                            <span className="block font-medium">{option.label}</span>
                                            <span className="mt-1 block text-xs text-[var(--color-text-subtle)]">
                                                {option.capabilities.entities.join(', ')}
                                                {option.capabilities.can_sync_both_ways ? ' · two-way' : ' · one-way'}
                                            </span>

                                            {/*
                                              Said plainly on the card.

                                              Without it a platform nobody has
                                              tested looks identical to one in
                                              daily use, and somebody picks the
                                              first on the strength of the
                                              second.
                                            */}
                                            {option.support && option.support !== 'built' && (
                                                <span
                                                    className="mt-2 inline-block rounded-full bg-[var(--shell-muted)] px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-muted)]"
                                                    title={
                                                        option.support === 'preset'
                                                            ? 'Set up for you, but not tested against this platform by us — check the field mapping after connecting.'
                                                            : 'Listed so you can find it. Expect to fill in the paths and map fields by hand.'
                                                    }
                                                >
                                                    {option.support === 'preset' ? 'Prefilled' : 'Manual setup'}
                                                </span>
                                            )}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}

                {/* STEP 2: Name & Storefront */}
                {(editing || step === 2) && provider !== '' && (
                    <>
                        <div>
                            <label htmlFor="connection-name" className="mb-1.5 block text-sm font-medium">
                                Connection Name
                            </label>
                            <input
                                id="connection-name"
                                className="field w-full"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="e.g., Main Shop, Bangladesh Store"
                            />
                            <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                A friendly name to identify this connection
                            </p>
                        </div>

                        {kind === 'store' && (
                            <div>
                                <label htmlFor="connection-store" className="mb-1.5 block text-sm font-medium">
                                    Link to Storefront
                                </label>

                                <select
                                    id="connection-store"
                                    className="field w-full"
                                    value={store}
                                    onChange={(event) => setStore(event.target.value)}
                                >
                                    {stores.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.name}
                                        </option>
                                    ))}
                                    <option value="__new">＋ Create a new storefront</option>
                                </select>

                                {store === '__new' && (
                                    <>
                                        <input
                                            className="field mt-2 w-full"
                                            value={newStoreName}
                                            onChange={(event) => setNewStoreName(event.target.value)}
                                            placeholder={name.trim() || 'Storefront name'}
                                        />
                                        <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                            A new storefront will be created for this integration
                                        </p>
                                    </>
                                )}
                                <p className="mt-1 text-xs text-[var(--color-text-subtle)]">
                                    Orders from this integration will appear under this storefront
                                </p>
                            </div>
                        )}
                    </>
                )}

                {/* STEP 3: API Credentials */}
                {(editing || step === 3) && provider !== '' && (
                    <>
                        <div className="rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-bg-subtle)] p-4">
                            <div className="flex items-start gap-2">
                                <Icon name="info" size={16} className="mt-0.5 shrink-0 text-[var(--color-brand)]" />
                                <div className="text-sm">
                                    <p className="font-medium">API Credentials Required</p>
                                    <p className="mt-1 text-[var(--color-text-muted)]">
                                        Enter your {platform?.label} API credentials below. These are stored encrypted and used to sync data securely.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {fields.map((field) => (
                            <Field
                                key={field.key}
                                field={field}
                                value={values[field.key] ?? ''}
                                stored={Boolean(connection?.configuration[field.key])}
                                onChange={(value) => set(field.key, value)}
                            />
                        ))}

                        {/*
                          Whether the shop is actually calling us, and a button
                          that makes it. Only once saved: the token is
                          generated on create, so before that there is no
                          address to point anything at.
                        */}
                        {editing && connection.capabilities.supports_webhooks && (
                            <WebhookPanel connectionId={connection.id} fallbackUrl={connection.webhook_url} />
                        )}

                        {tested && (
                            <div
                                className="flex items-start gap-2 rounded-[var(--shell-radius)] border px-3 py-2 text-sm"
                                style={{
                                    borderColor: tested.ok ? 'var(--color-success)' : 'var(--color-danger)',
                                    color: tested.ok ? 'var(--color-success)' : 'var(--color-danger)',
                                }}
                            >
                                <Icon name={tested.ok ? 'check' : 'alert-triangle'} size={14} className="mt-0.5 shrink-0" />
                                <span>{tested.message}</span>
                            </div>
                        )}
                    </>
                )}
            </div>

            <div className="mt-6 flex flex-wrap justify-between gap-2">
                <div>
                    {!editing && step > 1 && (
                        <button type="button" className="btn btn-secondary" onClick={handleBack}>
                            <Icon name="arrow-left" size={14} />
                            Back
                        </button>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        Cancel
                    </button>

                    {step === 3 && (
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => test.mutate()}
                            disabled={provider === '' || test.isPending}
                        >
                            {test.isPending ? <Icon name="spinner" size={14} className="animate-spin" /> : <Icon name="plug" size={14} />}
                            Test
                        </button>
                    )}

                    {!editing && step < 3 ? (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleNext}
                            disabled={(step === 1 && !canProceedStep1) || (step === 2 && !canProceedStep2)}
                        >
                            Next
                            <Icon name="arrow-right" size={14} />
                        </button>
                    ) : (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => save.mutate()}
                            disabled={!canProceedStep3 || save.isPending}
                        >
                            {save.isPending && <Icon name="spinner" size={14} className="animate-spin" />}
                            {editing ? 'Save changes' : save.isPending ? 'Connecting...' : 'Connect'}
                        </button>
                    )}
                </div>
            </div>
        </Modal>
    );
}

function Field({
    field,
    value,
    stored,
    onChange,
}: {
    field: ConfigField;
    value: string;
    stored: boolean;
    onChange: (value: string) => void;
}) {
    const id = `cfg-${field.key}`;

    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
                {field.label}
                {!field.required && <span className="ml-1.5 text-xs text-[var(--color-text-subtle)]">optional</span>}
            </label>

            {field.type === 'select' ? (
                <select id={id} className="field w-full" value={value} onChange={(e) => onChange(e.target.value)}>
                    <option value="">Choose…</option>
                    {field.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            ) : (
                <input
                    id={id}
                    type={field.secret ? 'password' : field.type === 'url' ? 'url' : 'text'}
                    className="field w-full"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    // A stored secret is never rendered back. The placeholder
                    // says one is set, so leaving the box empty keeps it.
                    placeholder={field.secret && stored ? 'Set — leave blank to keep it' : (field.placeholder ?? '')}
                    autoComplete={field.secret ? 'new-password' : 'off'}
                />
            )}

            {field.help && <p className="mt-1 text-xs text-[var(--color-text-subtle)]">{field.help}</p>}
        </div>
    );
}
