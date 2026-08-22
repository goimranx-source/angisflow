import { cn } from '@/lib/utils';

type Option<T extends string> = { key: T; label: string };

type SegmentedControlProps<T extends string> = {
    options: ReadonlyArray<Option<T>>;
    value: T;
    onChange: (key: T) => void;
    /** Tighter, for sitting inside a panel head rather than a page header. */
    size?: 'sm' | 'md';
    className?: string;
    /** Names the group for screen readers — "Period", "Chart range". */
    label?: string;
};

/**
 * A small run of mutually exclusive choices — a period, a range, a view.
 *
 * ── Buttons, not a select ────────────────────────────────────────────────────
 *
 * These are three to five short options that get changed constantly, and a
 * dropdown makes each change two clicks and hides the alternatives until the
 * first one. Laid out flat, the choice and what else is available are the same
 * glance.
 *
 * Written once because it appears in the page header and inside half the panels
 * on the dashboard, and hand-rolled copies had already drifted — one of them
 * hard-coded a white background, which is invisible on the dark theme.
 */
export function SegmentedControl<T extends string>({
    options,
    value,
    onChange,
    size = 'md',
    className,
    label,
}: SegmentedControlProps<T>) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'flex flex-none items-center gap-0.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] p-0.5',
                className,
            )}
        >
            {options.map((option) => (
                <button
                    key={option.key}
                    type="button"
                    onClick={() => onChange(option.key)}
                    aria-pressed={value === option.key}
                    className={cn(
                        'rounded-[var(--shell-radius-sm)] font-semibold transition-colors',
                        size === 'sm' ? 'px-2 py-1 text-[0.6875rem]' : 'px-2.5 py-1.5 text-xs',
                        value === option.key
                            ? 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                            : 'text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
