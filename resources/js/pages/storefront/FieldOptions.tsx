import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';

export type FieldOption = { label: string; value: string };

/**
 * Turn typed text into options.
 *
 * `Facebook|facebook` gives a choice labelled Facebook that the shop stores as
 * `facebook`. `Facebook` alone gives both — plenty of shops store exactly what
 * they display, and making somebody type it twice to say so is a tax.
 *
 * Several at once are accepted, split on commas and newlines, because the
 * options usually already exist somewhere — in the plugin's settings, in a
 * spreadsheet — and pasting the lot beats typing them one at a time.
 */
export function parseOptions(text: string): FieldOption[] {
    return text
        .split(/[\n,]+/)
        .map((piece) => piece.trim())
        .filter((piece) => piece !== '')
        .map((piece) => {
            const bar = piece.indexOf('|');

            if (bar === -1) return { label: piece, value: piece };

            const label = piece.slice(0, bar).trim();
            const value = piece.slice(bar + 1).trim();

            // A trailing bar with nothing after it — "Facebook|" — means they
            // started typing the stored name and stopped. Treated as no bar at
            // all rather than saved as a choice with an empty value, which is a
            // choice that clears the field when picked.
            if (value === '') return { label, value: label };
            if (label === '') return { label: value, value };

            return { label, value };
        });
}

/**
 * The choices behind a select, a radio group or a checkbox list.
 *
 * ── Why they are typed in rather than read from the shop ─────────────────────
 *
 * An order carries one value. An order whose source is Facebook proves that
 * Facebook is possible and says nothing at all about WhatsApp, and no amount of
 * reading orders will produce the full list — the definition lives inside
 * whichever plugin drew the dropdown, and no API hands it over.
 *
 * So they are stated once here, and every order afterwards shows a real
 * dropdown instead of a text box somebody can misspell into.
 */
export function FieldOptions({
    value,
    onChange,
}: {
    value: FieldOption[];
    onChange: (next: FieldOption[]) => void;
}) {
    const [draft, setDraft] = useState('');

    const commit = () => {
        const parsed = parseOptions(draft);

        if (parsed.length === 0) return;

        // Existing ones win, so re-adding a choice does not quietly relabel the
        // one already saved against however many orders.
        const seen = new Set(value.map((option) => option.value));

        onChange([...value, ...parsed.filter((option) => !seen.has(option.value))]);
        setDraft('');
    };

    const removeAt = (index: number) => onChange(value.filter((_, i) => i !== index));

    return (
        <div className="space-y-2 rounded-[var(--shell-radius)] border border-dashed p-2.5"
             style={{ borderColor: 'var(--shell-border)' }}>
            <div className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-text-muted)]">
                <Icon name="list" size={12} />
                Choices
                {value.length === 0 && (
                    <span style={{ color: 'var(--color-warning, #b45309)' }}>
                        — none yet, so this shows as a plain box
                    </span>
                )}
            </div>

            {value.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {value.map((option, index) => (
                        <span
                            key={option.value}
                            className="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs"
                            style={{ borderColor: 'var(--shell-border)' }}
                        >
                            <span>{option.label}</span>

                            {/* Only when they differ — showing "Facebook · facebook"
                                for a choice stored exactly as it reads is noise. */}
                            {option.label !== option.value && (
                                <code className="text-[10px] text-[var(--color-text-muted)]">
                                    {option.value}
                                </code>
                            )}

                            <button
                                type="button"
                                onClick={() => removeAt(index)}
                                aria-label={`Remove ${option.label}`}
                                className="opacity-60 transition hover:opacity-100"
                            >
                                <Icon name="x" size={10} />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            <input
                className="field w-full text-xs"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    commit();
                }}
                // Committed on the way out too. Typing a choice and clicking
                // Save without pressing Enter first should not throw it away.
                onBlur={commit}
                placeholder="Facebook|facebook — then Enter. Paste a list to add several."
                aria-label="Add a choice"
            />
        </div>
    );
}
