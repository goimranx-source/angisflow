import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FlyoutGuard } from '@/components/ui/FlyoutGuard';
import { Icon } from '@/components/ui/Icon';
import { useFlyoutPosition } from '@/hooks/useFlyoutPosition';
import { cn } from '@/lib/utils';

export type SelectOption = {
    value: string;
    label: string;

    /**
     * Quieter text after the label — the raw value behind a friendly name, a
     * warning that a field is read-only, a note that a shop has never used it.
     *
     * A separate field rather than glued onto the label, because these two are
     * read differently: the label is what somebody is looking for and the note
     * is what they check once they have found it. Concatenated, the note
     * competes with every label around it for attention it does not deserve.
     */
    note?: string;

    disabled?: boolean;
};

/**
 * How tall the list is allowed to get.
 *
 * About eight rows. Fewer and a list of forty feels like looking through a
 * letterbox; more and the panel starts covering the thing it was opened from,
 * which is disorienting on a table row. The window can still force it smaller
 * — see useFlyoutPosition, which knows how much room there actually is.
 */
const LIST_MAX = 264;

/**
 * The shortest list that gets a search box.
 *
 * A search box over three options is furniture. Below this, everything is on
 * screen at once and the eye beats typing; above it, scanning starts to lose
 * and a filter starts to win. Select2 leaves this at zero and shows the box
 * always, which is why its three-option dropdowns look like a search engine.
 */
const SEARCH_FROM = 8;

/** What the search box and its padding add to the panel's height. */
const SEARCH_BOX = 47;

/**
 * Does this option match what has been typed?
 *
 * Every word has to appear somewhere, in any order, across the label, the note
 * and the underlying value. Three consequences worth having:
 *
 *   "del slot" finds "Delivery Slot", because the words are matched separately
 *   rather than as one string.
 *
 *   "custom" finds a field whose label says nothing about being custom but
 *   whose value is `custom.delivery_slot` — the value is searched too, which is
 *   the whole point on a mapping screen where the raw name is what arrives on
 *   an order.
 *
 *   Typing more never widens the result, which is what makes a filter feel
 *   predictable rather than clever.
 */
function matches(option: SelectOption, terms: string[]): boolean {
    if (terms.length === 0) {
        return true;
    }

    const haystack = `${option.label} ${option.note ?? ''} ${option.value}`.toLowerCase();

    return terms.every((term) => haystack.includes(term));
}

/**
 * A select somebody can type into.
 *
 * ── Why not the browser's own ────────────────────────────────────────────────
 *
 * A native `<select>` is the right control right up until the list gets long.
 * The field-mapping screen has one holding every path a shop sends — around
 * forty on a plain WooCommerce shop, more with plugins — and another holding
 * every field this application can map onto. Finding `_billing_thana` in that
 * means opening the list and reading down it, because a native select's own
 * type-ahead only matches from the start of an option and gives up after a
 * second of not typing.
 *
 * It also cannot be styled. The list is drawn by the operating system, so its
 * height, its font and its behaviour differ on every machine the application
 * runs on, and none of them look like the rest of the page.
 *
 * ── What it keeps from the native one ────────────────────────────────────────
 *
 * The trigger is `.field`, the same class every input on the page uses, so it
 * is exactly as tall as the text box beside it and shares its border, its
 * radius and its focus ring. A control that is a different height from its
 * neighbours is the first thing anybody notices about a form.
 *
 * Arrow keys move, Enter chooses, Escape closes, and the value is announced as
 * a combobox — so it is still operable by somebody who never touches a mouse.
 *
 * ── Why it renders into the body ─────────────────────────────────────────────
 *
 * Because these live in table cells and inside modals, and both clip what
 * overflows them. A panel drawn where it sits in the markup would be cut off by
 * the row it belongs to, and no z-index fixes that: a number only ranks an
 * element among its siblings, and the clipping happens further up.
 */
