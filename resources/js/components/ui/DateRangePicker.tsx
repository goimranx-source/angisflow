import { useEffect, useMemo, useRef, useState } from 'react';

import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

export type DateRange = { start: Date; end: Date };

type Preset = { key: string; label: string; range: () => DateRange };

const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
const addDays = (d: Date, n: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();

/**
 * The ranges worth one click.
 *
 * Kept beside the calendar rather than instead of it. "This month" is what
 * somebody wants nine times out of ten and picking it off a grid is four
 * interactions; the tenth time they want 3–17 August and no list of presets
 * will ever contain it.
 */
const PRESETS: Preset[] = [
    {
        key: 'today',
        label: 'Today',
        range: () => ({ start: startOfDay(new Date()), end: startOfDay(new Date()) }),
    },
    {
        key: 'last_7_days',
        label: 'Last 7 days',
        range: () => ({ start: addDays(startOfDay(new Date()), -6), end: startOfDay(new Date()) }),
    },
    {
        key: 'this_month',
        label: 'This month',
        range: () => {
            const now = new Date();

            return {
                start: new Date(now.getFullYear(), now.getMonth(), 1),
                end: new Date(now.getFullYear(), now.getMonth() + 1, 0),
            };
        },
    },
    {
        key: 'last_month',
        label: 'Last month',
        range: () => {
            const now = new Date();

            return {
                start: new Date(now.getFullYear(), now.getMonth() - 1, 1),
                end: new Date(now.getFullYear(), now.getMonth(), 0),
            };
        },
    },
    {
        key: 'this_year',
        label: 'This year',
        range: () => {
            const now = new Date();

            return {
                start: new Date(now.getFullYear(), 0, 1),
                end: new Date(now.getFullYear(), 11, 31),
            };
        },
    },
];

function formatRange(range: DateRange | null): string {
    if (!range) {
        return 'Select dates';
    }

    const fmt = (d: Date) =>
        d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });

    return sameDay(range.start, range.end)
        ? fmt(range.start)
        : `${fmt(range.start)} to ${fmt(range.end)}`;
}

/** The cells of one month, padded to whole weeks so the grid never jumps. */
function monthGrid(view: Date): Array<{ date: Date; outside: boolean }> {
    const first = new Date(view.getFullYear(), view.getMonth(), 1);
    const start = addDays(first, -first.getDay());

    return Array.from({ length: 42 }, (_, i) => {
        const date = addDays(start, i);

        return { date, outside: date.getMonth() !== view.getMonth() };
    });
}

type DateRangePickerProps = {
    value?: DateRange | null;
    onChange?: (range: DateRange) => void;
    className?: string;
};

/**
 * A date range, chosen from a calendar.
 *
 * ── What this replaces ───────────────────────────────────────────────────────
 *
 * A button that printed today's date twice and opened nothing. Its own comment
 * admitted the calendar was missing — so the control was on screen, looked
 * pressable, and did nothing, which reads as the product being broken rather
 * than as a feature not yet built.
 *
 * ── How the range is picked ──────────────────────────────────────────────────
 *
 * First click sets the start and clears the end, second click closes it, and a
 * second click before the first extends backwards rather than being refused —
 * people pick the end date first often enough that treating it as a mistake is
 * the wrong call. Between the two, the cell under the pointer previews the span
 * so the shading answers "how much am I about to select" before committing.
 */
