import { useId, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { InfoHint } from '@/components/ui/InfoHint';
import { SearchSelect, type SelectOption } from '@/components/ui/SearchSelect';
import { cn } from '@/lib/utils';

/**
 * The form controls this application uses, all wearing the same clothes.
 *
 * ── The problem this replaces ────────────────────────────────────────────────
 *
 * Every screen was building its own. The order editor had a `Text`, a `Money`,
 * a `Choice` and an `Area` defined inside the component that rendered them; the
 * shop editor wrote `<label>` and `<input className="field w-full">` by hand
 * each time; a third page used the half-finished primitives in this folder that
 * nobody had adopted. Three dialects of the same sentence, and they had drifted
 * — different label sizes, different gaps, help text in three different greys,
 * some fields explaining themselves and some not.
 *
 * The cost is not only that it looks untidy. A control defined inside a
 * component is redefined on every render, which throws away the DOM node and
 * the focus in it; that is why typing in some of these forms lost the cursor
 * after each keystroke.
 *
 * ── What a field is here ─────────────────────────────────────────────────────
 *
 * A label, a control, and — only when there is something worth saying — a way
 * to say it. Every one of them is the same height, uses the same `.field`
 * styling as everything else on the page, and binds its label, its hint and its
 * error to the control by id so a screen reader reads them in that order.
 *
 * ── Why explanations hide behind an icon ─────────────────────────────────────
 *
 * A paragraph of grey text under every field doubles the height of a form and
 * is read once, by the person who wrote it. But the explanation still has to
 * exist: "shipping is what you charged, not what it cost" is not guessable.
 *
 * So there are two levels. `hint` is a few words that belong on screen — a unit,
 * a format, a default. `info` is a sentence or two that belongs behind the ⓘ
 * next to the label, available on hover and on focus, out of the way until
 * somebody wants it.
 */

/** What every field accepts, whatever kind of control it draws. */
type Common = {
    label: string;

    /** A few words, always visible. A unit, a format, a default. */
    hint?: ReactNode;

    /** A sentence or two, behind the ⓘ beside the label. */
    info?: ReactNode;

    error?: string;
    disabled?: boolean;

    /** Marks beside the label — whether the shop syncs it, for instance. */
    badge?: ReactNode;

    /** Takes the whole row in a two-column grid. */
    wide?: boolean;
};

/**
 * The label, the marks beside it, and whatever the control turned out to be.
 *
 * Every control below is this wrapper with something different inside it, which
 * is the only reason they all line up.
 */
function Wrap({
    id,
    label,
    hint,
    info,
    error,
    badge,
    wide,
    children,
}: Common & { id: string; children: ReactNode }) {
    return (
        <div className={cn('min-w-0', wide && 'sm:col-span-2')}>
            <div className="mb-1.5 flex items-center gap-1.5">
                <label
                    htmlFor={id}
                    className="text-[13px] font-medium text-[var(--color-text-body)]"
                >
                    {label}
                </label>

                {badge}

                {info && <InfoHint label={`About ${label}`}>{info}</InfoHint>}
            </div>

            {children}

            {/*
              One line at a time, and the error wins.

              A field showing both its hint and its complaint asks somebody to
              work out which of the two applies right now.
            */}
            {error ? (
                <p
                    id={`${id}-note`}
                    role="alert"
                    className="mt-1 flex items-center gap-1 text-xs text-[var(--color-danger-text)]"
                >
                    <Icon name="warning-circle" size={12} />
                    {error}
                </p>
            ) : hint ? (
                <p id={`${id}-note`} className="mt-1 text-xs text-[var(--color-text-subtle)]">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

/** The attributes that tie a control to its label and its message. */
function wiring(id: string, error?: string, described?: boolean) {
    return {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error || described ? `${id}-note` : undefined,
    };
}

export function TextField({
    value,
    onChange,
    type = 'text',
    placeholder,
    readOnly,
    maxLength,
    ...rest
}: Common & {
    value: string;
    onChange: (value: string) => void;
    type?: 'text' | 'email' | 'tel' | 'url' | 'date' | 'datetime-local' | 'number' | 'password';
    placeholder?: string;
    readOnly?: boolean;
    maxLength?: number;
}) {
    const id = useId();

    return (
        <Wrap {...rest} id={id}>
            <input
                {...wiring(id, rest.error, Boolean(rest.hint))}
                type={type}
                value={value}
                placeholder={placeholder}
                readOnly={readOnly}
                disabled={rest.disabled}
                maxLength={maxLength}
                onChange={(event) => onChange(event.target.value)}
                className={cn(
                    'field w-full',
                    rest.error && 'border-[var(--color-danger)]',
                    readOnly && 'cursor-not-allowed opacity-60',
                )}
            />
        </Wrap>
    );
}

/**
 * Money, with its currency shown and its digits lined up.
 *
 * Right-aligned and tabular, because the reason these are in a column is so
 * they can be compared down it, and proportional digits make 1,111 narrower
 * than 8,888.
 */
export function MoneyField({
    value,
    onChange,
    symbol,
    readOnly,
    ...rest
}: Common & {
    value: string;
    onChange: (value: string) => void;
    symbol: string;
    readOnly?: boolean;
}) {
    const id = useId();

    return (
        <Wrap {...rest} id={id}>
            <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--color-text-muted)]">
                    {symbol}
                </span>

                <input
                    {...wiring(id, rest.error, Boolean(rest.hint))}
                    type="number"
                    step="any"
                    min="0"
                    value={value}
                    readOnly={readOnly}
                    disabled={rest.disabled}
                    onChange={(event) => onChange(event.target.value)}
                    className={cn(
                        'field w-full pl-7 text-right tabular-nums',
                        rest.error && 'border-[var(--color-danger)]',
                        readOnly && 'cursor-not-allowed opacity-60',
                    )}
                />
            </div>
        </Wrap>
    );
}

export function TextAreaField({
    value,
    onChange,
    rows = 3,
    placeholder,
    mono,
    ...rest
}: Common & {
    value: string;
    onChange: (value: string) => void;
    rows?: number;
    placeholder?: string;
    mono?: boolean;
}) {
    const id = useId();

    return (
        <Wrap {...rest} id={id}>
            <textarea
                {...wiring(id, rest.error, Boolean(rest.hint))}
                rows={rows}
                value={value}
                placeholder={placeholder}
                disabled={rest.disabled}
                onChange={(event) => onChange(event.target.value)}
                className={cn(
                    'field w-full',
                    mono && 'font-mono text-xs',
                    rest.error && 'border-[var(--color-danger)]',
                )}
            />
        </Wrap>
    );
}

/** A choice, drawn by the searchable picker every dropdown here uses. */
export function SelectField({
    value,
    onChange,
    options,
    placeholder,
    ...rest
}: Common & {
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
}) {
    const id = useId();

    return (
        <Wrap {...rest} id={id}>
            <SearchSelect
                id={id}
                value={value}
                onChange={onChange}
                options={options}
                placeholder={placeholder}
                disabled={rest.disabled}
                ariaLabel={rest.label}
            />
        </Wrap>
    );
}

/**
 * A yes-or-no, drawn as a switch rather than a tick box.
 *
 * ── Why it does not use Wrap ─────────────────────────────────────────────────
 *
 * Every other control here is a label above a box. A switch is a label *beside*
 * a control, because the label is the sentence the switch answers — stacking
 * them leaves a lone toggle under a heading, and a row of those reads as a list
 * of headings with debris underneath.
 */
export function SwitchField({
    value,
    onChange,
    label,
    info,
    hint,
    badge,
    disabled,
    wide,
}: Common & {
    value: boolean;
    onChange: (value: boolean) => void;
}) {
    const id = useId();

    return (
        <div className={cn('min-w-0', wide && 'sm:col-span-2')}>
            <label
                htmlFor={id}
                className={cn(
                    'flex items-center gap-2.5',
                    disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                )}
            >
                <button
                    id={id}
                    type="button"
                    role="switch"
                    aria-checked={value}
                    disabled={disabled}
                    onClick={() => onChange(!value)}
                    className="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                    style={{
                        background: value ? 'var(--color-brand)' : 'var(--color-border-strong)',
                    }}
                >
                    <span
                        className="inline-block size-4 rounded-full bg-white shadow transition-transform"
                        style={{ transform: `translateX(${value ? 18 : 2}px)` }}
                    />
                </button>

                <span className="flex items-center gap-1.5 text-[13px] font-medium text-[var(--color-text-body)]">
                    {label}
                    {badge}
                </span>

                {info && <InfoHint label={`About ${label}`}>{info}</InfoHint>}
            </label>

            {hint && <p className="mt-1 pl-[46px] text-xs text-[var(--color-text-subtle)]">{hint}</p>}
        </div>
    );
}

/**
 * A value this form does not set.
 *
 * ── Why it is not a disabled input ───────────────────────────────────────────
 *
 * A greyed-out box says "you may not change this", which invites the question
 * of who may and how. These are figures nobody changes because nothing changes
 * them directly — a total is what the lines add up to, and the way to alter it
 * is to alter a line. Drawn as a fact rather than a broken field, that is
 * obvious without a sentence explaining it.
 */
export function ReadOnlyField({
    value,
    label,
    info,
    hint,
    badge,
    strong,
    tone,
    wide,
}: Omit<Common, 'disabled' | 'error'> & {
    value: ReactNode;
    /** For the one line that is the answer, where the others are workings. */
    strong?: boolean;
    tone?: string;
}) {
    return (
        <div className={cn('min-w-0', wide && 'sm:col-span-2')}>
            <div className="mb-1.5 flex items-center gap-1.5">
                <span className="text-[13px] font-medium text-[var(--color-text-body)]">{label}</span>
                {badge}
                {info && <InfoHint label={`About ${label}`}>{info}</InfoHint>}
            </div>

            <p
                className={cn(
                    'tabular-nums',
                    strong
                        ? 'text-base font-semibold text-[var(--color-text-main)]'
                        : 'text-sm text-[var(--color-text-main)]',
                )}
                style={tone ? { color: tone } : undefined}
            >
                {value}
            </p>

            {hint && <p className="mt-0.5 text-xs text-[var(--color-text-subtle)]">{hint}</p>}
        </div>
    );
}

/**
 * A titled box around a group of fields.
 *
 * ── Why groups and not headings ──────────────────────────────────────────────
 *
 * Twelve customer fields and eight delivery fields in one flat column is a
 * wall: the eye has nothing to stop at, and two fields with similar names a
 * screen apart look like the same field twice. A boundary and a name gives each
 * group somewhere to begin and end.
 */
export function FieldGroup({
    title,
    icon,
    hint,
    info,
    action,
    flush,
    id,
    children,
}: {
    title: string;
    icon?: string;
    /** A count, a currency — the short fact that belongs in the head. */
    hint?: ReactNode;
    info?: ReactNode;
    action?: ReactNode;
    /** No padding, for a table that should meet its own box. */
    flush?: boolean;
    id?: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className="overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--color-card-bg)]"
            /*
             * Somewhere to land when a section is jumped to from the contents,
             * so the heading is not left under the sticky bar above it.
             */
            style={{ scrollMarginTop: '4rem' }}
        >
            <header className="flex items-center justify-between gap-3 border-b border-[var(--shell-border)] bg-[var(--color-site-bg)] px-4 py-2.5">
                <h3 className="flex items-center gap-2 text-[13px] font-semibold text-[var(--color-text-main)]">
                    {icon && (
                        <Icon name={icon} size={14} className="text-[var(--color-text-muted)]" />
                    )}
                    {title}
                    {info && <InfoHint label={`About ${title}`}>{info}</InfoHint>}
                </h3>

                <div className="flex items-center gap-2">
                    {hint && (
                        <span className="text-[11px] tabular-nums text-[var(--color-text-muted)]">
                            {hint}
                        </span>
                    )}
                    {action}
                </div>
            </header>

            <div className={flush ? '' : 'p-4'}>{children}</div>
        </section>
    );
}

/** Two to a row on anything wider than a phone, one on a phone. */
export function FieldGrid({ children }: { children: ReactNode }) {
    return <div className="grid gap-4 sm:grid-cols-2">{children}</div>;
}
