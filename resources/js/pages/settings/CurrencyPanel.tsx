import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { useApiForm } from '@/hooks/useApiForm';
import { api, ApiError } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';
import type { CurrencyRate, SettingsPanel } from '@/types/settings';

import { SettingsCard } from './SettingsCard';

/**
 * What the books are counted in, and how foreign money is converted.
 *
 * ── Only ever a dozen rows on screen ─────────────────────────────────────────
 *
 * With every currency fetched, the list runs to a hundred and fifty. A settings
 * page that opens onto a hundred and fifty rows is a page nobody reads, so the
 * ones that matter — the currencies a business actually trades in — are sorted
 * to the top by the server and the rest are behind "show all". The first dozen
 * is usually the whole answer.
 */
export default function CurrencyPanel() {
    const { apply } = useSession();
    const queryClient = useQueryClient();
    const [showAll, setShowAll] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const { data, isPending, refetch } = useQuery({
        queryKey: ['settings', 'currency'],
        queryFn: ({ signal }) => api.get<{ data: SettingsPanel }>('/settings/currency', { signal }),
    });

    const panel = data?.data.currency;

    const serviceOwns = panel?.mode === 'auto';
    const visible = useMemo(
        () => (showAll ? (panel?.rates ?? []) : (panel?.rates ?? []).slice(0, 12)),
        [panel?.rates, showAll],
    );

    // ── The base currency ────────────────────────────────────────────────

    const changeBase = async (code: string) => {
        if (!panel || code === panel.base) {
            return;
        }

        setBusy('base');

        try {
            const result = await api.post<{ message: string; boot: BootPayload }>('/currency/base', {
                base: code,
            });

            apply(result.boot);
            await refetch();
            toast.success(result.message);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'That could not be changed.');
        } finally {
            setBusy(null);
        }
    };

    // ── How rates are kept ───────────────────────────────────────────────

    const saveMode = async (values: Record<string, string>) => {
        setBusy('mode');

        try {
            await api.patch('/settings/currency', values);
            await refetch();
            toast.success('Saved.');
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'That could not be saved.');
        } finally {
            setBusy(null);
        }
    };

    const refresh = async () => {
        setBusy('refresh');

        try {
            const result = await api.post<{ message: string }>('/currency/refresh');
            await refetch();
            toast.success(result.message);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Rates could not be fetched.');
        } finally {
            setBusy(null);
        }
    };

    // ── Individual rates ─────────────────────────────────────────────────

    const addForm = useApiForm({ code: '', rate: '' });

    const addRate = (event: React.FormEvent) => {
        event.preventDefault();

        void addForm.post<{ message: string }>('/currency/rates', {
            onSuccess: async (result) => {
                addForm.reset();
                await refetch();
                await queryClient.invalidateQueries({ queryKey: ['settings', 'currency'] });
                toast.success(result.message);
            },
        });
    };

    const saveRate = async (code: string, rate: string) => {
        setBusy(code);

        try {
            const result = await api.post<{ message: string }>('/currency/rates', { code, rate });
            await refetch();
            toast.success(result.message);
        } catch (error) {
            toast.error(
                error instanceof ApiError ? (error.fieldError('rate') ?? error.message) : 'Not saved.',
            );
        } finally {
            setBusy(null);
        }
    };

    const removeRate = async (code: string) => {
        setBusy(code);

        try {
            await api.delete('/currency/rates', { code });
            await refetch();
            toast.success('Rate removed.');
        } catch {
            toast.error('That could not be removed.');
        } finally {
            setBusy(null);
        }
    };

    if (isPending || !panel) {
        return (
            <div className="space-y-5">
                {[0, 1].map((i) => (
                    <div key={i} className="card p-5">
                        <div className="h-4 w-40 animate-pulse rounded bg-[var(--color-brand-subtle)]" />
                        <div className="mt-4 h-10 animate-pulse rounded bg-[var(--color-brand-subtle)]" />
                    </div>
                ))}
            </div>
        );
    }

    const addable = panel.options.filter(
        (option) =>
            option.code !== panel.base && !panel.rates.some((rate) => rate.code === option.code),
    );

    return (
        <div className="space-y-5">
            <SettingsCard
                title="Tool currency"
                blurb="What every total is counted and shown in. Each storefront keeps charging in its own currency; anything arriving in another one is converted into this."
            >
                <div className="flex flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1" style={{ flexBasis: '16rem' }}>
                        <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                            Count and show everything in
                        </span>
                        <select
                            className="field"
                            value={panel.base}
                            disabled={busy === 'base'}
                            onChange={(event) => void changeBase(event.target.value)}
                        >
                            {panel.options.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.name} ({option.symbol}) — {option.code}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="mt-4 flex items-start gap-2 rounded-lg bg-[var(--color-brand-subtle)] px-3 py-2.5 text-xs text-[var(--color-text-body)]">
                    <Icon name="shield-check" size={14} className="mt-0.5 flex-none" />
                    <span>
                        Change this whenever you like. Every order, payment and transaction keeps the amount
                        and the currency it actually happened in; the totals are worked out from those again
                        each time. A ৳300 expense stays ৳300 — in dollar mode it reads as its dollar value,
                        and back in taka mode it reads ৳300 again.
                        {panel.rebuilt_at && (
                            <span className="mt-1 block">
                                Last recalculated {new Date(panel.rebuilt_at).toLocaleString()}.
                            </span>
                        )}
                    </span>
                </div>
            </SettingsCard>

            <SettingsCard
                title="Where rates come from"
                blurb="On automatic the service owns every rate and they cannot be edited by hand."
            >
                <div className="flex flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1" style={{ flexBasis: '14rem' }}>
                        <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                            Rates
                        </span>
                        <select
                            className="field"
                            value={panel.mode}
                            disabled={busy === 'mode'}
                            onChange={(event) =>
                                void saveMode({
                                    'mode': event.target.value,
                                    'provider': panel.provider,
                                    'provider_key': '',
                                })
                            }
                        >
                            <option value="manual">I enter them myself</option>
                            <option value="auto">A rate service, automatically</option>
                        </select>
                    </label>

                    {serviceOwns && (
                        <>
                            <label className="min-w-0 flex-1" style={{ flexBasis: '18rem' }}>
                                <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                                    Service
                                </span>
                                <select
                                    className="field"
                                    value={panel.provider}
                                    disabled={busy === 'mode'}
                                    onChange={(event) =>
                                        void saveMode({
                                            'mode': 'auto',
                                            'provider': event.target.value,
                                            'provider_key': '',
                                        })
                                    }
                                >
                                    {panel.providers.map((provider) => (
                                        <option key={provider.key} value={provider.key}>
                                            {provider.label}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <Button
                                type="button"
                                busy={busy === 'refresh'}
                                onClick={() => void refresh()}
                                className="flex-none"
                            >
                                <Icon name="arrows-clockwise" size={15} />
                                Fetch now
                            </Button>
                        </>
                    )}
                </div>

                {serviceOwns && (
                    <p className="mt-3 text-xs text-[var(--color-text-muted)]">
                        {panel.last_refreshed
                            ? `Last fetched ${new Date(panel.last_refreshed).toLocaleString()}.`
                            : 'Never fetched.'}{' '}
                        Every currency the tool knows is fetched against {panel.base}.
                    </p>
                )}
            </SettingsCard>

            <SettingsCard
                title={`Conversion to ${panel.base}`}
                blurb={`What one unit of each currency is worth in ${panel.base}.`}
            >
                {panel.missing.length > 0 && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs"
                         style={{ background: 'var(--color-warning-subtle)', color: 'var(--color-warning)' }}>
                        <Icon name="warning" size={14} weight="fill" className="mt-0.5 flex-none" />
                        <span>
                            No rate yet for <strong>{panel.missing.join(', ')}</strong> — a business trades in
                            {panel.missing.length === 1 ? ' it' : ' them'}, so anything in
                            {panel.missing.length === 1 ? ' that currency' : ' those currencies'} is left out
                            of totals until a rate exists.
                        </span>
                    </div>
                )}

                {!serviceOwns && addable.length > 0 && (
                    <form
                        onSubmit={addRate}
                        className="mb-4 flex flex-wrap items-end gap-2 rounded-lg bg-[var(--color-brand-subtle)] p-3"
                    >
                        <label className="min-w-0" style={{ flex: '1 1 12rem' }}>
                            <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                                Add a currency
                            </span>
                            <select
                                className="field"
                                value={addForm.data.code}
                                onChange={(event) => addForm.set('code', event.target.value)}
                                required
                            >
                                <option value="">Choose…</option>
                                {addable.map((option) => (
                                    <option key={option.code} value={option.code}>
                                        {option.name} — {option.code}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <Field
                            label={`1 of it = how many ${panel.base}?`}
                            error={addForm.errors.rate}
                            type="number"
                            step="0.00000001"
                            min="0"
                            value={addForm.data.rate}
                            onChange={(event) => addForm.set('rate', event.target.value)}
                            placeholder="122.50"
                            required
                            className="min-w-0"
                        />

                        <Button type="submit" variant="secondary" busy={addForm.processing} className="flex-none">
                            <Icon name="plus" size={14} weight="bold" />
                            Add
                        </Button>
                    </form>
                )}

                {visible.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-[var(--color-border-light)] px-4 py-10 text-center">
                        <Icon name="currency-circle-dollar" size={26} className="mx-auto text-[var(--color-text-subtle)]" />
                        <p className="mt-2 text-sm text-[var(--color-text-muted)]">No conversions yet.</p>
                        <p className="mx-auto mt-1 max-w-sm text-xs text-[var(--color-text-subtle)]">
                            {serviceOwns
                                ? 'Press “Fetch now” above and every currency the tool knows arrives with today’s rate.'
                                : `Everything is in ${panel.base} so far. Add a currency above when a business starts trading in another one.`}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {visible.map((rate) => (
                            <RateRow
                                key={rate.code}
                                rate={rate}
                                base={panel.base}
                                readOnly={serviceOwns}
                                busy={busy === rate.code}
                                onSave={saveRate}
                                onRemove={removeRate}
                            />
                        ))}
                    </div>
                )}

                {!showAll && (panel.rates.length > visible.length) && (
                    <button
                        type="button"
                        onClick={() => setShowAll(true)}
                        className="mt-3 text-sm text-[var(--color-link)]"
                    >
                        Show all {panel.rates.length} currencies
                    </button>
                )}
            </SettingsCard>
        </div>
    );
}

function RateRow({
    rate,
    base,
    readOnly,
    busy,
    onSave,
    onRemove,
}: {
    rate: CurrencyRate;
    base: string;
    readOnly: boolean;
    busy: boolean;
    onSave: (code: string, value: string) => Promise<void>;
    onRemove: (code: string) => Promise<void>;
}) {
    const [value, setValue] = useState(rate.rate === null ? '' : String(rate.rate));
    const dirty = value !== (rate.rate === null ? '' : String(rate.rate));

    return (
        <div
            className={cn(
                'rounded-xl border border-[var(--color-border-light)] px-3 py-2.5',
                readOnly && 'opacity-75',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <div className="min-w-0 flex-1">
                    <p className="text-sm text-[var(--color-text-main)]">
                        1 {rate.code} ={' '}
                        <strong>{rate.rate === null ? '?' : rate.rate.toLocaleString(undefined, { maximumFractionDigits: 6 })}</strong>{' '}
                        {base}
                    </p>
                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                        {rate.name}
                        {rate.source && ` · ${rate.source === 'manual' ? 'set by you' : 'from the service'}`}
                        {rate.in_use && ' · a business uses this'}
                        {rate.rate === null && (
                            <span className="text-[var(--color-danger-text)]"> · no rate yet</span>
                        )}
                    </p>
                </div>

                {readOnly ? (
                    <Icon
                        name="cloud-arrow-down"
                        size={16}
                        className="flex-none text-[var(--color-text-subtle)]"
                    />
                ) : (
                    <div className="flex flex-none items-center gap-1.5">
                        <input
                            type="number"
                            step="0.00000001"
                            min="0"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            className="field w-36 px-2 py-1 text-sm"
                            aria-label={`Rate for ${rate.code}`}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            busy={busy}
                            // Nothing to save until something changed — a live
                            // button that does nothing teaches people to
                            // distrust the ones that do.
                            disabled={!dirty || value === ''}
                            onClick={() => void onSave(rate.code, value)}
                            aria-label={`Save ${rate.code}`}
                        >
                            <Icon name="check" size={14} weight="bold" />
                        </Button>
                        {rate.rate !== null && (
                            <button
                                type="button"
                                onClick={() => void onRemove(rate.code)}
                                className="rounded p-1.5 text-[var(--color-text-subtle)] hover:text-[var(--color-danger-text)]"
                                aria-label={`Remove ${rate.code}`}
                            >
                                <Icon name="x" size={14} />
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