export function DateRangePicker({ value, onChange, className }: DateRangePickerProps) {
    const [open, setOpen] = useState(false);
    const [view, setView] = useState(() => value?.start ?? new Date());
    const [anchor, setAnchor] = useState<Date | null>(null);
    const [hovered, setHovered] = useState<Date | null>(null);
    const container = useRef<HTMLDivElement>(null);

    // Dismissed by clicking away or pressing Escape — both, because a panel
    // that only closes one way is one people end up clicking around.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (event: MouseEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
                setAnchor(null);
            }
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                setAnchor(null);
            }
        };

        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const cells = useMemo(() => monthGrid(view), [view]);

    // While picking, the span follows the pointer; settled, it is the value.
    const preview: DateRange | null = anchor
        ? hovered && hovered < anchor
            ? { start: hovered, end: anchor }
            : { start: anchor, end: hovered ?? anchor }
        : (value ?? null);

    const commit = (range: DateRange) => {
        onChange?.(range);
        setAnchor(null);
        setOpen(false);
    };

    const pick = (date: Date) => {
        if (!anchor) {
            setAnchor(date);
            setHovered(date);

            return;
        }

        commit(date < anchor ? { start: date, end: anchor } : { start: anchor, end: date });
    };

    const inRange = (d: Date) => preview && d >= startOfDay(preview.start) && d <= startOfDay(preview.end);
    const isEdge = (d: Date) => preview && (sameDay(d, preview.start) || sameDay(d, preview.end));

    return (
        <div ref={container} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                aria-expanded={open}
                className={cn(
                    'flex h-9 items-center gap-2 rounded-[var(--shell-radius)] border px-3 text-xs font-semibold transition-colors',
                    open
                        ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]'
                        : 'border-[var(--shell-border)] bg-[var(--shell-bg)] text-[var(--shell-text-strong)] hover:bg-[var(--shell-hover)]',
                )}
            >
                <Icon name="calendar-blank" size={15} />
                <span className="whitespace-nowrap">{formatRange(value ?? null)}</span>
                <Icon name="caret-down" size={12} />
            </button>

            {open && (
                /*
                  A sheet under it, like every other panel that opens over the
                  page.

                  This closed on an outside click through a document listener
                  and drew nothing, so while the calendar was open the whole
                  page stayed live underneath: every button still lit on hover
                  and still showed a hand, and the first click on any of them
                  went to that button rather than closing the calendar.

                  The listener stays -- it is what handles a click that lands
                  outside the window entirely, and Escape.
                */
                <FlyoutGuard onClose={() => setOpen(false)} dismissOnOutsidePress={false} />
            )}

            {open && (
                <div
                    data-flyout-panel
                    className="absolute right-0 z-[var(--z-flyout-panel)] mt-1.5 flex overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] shadow-[var(--shadow-lg)]"
                    style={{
                        /*
                          The same arrival as every other panel that opens over
                          the page.

                          A calendar is the largest thing on this row and was the
                          only one appearing without warning — the bigger the
                          panel, the more it wants a moment to say where it came
                          from.
                        */
                        animation: 'context-flyout-slide-up 120ms ease-out',
                        transformOrigin: 'top right',
                    }}
                >
                    <ul className="hidden w-36 flex-none border-r border-[var(--shell-border)] p-1.5 sm:block">
                        {PRESETS.map((preset) => (
                            <li key={preset.key}>
                                <button
                                    type="button"
                                    onClick={() => commit(preset.range())}
                                    className="w-full rounded-[var(--shell-radius-sm)] px-2.5 py-1.5 text-left text-xs font-medium text-[var(--color-text-body)] transition-colors hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                                >
                                    {preset.label}
                                </button>
                            </li>
                        ))}
                    </ul>

                    <div className="w-[17rem] flex-none p-3">
                        <div className="flex items-center justify-between">
                            <button
                                type="button"
                                aria-label="Previous month"
                                onClick={() => setView(new Date(view.getFullYear(), view.getMonth() - 1, 1))}
                                className="grid size-7 place-items-center rounded-[var(--shell-radius-sm)] text-[var(--color-text-muted)] transition-colors hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                            >
                                <Icon name="caret-left" size={14} />
                            </button>

                            <span className="text-xs font-semibold text-[var(--color-text-main)]">
                                {view.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}
                            </span>

                            <button
                                type="button"
                                aria-label="Next month"
                                onClick={() => setView(new Date(view.getFullYear(), view.getMonth() + 1, 1))}
                                className="grid size-7 place-items-center rounded-[var(--shell-radius-sm)] text-[var(--color-text-muted)] transition-colors hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                            >
                                <Icon name="caret-right" size={14} />
                            </button>
                        </div>

                        <div className="mt-2 grid grid-cols-7 text-center">
                            {['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((day, i) => (
                                <span
                                    key={i}
                                    className="py-1 text-[0.625rem] font-semibold text-[var(--color-text-muted)]"
                                >
                                    {day}
                                </span>
                            ))}

                            {cells.map(({ date, outside }) => {
                                const edge = isEdge(date);
                                const within = inRange(date) && !edge;

                                return (
                                    <button
                                        key={date.toISOString()}
                                        type="button"
                                        onClick={() => pick(date)}
                                        onMouseEnter={() => setHovered(date)}
                                        className={cn(
                                            'h-8 text-xs font-medium transition-colors',
                                            // Square middles, rounded ends, so a
                                            // selected span reads as one bar
                                            // rather than a row of separate pills.
                                            edge && 'rounded-[var(--shell-radius-sm)] bg-[var(--color-brand)] font-bold text-[var(--color-text-on-accent)]',
                                            within && 'bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]',
                                            !edge && !within && 'rounded-[var(--shell-radius-sm)] hover:bg-[var(--shell-hover)]',
                                            !edge && (outside
                                                ? 'text-[var(--color-text-subtle)]'
                                                : 'text-[var(--color-text-body)]'),
                                        )}
                                    >
                                        {date.getDate()}
                                    </button>
                                );
                            })}
                        </div>

                        <p className="mt-2 border-t border-[var(--shell-border)] pt-2 text-center text-[0.6875rem] text-[var(--color-text-muted)]">
                            {anchor ? 'Pick the end date' : formatRange(value ?? null)}
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
