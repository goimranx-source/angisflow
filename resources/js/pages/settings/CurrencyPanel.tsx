import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { InfoHint } from '@/components/ui/InfoHint';
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
    const { apply, refresh: refreshSession } = useSession();
    const queryClient = useQueryClient();
    const [showAll, setShowAll] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    /**
     * Make the rest of the product agree with what just changed here.
     *
     * The boot payload carries the money scope — the reporting currency and a
     * stamp of the rates behind it — and every money-bearing query is keyed on
     * it, with the same token in the URL so the browser's own cache is scoped
     * too (see hooks/useMoney and lib/api). Refreshing the session is
     * therefore what actually re-points the whole shell: new scope, new keys,
     * new URLs, and nothing anywhere can answer out of a cache filled while a
     * different currency was in force.
     *
     * The invalidate is belt and braces for anything keyed without the scope.
     */
    const republishMoney = async () => {
        await refreshSession();
        await queryClient.invalidateQueries();
    };

    const { data, isPending, refetch } = useQuery({
        queryKey: ['settings', 'currency'],
        queryFn: ({ signal }) => api.get<{ data: SettingsPanel }>('/settings/currency', { signal }),
    });

    const panel = data?.data.currency;
    // A sibling of `currency` in the payload, not a child of it — this is where
    // the raw stored values live, including the API key.
    const storedKey = (data?.data.values?.provider_key as string | undefined) ?? '';

    const serviceOwns = panel?.mode === 'auto';
    const providerNeedsKey =
        panel?.providers.find((candidate) => candidate.key === panel.provider)?.needs_key ?? false;

    // Local, so the field can be typed into before it is saved. Re-seeded
    // whenever the server's copy changes — switching provider, or a refetch —
    // so it never shows the key belonging to a provider no longer selected.
    const [apiKey, setApiKey] = useState(storedKey);

    useEffect(() => {
        setApiKey(storedKey);
    }, [storedKey]);
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

            // The boot that came back with the save already carries the new
            // scope, so this re-points the shell without a second round trip.
            apply(result.boot);
            await queryClient.invalidateQueries();
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
            // New rates mean every converted figure in the product moved,
            // even though the currency did not. The scope's stamp is what
            // says so — and only a fresh boot carries it.
            await republishMoney();
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
                await republishMoney();
                await refetch();
                toast.success(result.message);
            },
        });
    };

    const saveRate = async (code: string, rate: string) => {
        setBusy(code);

        try {
            const result = await api.post<{ message: string }>('/currency/rates', { code, rate });
            await republishMoney();
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
            await republishMoney();
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
                title="Business currency"
                hint="The currency this business keeps its books in. Every total on its screens is shown in it, and every sale — whichever store or currency it came in through — is converted into it once, at the rate on the day, and kept that way. Changing it after trading has started is an accounting decision, not a display preference."
            >
                {/* The card's title is the label. A second heading above the
                    only control in it said the same thing twice. */}
                <select
                    className="w-full min-h-[2.25rem] px-3 py-1.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] text-[0.875rem] text-[var(--color-text-main)] focus:outline-none focus:border-[var(--color-brand)] transition-colors appearance-none pr-10"
                    style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%232e3d52' d='M1 1l5 5 5-5'/%3E%3C/svg%3E")`,
                        backgroundRepeat: 'no-repeat',
                        backgroundPosition: 'right 0.75rem center',
                        backgroundSize: '12px 8px'
                    }}
                    aria-label="Business currency"
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
            </SettingsCard>

            <SettingsCard
                title="How rates are kept up to date"
                hint="On automatic the service owns every rate. Switch to manual to set one yourself."
            >
                <div className="flex flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1" style={{ flexBasis: '14rem' }}>
                        <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                            Rates
                        </span>
                        <select
                            className="w-full min-h-[2.25rem] px-3 py-1.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] text-[0.875rem] text-[var(--color-text-main)] focus:outline-none focus:border-[var(--color-brand)] transition-colors appearance-none pr-10"
                            style={{
                                backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%232e3d52' d='M1 1l5 5 5-5'/%3E%3C/svg%3E")`,
                                backgroundRepeat: 'no-repeat',
                                backgroundPosition: 'right 0.75rem center',
                                backgroundSize: '12px 8px'
                            }}
                            value={panel.mode}
                            disabled={busy === 'mode'}
                            onChange={(event) =>
                                void saveMode({
                                    'mode': event.target.value,
                                    'provider': panel.provider,
                                    // The stored key, not a blank. Sending ''
                                    // here wiped the subscriber's API key every
                                    // time they touched an unrelated dropdown.
                                    'provider_key': apiKey,
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
                                    className="w-full min-h-[2.25rem] px-3 py-1.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] text-[0.875rem] text-[var(--color-text-main)] focus:outline-none focus:border-[var(--color-brand)] transition-colors appearance-none pr-10"
                                    style={{
                                        backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%232e3d52' d='M1 1l5 5 5-5'/%3E%3C/svg%3E")`,
                                        backgroundRepeat: 'no-repeat',
                                        backgroundPosition: 'right 0.75rem center',
                                        backgroundSize: '12px 8px'
                                    }}
                                    value={panel.provider}
                                    disabled={busy === 'mode'}
                                    onChange={(event) =>
                                        void saveMode({
                                            'mode': 'auto',
                                            'provider': event.target.value,
                                            'provider_key': apiKey,
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

                            {/*
                                Only for services that need one — the free ones
                                do not, and an empty box labelled "API key" on a
                                provider that never asks for one reads as a
                                required step somebody is missing.
                            */}
                            {providerNeedsKey && (
                                <label className="min-w-0 flex-1" style={{ flexBasis: '18rem' }}>
                                    <span className="mb-1.5 flex items-center gap-1.5 text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                                        API key
                                        <InfoHint label="About the API key">
                                            Issued by the rate service when you sign up with them.
                                            Stored against this workspace and sent only to that
                                            service.
                                        </InfoHint>
                                    </span>
                                    <div className="flex gap-2">
                                        <input
                                            type="password"
                                            className="field font-mono text-xs"
                                            value={apiKey}
                                            placeholder="not needed for the free services"
                                            autoComplete="off"
                                            spellCheck={false}
                                            disabled={busy === 'mode'}
                                            onChange={(event) => setApiKey(event.target.value)}
                                        />
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            busy={busy === 'mode'}
                                            disabled={apiKey === storedKey}
                                            onClick={() =>
                                                void saveMode({
                                                    'mode': 'auto',
                                                    'provider': panel.provider,
                                                    'provider_key': apiKey,
                                                })
                                            }
                                            className="flex-none"
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </label>
                            )}

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
                hint={`What one unit of each currency is worth in ${panel.base}. Anything without a rate is left out of totals rather than guessed at.`}
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
                        className="mb-4 flex flex-wrap items-end gap-2 rounded-[var(--shell-radius)] bg-[var(--color-brand-subtle)] p-3"
                    >
                        <label className="min-w-0" style={{ flex: '1 1 12rem' }}>
                            <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                                Add a currency
                            </span>
                            <select
                                className="w-full min-h-[2.25rem] px-3 py-1.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] text-[0.875rem] text-[var(--color-text-main)] focus:outline-none focus:border-[var(--color-brand)] transition-colors appearance-none pr-10"
                                style={{
                                    backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%232e3d52' d='M1 1l5 5 5-5'/%3E%3C/svg%3E")`,
                                    backgroundRepeat: 'no-repeat',
                                    backgroundPosition: 'right 0.75rem center',
                                    backgroundSize: '12px 8px'
                                }}
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
                    <div className="rounded-[var(--shell-radius)] border border-dashed border-[var(--shell-border)] px-4 py-10 text-center">
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

            {/*
                The rate table made concrete.

                A column of codes against numbers is abstract; this is the
                question actually being asked — my Berlin shop charges euros, so
                what does that become in what I report in, and is there a rate
                for it at all. A hundred units rather than one, because rates
                below 0.01 round to nothing at a single unit and the row reads
                as broken.
            */}
            {/*
                Kept as a workspace-level overview rather than removed: it is
                the one place that answers "do I have a rate for every currency
                my other books are kept in", which is what stops a cross-
                business total quietly leaving one of them out. It is not a
                claim that those businesses report here — each keeps its own.
            */}
            <SettingsCard
                title="Other books in this workspace"
                hint={`Each business keeps its own books in its own currency. This is what one of theirs is worth in this one — the rate a workspace-level comparison would use. A row without a rate is a business that would be left out of that comparison rather than guessed at.`}
            >
                <div className="overflow-x-auto rounded-[var(--shell-radius)]">
                    <table className="table table-framed">
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Trades in</th>
                                <th className="text-right">{panel.sample} becomes</th>
                                <th>Rate in force</th>
                            </tr>
                        </thead>
                        <tbody>
                            {panel.businesses.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="text-center text-[var(--color-text-muted)]">
                                        No businesses yet.
                                    </td>
                                </tr>
                            ) : (
                                panel.businesses.map((business) => (
                                    <tr key={business.id}>
                                        <td>
                                            {business.name}
                                            {business.short_code && (
                                                <span className="ml-1.5 font-mono text-xs text-[var(--color-text-subtle)]">
                                                    {business.short_code}
                                                </span>
                                            )}
                                        </td>
                                        <td>{business.code}</td>
                                        <td className="text-right">
                                            {business.is_base ? (
                                                <span className="text-[var(--color-text-muted)]">
                                                    {panel.sample} {panel.base}
                                                </span>
                                            ) : business.converted !== null ? (
                                                <>
                                                    {panel.sample} {business.code} →{' '}
                                                    <strong>
                                                        {business.converted} {panel.base}
                                                    </strong>
                                                </>
                                            ) : (
                                                <span className="text-[var(--color-danger-text)]">
                                                    cannot convert
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-xs text-[var(--color-text-muted)]">
                                            {business.is_base
                                                ? 'this workspace’s own currency'
                                                : business.rate !== null
                                                  ? `1 ${business.code} = ${business.rate} ${panel.base}`
                                                  : `add a rate for ${business.code} above`}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
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
                'rounded-[var(--shell-radius)] border border-[var(--shell-border)] px-3 py-2.5',
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
                            className="field w-36 text-sm"
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