export function SearchSelect({
    value,
    onChange,
    options,
    placeholder = 'Select…',
    disabled = false,
    id,
    ariaLabel,
    title,
    className,
    searchFrom = SEARCH_FROM,
    searchPlaceholder = 'Type to filter…',
}: {
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
    disabled?: boolean;
    id?: string;
    ariaLabel?: string;
    title?: string;
    className?: string;

    /** Lists shorter than this get no search box. */
    searchFrom?: number;
    searchPlaceholder?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    /*
     * Measured when it opens, not while it is open.
     *
     * The panel is as wide as the control it belongs to, and it has to be that
     * wide before it is measured for placement — a panel that resizes after
     * being positioned jumps sideways on the frame it appears.
     */
    const [width, setWidth] = useState(0);

    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const search = useRef<HTMLInputElement>(null);

    const listId = useId();
    const at = useFlyoutPosition({ open, trigger, panel, align: 'start' });

    const chosen = options.find((option) => option.value === value) ?? null;
    const searchable = options.length >= searchFrom;

    /** How tall the panel may get: the list, plus the search box if it has one. */
    const roof = LIST_MAX + (searchable ? SEARCH_BOX : 0);

    const shown = useMemo(() => {
        const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

        return options.filter((option) => matches(option, terms));
    }, [options, query]);

    // Opening lands on what is already chosen, so the list starts where the
    // reader's expectation is rather than at the top of forty rows.
    useEffect(() => {
        if (!open) {
            setQuery('');

            return;
        }

        const index = shown.findIndex((option) => option.value === value);

        setActive(index >= 0 ? index : 0);
        // Only when it opens. Re-running as the query changes would fight the
        // reset below, which is the one that belongs to typing.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Back to the top whenever the list changes under it, because the row that
    // was active is usually no longer in it.
    useEffect(() => {
        setActive(0);
    }, [query]);

    /*
     * Focus, but not before the panel has somewhere to be.
     *
     * It renders hidden for one frame while it is measured — see the style
     * below — and a hidden element cannot take focus. Asking it to silently did
     * nothing, so the search box appeared with a cursor nowhere near it and the
     * first thing anybody typed went to the page instead.
     */
    useLayoutEffect(() => {
        if (!open || !at) {
            return;
        }

        (searchable ? search.current : panel.current)?.focus();
    }, [open, at, searchable]);

    // The active row kept in view as the arrows walk past the fold.
    useEffect(() => {
        if (!open) {
            return;
        }

        list.current?.children[active]?.scrollIntoView({ block: 'nearest' });
    }, [active, open]);

    /*
     * ── Escape closes the list, and only the list ────────────────────────────
     *
     * These are opened from inside dialogs, and a dialog closes itself on
     * Escape through a listener on the document. So one press was closing the
     * picker and the dialog behind it together — losing a half-filled form
     * because somebody changed their mind about a dropdown.
     *
     * Stopping it has to happen on a real listener on the panel. React's
     * synthetic events are dispatched from its own root, so stopping one there
     * says nothing about a native listener on the document; the native event
     * has to be caught below that document listener and stopped before it gets
     * there. The panel is a child of the body, so a listener on it is.
     */
    useEffect(() => {
        const node = panel.current;

        if (!open || node === null) {
            return;
        }

        const onEscape = (event: KeyboardEvent) => {
            if (event.key !== 'Escape') {
                return;
            }

            event.stopPropagation();
            event.preventDefault();
            setOpen(false);
            trigger.current?.focus();
        };

        node.addEventListener('keydown', onEscape);

        return () => node.removeEventListener('keydown', onEscape);
    }, [open]);

    /*
     * ── Closing on a press elsewhere, judged against its own two elements ────
     *
     * FlyoutGuard offers to do this, and its test is "inside any flyout panel"
     * — which is right for a flyout that is the only one open and wrong for one
     * inside another. A picker in a filter panel would survive every press
     * anywhere in that panel, because all of it is inside a flyout.
     *
     * This one knows exactly which two elements are its own, so it can be
     * asked the question that actually matters.
     */
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPress = (event: PointerEvent) => {
            const target = event.target;

            if (!(target instanceof Node)) {
                return;
            }

            if (panel.current?.contains(target) || trigger.current?.contains(target)) {
                return;
            }

            setOpen(false);
        };

        // Next tick, so the press that opened this is not still travelling when
        // the listener goes on.
        const armed = window.setTimeout(
            () => document.addEventListener('pointerdown', onPress),
            0,
        );

        return () => {
            window.clearTimeout(armed);
            document.removeEventListener('pointerdown', onPress);
        };
    }, [open]);

    const choose = (option: SelectOption) => {
        if (option.disabled) {
            return;
        }

        onChange(option.value);
        setOpen(false);
        trigger.current?.focus();
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        const step = (by: number) => {
            event.preventDefault();

            if (shown.length === 0) {
                return;
            }

            // Wraps, so holding down arrow at the end of a long list returns to
            // the top rather than sticking.
            setActive((current) => (current + by + shown.length) % shown.length);
        };

        switch (event.key) {
            case 'ArrowDown':
                return step(1);
            case 'ArrowUp':
                return step(-1);
            case 'Home':
                event.preventDefault();

                return setActive(0);
            case 'End':
                event.preventDefault();

                return setActive(Math.max(0, shown.length - 1));
            case 'Enter': {
                event.preventDefault();

                const option = shown[active];

                if (option) {
                    choose(option);
                }

                return;
            }
            // Escape is handled natively, on the panel — see below. React's
            // stopPropagation would not keep it from the dialog's own document
            // listener, which is the whole problem.
            case 'Tab':
                // Closes rather than trapping. Tab means "I am done here", and a
                // picker that swallows it is a picker somebody has to reach for
                // the mouse to escape.
                setOpen(false);

                return;
            default:
        }
    };

    return (
        <>
            <button
                ref={trigger}
                id={id}
                type="button"
                role="combobox"
                aria-expanded={open}
                aria-haspopup="listbox"
                aria-controls={open ? listId : undefined}
                aria-label={ariaLabel}
                title={title}
                disabled={disabled}
                className={cn(
                    'field flex w-full items-center gap-2 text-left',
                    disabled && 'cursor-not-allowed opacity-60',
                    className,
                )}
                onClick={() => {
                    if (disabled) {
                        return;
                    }

                    setWidth(trigger.current?.offsetWidth ?? 0);
                    setOpen((was) => !was);
                }}
                onKeyDown={(event) => {
                    // Down opens it, the way every dropdown on a desktop does.
                    if (!open && (event.key === 'ArrowDown' || event.key === 'Enter')) {
                        event.preventDefault();
                        setWidth(trigger.current?.offsetWidth ?? 0);
                        setOpen(true);
                    }
                }}
            >
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate',
                        !chosen && 'text-[var(--color-text-subtle)]',
                    )}
                >
                    {chosen?.label ?? placeholder}
                </span>

                {/*
                  The note, only when there is room for it.

                  It is the part somebody checks rather than searches for, so on
                  a narrow control it is the half that goes. Hidden below `sm`
                  rather than truncated, because half a note reads as a broken
                  label.
                */}
                {chosen?.note && (
                    <span className="hidden shrink-0 truncate text-xs text-[var(--color-text-muted)] sm:inline">
                        {chosen.note}
                    </span>
                )}

                <Icon
                    name="caret-down"
                    size={12}
                    className={cn(
                        'shrink-0 text-[var(--color-text-muted)] transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {open &&
                createPortal(
                    <>
                        {/*
                          Here for the one-at-a-time rule alone; the dismissal
                          is handled above, against this picker's own elements.

                          The anchor is what tells the registry this may be
                          nested — a filter picker's trigger sits inside the
                          filter panel, and without that the two would close
                          each other.
                        */}
                        <FlyoutGuard
                            onClose={() => setOpen(false)}
                            dismissOnOutsidePress={false}
                            anchor={trigger}
                        />

                        <div
                            ref={panel}
                            data-flyout-panel
                            tabIndex={-1}
                            onKeyDown={onKeyDown}
                            className="fixed flex flex-col overflow-hidden rounded-[var(--shell-radius)] border shadow-lg outline-none"
                            style={{
                                top: at?.top ?? 0,
                                left: at?.left ?? 0,
                                width: Math.max(width, 200),

                                /*
                                 * The whole panel, not the list inside it.
                                 *
                                 * The cap was on the list, and the search box
                                 * above it is another forty-odd pixels that
                                 * nothing accounted for — so a panel told it
                                 * had room for 264 drew 311 and hung over the
                                 * bottom of the window by the difference.
                                 *
                                 * useFlyoutPosition measures the panel, so the
                                 * limit it hands back is the panel's. The list
                                 * takes whatever is left of it.
                                 */
                                maxHeight: Math.min(roof, at?.maxHeight ?? roof),
                                /*
                                 * Above a dialog, not below it.
                                 *
                                 * These are opened from inside modals as often
                                 * as not — the shop editor, the field map, the
                                 * order form — and at flyout height the list
                                 * renders behind the dialog holding its own
                                 * trigger. See --z-picker.
                                 */
                                zIndex: 'var(--z-picker)' as unknown as number,
                                borderColor: 'var(--shell-border)',
                                background: 'var(--color-card-bg)',

                                // Invisible until it has been measured and put
                                // where it belongs, so it is never seen in the
                                // top-left corner on its first frame.
                                visibility: at ? 'visible' : 'hidden',
                            }}
                        >
                            {searchable && (
                                <div
                                    className="shrink-0 border-b p-1.5"
                                    style={{ borderColor: 'var(--shell-border)' }}
                                >
                                    <div className="relative">
                                        <Icon
                                            name="magnifying-glass"
                                            size={13}
                                            className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[var(--color-text-subtle)]"
                                        />

                                        <input
                                            ref={search}
                                            type="text"
                                            value={query}
                                            onChange={(event) => setQuery(event.target.value)}
                                            placeholder={searchPlaceholder}
                                            className="field w-full pl-7"
                                            aria-label="Filter the options"
                                            aria-controls={listId}
                                        />
                                    </div>
                                </div>
                            )}

                            <ul
                                ref={list}
                                id={listId}
                                role="listbox"
                                aria-label={ariaLabel}
                                // min-h-0 as well as flex-1: a flex item will
                                // not shrink below its content without it, so
                                // the list would push the panel past the height
                                // it was just given.
                                className="min-h-0 flex-1 overflow-y-auto py-1"
                            >
                                {shown.map((option, index) => {
                                    const picked = option.value === value;

                                    return (
                                        <li
                                            key={option.value}
                                            role="option"
                                            aria-selected={picked}
                                            aria-disabled={option.disabled}
                                            /*
                                              Chosen on pointer-down, not click.
                                              A click fires after the press has
                                              already moved focus, and on a
                                              panel that closes on outside
                                              presses that ordering is a race.
                                            */
                                            onPointerDown={(event) => {
                                                event.preventDefault();
                                                choose(option);
                                            }}
                                            onPointerEnter={() => setActive(index)}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-2 px-2.5 py-1.5 text-sm',
                                                option.disabled &&
                                                    'cursor-not-allowed opacity-50',
                                                index === active && 'bg-[var(--shell-tint)]',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'min-w-0 flex-1 truncate',
                                                    picked
                                                        ? 'font-medium text-[var(--color-text-main)]'
                                                        : 'text-[var(--color-text-body)]',
                                                )}
                                            >
                                                {option.label}
                                            </span>

                                            {option.note && (
                                                <span className="shrink-0 truncate text-xs text-[var(--color-text-muted)]">
                                                    {option.note}
                                                </span>
                                            )}

                                            {/*
                                              A tick on the chosen one, and a
                                              gap the same width on the rest, so
                                              the labels stay in one column
                                              instead of shifting by 14px as the
                                              selection moves.
                                            */}
                                            <span className="w-3.5 shrink-0 text-[var(--color-brand)]">
                                                {picked && <Icon name="check" size={13} />}
                                            </span>
                                        </li>
                                    );
                                })}

                                {shown.length === 0 && (
                                    <li className="px-2.5 py-3 text-center text-sm text-[var(--color-text-muted)]">
                                        Nothing matches “{query.trim()}”
                                    </li>
                                )}
                            </ul>
                        </div>
                    </>,
                    document.body,
                )}
        </>
    );
}
