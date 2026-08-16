import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Form/Input';
import { FileUpload } from '@/components/ui/Form/FileUpload';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

type Country = {
    code: string;
    name: string;
    currency: string;
    timezones: string[];
};

type Category = {
    id: number;
    key: string;
    name: string;
    icon: string | null;
    summary: string | null;
    modules: number | null;
    children: Category[];
};

type CountryPayload = { data: Country[]; currencies: string[] };

/**
 * Opening a set of books, one question at a time.
 *
 * ── Why the country is asked first ───────────────────────────────────────────
 *
 * It is the only answer here that decides other answers. Pick Bangladesh and
 * the books should keep taka and Dhaka time; the old form asked for a country
 * last, out of a list of twelve, alongside a currency dropdown of nine — and
 * then threw the country away without saving it. Two hundred and forty-three
 * countries now, each carrying its own money and clock, and answering the first
 * question fills in the second.
 *
 * ── Why the derived answers are still shown ──────────────────────────────────
 *
 * A currency guessed from a country is right almost always and wrong in exactly
 * the cases that matter most — an exporter banking in dollars, a business in a
 * dollarised economy. Deriving it silently would be a figure nobody chose
 * appearing at the bottom of every invoice. So step two shows the derivation
 * and lets it be overruled.
 */
export function CreateBusinessWizard({
    workspaceId,
    workspaceName,
    onClose,
    onCreated,
}: {
    workspaceId: string;
    workspaceName: string;
    onClose: () => void;
    onCreated: () => void;
}) {
    const { tenant, refresh } = useSession();
    const [step, setStep] = useState(0);
    const [categoryKey, setCategoryKey] = useState<string | null>(null);
    const [childKey, setChildKey] = useState<string | null>(null);
    const [country, setCountry] = useState<Country | null>(null);
    const [currency, setCurrency] = useState<string | null>(null);
    const [timezone, setTimezone] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [name, setName] = useState('');
    const [shortCode, setShortCode] = useState('');
    const [codeTouched, setCodeTouched] = useState(false);
    const [logoFiles, setLogoFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const { data: categoriesData, isPending: categoriesLoading } = useQuery({
        queryKey: ['business-categories'],
        queryFn: ({ signal }) =>
            api.get<{ data: Category[] }>('/business-categories', { signal }),
        staleTime: 30 * 60 * 1000,
    });

    const { data, isPending } = useQuery({
        queryKey: ['countries'],
        queryFn: ({ signal }) => api.get<CountryPayload>('/workspaces/countries', { signal }),
        // Reference data. It changes when a country changes its money.
        staleTime: 24 * 60 * 60 * 1000,
    });

    const categories = useMemo(() => categoriesData?.data ?? [], [categoriesData]);
    const category = categories.find((entry) => entry.key === categoryKey) ?? null;
    const child = category?.children.find((entry) => entry.key === childKey) ?? null;
    
    // A category with nothing under it has no second question to ask
    const hasChildren = (category?.children.length ?? 0) > 0;
    
    // What actually gets posted - the narrowest thing chosen
    const chosen = child ?? category;

    const countries = useMemo(() => data?.data ?? [], [data]);
    const currencies = useMemo(() => data?.currencies ?? [], [data]);

    const matches = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle.length === 0) {
            return countries;
        }

        // Code as well as name, so somebody who knows they want GB can type it
        // rather than scrolling to United Kingdom.
        return countries.filter(
            (entry) =>
                entry.name.toLowerCase().includes(needle) ||
                entry.code.toLowerCase() === needle ||
                entry.currency.toLowerCase() === needle,
        );
    }, [countries, search]);

    const pickCategory = (next: Category) => {
        setCategoryKey(next.key);
        setChildKey(null);
        const categoryHasChildren = (next.children?.length ?? 0) > 0;
        // Move to subcategory step if this category has children, otherwise skip to country
        setStep(categoryHasChildren ? 1 : 2);
    };

    const skipCategory = () => {
        setCategoryKey(null);
        setChildKey(null);
        setStep(2); // Skip to country step
    };

    const pickCountry = (entry: Country) => {
        setCountry(entry);
        setCurrency(entry.currency);
        setTimezone(entry.timezones[0] ?? null);
        // Always move to step 3 (money) after country
        setStep(3);
    };

    const skipCountry = () => {
        setCountry(null);
        setCurrency(tenant?.account.currency ?? 'USD');
        setTimezone(null);
        // Always move to step 3 (money) after skipping country
        setStep(3);
    };

    const onName = (value: string) => {
        setName(value);

        // Follows the name until somebody types their own, then stops — a code
        // that keeps rewriting itself under the cursor is worse than none.
        if (!codeTouched) {
            setShortCode(shortCodeFor(value));
        }
    };

    const create = async () => {
        const trimmed = name.trim();

        if (trimmed.length === 0 || busy) {
            return;
        }

        setBusy(true);
        setErrors({});

        const fields: Record<string, string> = {
            name: trimmed,
            short_code: shortCode.trim().toUpperCase(),
            base_currency: currency ?? tenant?.account.currency ?? 'USD',
            business_category_id: chosen?.id?.toString() ?? '',
        };

        if (country) {
            fields.country = country.code;
        }

        if (timezone) {
            fields.timezone = timezone;
        }

        try {
            let body: FormData | Record<string, string>;

            if (logoFiles.length > 0) {
                const form = new FormData();
                Object.entries(fields).forEach(([key, value]) => form.append(key, value));
                form.append('logo', logoFiles[0]!);
                body = form;
            } else {
                body = fields;
            }

            const result = await api.post<{ message: string }>(
                `/workspaces/${workspaceId}/businesses`,
                body,
            );

            toast.success(result.message);
            onClose();
            onCreated();
            void refresh();
        } catch (problem) {
            const error = problem as { message?: string; errors?: Record<string, string[]> };

            if (error.errors) {
                const flat: Record<string, string> = {};
                Object.entries(error.errors).forEach(([key, list]) => {
                    flat[key] = list[0] ?? 'That is not valid.';
                });
                setErrors(flat);
            } else {
                setErrors({ form: error.message ?? 'That could not be created.' });
            }
        } finally {
            setBusy(false);
        }
    };

    const heading = [
        'What kind of business is this?',
        `Which kind of ${category?.name.toLowerCase()}?`,
        'Where does it trade?',
        'Money and time',
        'Money and time',
        'Name this business',
    ][step] ?? 'Create business';
    
    const caption = [
        'This decides which tools are switched on. You can change any of it later.',
        'Optional, and only used to fine-tune the starting set.',
        'This sets the currency its figures are kept in and the clock its days are counted by.',
        country
            ? `Taken from ${country.name}. Change either if that is not how these books are kept.`
            : 'No country chosen, so these start from your account settings.',
        country
            ? `Taken from ${country.name}. Change either if that is not how these books are kept.`
            : 'No country chosen, so these start from your account settings.',
        `A business is one set of books inside ${workspaceName} — its own orders, stock and figures.`,
    ][step] ?? '';
    
    // Calculate which step index for the last details step
    // WITH children: 0(cat) → 1(subcat) → 2(country) → 3(money) → 4(details) = step 4
    // WITHOUT children: 0(cat) → 2(country) → 3(money) → 4(details) = step 4
    const detailsStepIndex = 4;
    // Total steps for progress bar (always show 5 steps)
    const totalSteps = 5;

    return (
        <Modal
            open={true}
            onClose={onClose}
            title={heading}
            description={caption}
            size="xl"
            closeOnBackdrop={false}
        >
            {/* Progress bar */}
            <div className="mb-6 flex gap-2">
                {Array.from({ length: totalSteps }, (_, index) => (
                    <button
                        key={index}
                        type="button"
                        onClick={() => {
                            // Only allow clicking on completed steps or current step
                            if (index <= step) {
                                setStep(index);
                            }
                        }}
                        disabled={index > step}
                        className={cn(
                            'h-1 flex-1 rounded-full transition-colors duration-200',
                            index <= step
                                ? 'bg-[var(--color-brand)] cursor-pointer hover:opacity-80'
                                : 'bg-[var(--color-border-light)] cursor-not-allowed',
                        )}
                        aria-label={`Step ${index + 1}${index <= step ? ' (click to go back)' : ''}`}
                        aria-current={index === step ? 'step' : undefined}
                    />
                ))}
            </div>

            {/* Step content */}
            <div className="min-h-[400px]">
                {step === 0 && (
                    <CategoryStep
                        categories={categories}
                        loading={categoriesLoading}
                        selected={categoryKey}
                        onPick={pickCategory}
                    />
                )}

                {step === 1 && hasChildren && category && (
                    <div className="choice-grid is-list">
                        {category.children.map((entry) => (
                            <button
                                key={entry.key}
                                type="button"
                                onClick={() => {
                                    setChildKey(entry.key);
                                    setStep(2);
                                }}
                                className={cn('choice is-row', childKey === entry.key && 'is-selected')}
                            >
                                <span className="choice-radio" />
                                <span className="choice-name">{entry.name}</span>
                            </button>
                        ))}
                    </div>
                )}

                {step === 2 && (
                    <CountryStep
                        countries={matches}
                        loading={isPending}
                        search={search}
                        selected={country?.code ?? null}
                        onSearch={setSearch}
                        onPick={pickCountry}
                    />
                )}

                {step === 3 && (
                    <MoneyStep
                        country={country}
                        currency={currency}
                        timezone={timezone}
                        currencies={currencies}
                        onCurrency={setCurrency}
                        onTimezone={setTimezone}
                    />
                )}

                {step === detailsStepIndex && (
                    <DetailsStep
                        name={name}
                        shortCode={shortCode}
                        logoFiles={logoFiles}
                        country={country}
                        currency={currency}
                        timezone={timezone}
                        chosen={chosen}
                        errors={errors}
                        onName={onName}
                        onShortCode={(value) => {
                            setCodeTouched(true);
                            setShortCode(value.toUpperCase());
                        }}
                        onLogoFilesSelected={setLogoFiles}
                        onSubmit={() => void create()}
                    />
                )}
            </div>

            {/* Footer actions */}
            <div className="mt-6 flex items-center justify-between border-t border-[var(--color-border-light)] pt-4">
                {/* Left side - Skip or Back */}
                {step === 0 ? (
                    <button
                        type="button"
                        onClick={skipCategory}
                        className="text-sm font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition-colors"
                    >
                        Skip, I'll set this up myself
                    </button>
                ) : step === 2 ? (
                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setStep((current) => Math.max(0, current - 1))}
                        >
                            <Icon name="caret-left" size={14} />
                            Back
                        </Button>
                        <button
                            type="button"
                            onClick={skipCountry}
                            className="text-sm font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition-colors"
                        >
                            Skip, use my account settings
                        </button>
                    </div>
                ) : (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setStep((current) => Math.max(0, current - 1))}
                    >
                        <Icon name="caret-left" size={14} />
                        Back
                    </Button>
                )}

                {/* Right side - Continue or Submit */}
                {step === 1 && hasChildren ? (
                    <Button type="button" size="sm" variant="secondary" onClick={() => setStep(2)}>
                        Skip this
                    </Button>
                ) : step === 3 ? (
                    <Button type="button" size="sm" onClick={() => setStep(4)}>
                        Continue
                    </Button>
                ) : step === 4 ? (
                    <Button
                        type="button"
                        size="sm"
                        busy={busy}
                        disabled={name.trim().length === 0}
                        onClick={() => void create()}
                    >
                        Create business
                    </Button>
                ) : (
                    <span />
                )}
            </div>
        </Modal>
    );
}

function CategoryStep({
    categories,
    loading,
    selected,
    onPick,
}: {
    categories: Category[];
    loading: boolean;
    selected: string | null;
    onPick: (category: Category) => void;
}) {
    if (loading) {
        return (
            <div className="choice-grid">
                {Array.from({ length: 9 }, (_, index) => (
                    <div key={index} className="choice animate-pulse">
                        <span className="choice-icon" />
                        <span className="h-3 w-2/3 rounded bg-[var(--shell-tint)]" />
                        <span className="h-2.5 w-full rounded bg-[var(--shell-tint)]" />
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="choice-grid">
            {categories.map((category) => (
                <button
                    key={category.key}
                    type="button"
                    onClick={() => onPick(category)}
                    className={cn('choice', selected === category.key && 'is-selected')}
                >
                    <span className="choice-icon">
                        <Icon name={category.icon ?? 'buildings'} size={18} weight="duotone" />
                    </span>
                    <span className="choice-name">{category.name}</span>
                    {category.summary && <span className="choice-note">{category.summary}</span>}
                </button>
            ))}
        </div>
    );
}

function CountryStep({
    countries,
    loading,
    search,
    selected,
    onSearch,
    onPick,
}: {
    countries: Country[];
    loading: boolean;
    search: string;
    selected: string | null;
    onSearch: (value: string) => void;
    onPick: (country: Country) => void;
}) {
    return (
        <div className="space-y-3">
            <input
                autoFocus
                value={search}
                onChange={(event) => onSearch(event.target.value)}
                placeholder="Search 243 countries, or type a code like BD"
                style={{ borderRadius: 'var(--shell-radius)' }}
                className="w-full border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-2 text-sm text-[var(--color-text-main)] placeholder:text-[var(--color-text-subtle)] transition-all duration-150 focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]"
                aria-label="Search countries"
            />

            {loading ? (
                <div className="choice-grid is-list">
                    {Array.from({ length: 8 }, (_, index) => (
                        <div key={index} className="choice is-row animate-pulse">
                            <span className="choice-radio" />
                            <span className="h-3 w-1/2 rounded bg-[var(--shell-tint)]" />
                        </div>
                    ))}
                </div>
            ) : countries.length === 0 ? (
                <p className="py-6 text-center text-[0.8125rem] text-[var(--color-text-muted)]">
                    No country matches that.
                </p>
            ) : (
                <div className="choice-grid is-list">
                    {countries.map((entry) => (
                        <button
                            key={entry.code}
                            type="button"
                            onClick={() => onPick(entry)}
                            className={cn('choice is-row', selected === entry.code && 'is-selected')}
                        >
                            <span className="choice-radio" />
                            <span className="choice-name flex-1 truncate">{entry.name}</span>
                            <span className="choice-tail">{entry.currency}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function MoneyStep({
    country,
    currency,
    timezone,
    currencies,
    onCurrency,
    onTimezone,
}: {
    country: Country | null;
    currency: string | null;
    timezone: string | null;
    currencies: string[];
    onCurrency: (value: string) => void;
    onTimezone: (value: string) => void;
}) {
    // Every zone when no country is chosen, otherwise that country's own — a
    // list of four hundred is not a choice, it is a search problem.
    const zones = country?.timezones ?? [];

    return (
        <div className="space-y-4">
            <div className="space-y-1.5">
                <label
                    htmlFor="business-currency"
                    className="block text-sm font-medium text-[var(--color-text-main)]"
                >
                    Base currency <span className="text-red-600">*</span>
                </label>
                <div className="relative">
                    <select
                        id="business-currency"
                        value={currency ?? ''}
                        onChange={(event) => onCurrency(event.target.value)}
                        style={{ borderRadius: 'var(--shell-radius)' }}
                        className="w-full appearance-none border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-2 pr-9 text-sm text-[var(--color-text-main)] transition-all duration-150 focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]"
                        required
                    >
                        {currencies.map((code) => (
                            <option key={code} value={code}>
                                {code}
                            </option>
                        ))}
                    </select>
                    {/* Custom dropdown icon */}
                    <div className="pointer-events-none absolute right-0 top-0 flex h-full items-center pr-2.5 text-[var(--color-text-muted)]">
                        <Icon name="caret-down" size={16} weight="bold" />
                    </div>
                </div>
                <p className="text-xs text-[var(--color-text-muted)]">
                    Every total in these books is counted in this. Sales in other currencies are converted into it.
                </p>
            </div>

            <div className="space-y-1.5">
                <label
                    htmlFor="business-timezone"
                    className="block text-sm font-medium text-[var(--color-text-main)]"
                >
                    Timezone
                </label>
                {zones.length > 0 ? (
                    <div className="relative">
                        <select
                            id="business-timezone"
                            value={timezone ?? ''}
                            onChange={(event) => onTimezone(event.target.value)}
                            style={{ borderRadius: 'var(--shell-radius)' }}
                            className="w-full appearance-none border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-2 pr-9 text-sm text-[var(--color-text-main)] transition-all duration-150 focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]"
                        >
                            {zones.map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </select>
                        {/* Custom dropdown icon */}
                        <div className="pointer-events-none absolute right-0 top-0 flex h-full items-center pr-2.5 text-[var(--color-text-muted)]">
                            <Icon name="caret-down" size={16} weight="bold" />
                        </div>
                    </div>
                ) : (
                    <div style={{ borderRadius: 'var(--shell-radius)' }} className="border border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-3 py-2 text-sm text-[var(--color-text-muted)]">
                        Your account's timezone
                    </div>
                )}
                <p className="text-xs text-[var(--color-text-muted)]">
                    Decides where one trading day ends and the next begins.
                </p>
            </div>

            {country && (
                <div className="wizard-summary">
                    <Icon
                        name="compass"
                        size={18}
                        weight="duotone"
                        className="mt-0.5 shrink-0 text-[var(--color-brand-text)]"
                    />
                    <p className="text-xs leading-relaxed text-[var(--color-text-muted)]">
                        Suggested from <strong className="font-semibold">{country.name}</strong>.
                        Both can be changed later in settings, though changing a base currency once
                        figures are posted is a bigger job than changing it now.
                    </p>
                </div>
            )}
        </div>
    );
}

function DetailsStep({
    name,
    shortCode,
    country,
    currency,
    timezone,
    chosen,
    errors,
    onName,
    onShortCode,
    onLogoFilesSelected,
    onSubmit,
}: {
    name: string;
    shortCode: string;
    logoFiles: File[];
    country: Country | null;
    currency: string | null;
    timezone: string | null;
    chosen: Category | null;
    errors: Record<string, string>;
    onName: (value: string) => void;
    onShortCode: (value: string) => void;
    onLogoFilesSelected: (files: File[]) => void;
    onSubmit: () => void;
}) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            className="space-y-4"
        >
            <Input
                label="Business name"
                id="business-name"
                autoFocus
                value={name}
                onChange={(event) => onName(event.target.value)}
                placeholder="Acme Retail"
                maxLength={120}
                error={errors.name}
                required
            />

            <Input
                label="Short code"
                id="business-code"
                value={shortCode}
                onChange={(event) => onShortCode(event.target.value)}
                placeholder="ACM"
                maxLength={6}
                helperText="Prefixes its SKUs and reference numbers. Suggested from the name."
                error={errors.short_code}
            />

            <FileUpload
                label="Business logo"
                helperText="Upload your business logo (optional). Supported formats: PNG, JPG, SVG. Max 2MB."
                onFilesSelected={onLogoFilesSelected}
                accept="image/*"
                maxSize={2 * 1024 * 1024}
                showPreview
                error={errors.logo}
            />

            {chosen && (
                <div className="wizard-summary">
                    <Icon
                        name={chosen.icon ?? 'buildings'}
                        size={18}
                        weight="duotone"
                        className="mt-0.5 shrink-0 text-[var(--color-brand-text)]"
                    />
                    <div className="min-w-0 space-y-0.5">
                        <p className="text-sm font-semibold text-[var(--color-text-main)]">
                            Set up for {chosen.name.toLowerCase()}
                        </p>
                        <p className="text-xs leading-relaxed text-[var(--color-text-muted)]">
                            {chosen.modules
                                ? `${chosen.modules} tools switched on to begin with. `
                                : ''}
                            Books kept in <strong className="font-semibold">{currency ?? '—'}</strong>
                            {country ? `, trading in ${country.name}` : ''}
                            {timezone ? `, on ${timezone.replace(/_/g, ' ')} time` : ''}.
                        </p>
                    </div>
                </div>
            )}

            {!chosen && (
                <div className="wizard-summary">
                    <Icon
                        name="scales"
                        size={18}
                        weight="duotone"
                        className="mt-0.5 shrink-0 text-[var(--color-brand-text)]"
                    />
                    <p className="text-xs leading-relaxed text-[var(--color-text-muted)]">
                        Books kept in <strong className="font-semibold">{currency ?? '—'}</strong>
                        {country ? `, trading in ${country.name}` : ''}
                        {timezone ? `, on ${timezone.replace(/_/g, ' ')} time` : ''}.
                    </p>
                </div>
            )}

            {errors.form && (
                <p role="alert" className="text-xs text-red-600">
                    {errors.form}
                </p>
            )}

            {/* Hidden submit button for Enter key */}
            <button type="submit" className="sr-only" tabIndex={-1}>
                Create business
            </button>
        </form>
    );
}

/**
 * A short code from a name: initials for several words, the first few letters
 * for one.
 */
function shortCodeFor(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return '';
    }

    if (words.length === 1) {
        return (words[0] ?? '').slice(0, 3).toUpperCase();
    }

    return words
        .map((word) => word[0] ?? '')
        .join('')
        .slice(0, 6)
        .toUpperCase();
}
