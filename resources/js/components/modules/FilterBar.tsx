import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type FilterBarProps = {
    /** Search input value */
    searchValue?: string;
    /** Search change handler */
    onSearchChange?: (value: string) => void;
    /** Search placeholder text */
    searchPlaceholder?: string;
    /** Filter controls (dropdowns, date pickers, etc.) */
    filters?: ReactNode;
    /** View toggle buttons (grid/list, calendar/list) */
    viewControls?: ReactNode;
    /** Additional actions (export, import, etc.) */
    actions?: ReactNode;
    /** Compact mode (smaller height) */
    compact?: boolean;
    /**
     * Anything that belongs beside the search rather than opposite it.
     *
     * A period picker narrows *what is being looked at*, which is the same job
     * the search box does — so it sits with the search, and the controls that
     * decide how the list is shown stay on the other side of the row.
     */
    trailingSearch?: ReactNode;
    /**
     * Put the filters on their own row beneath the search.
     *
     * ── Why this is opt-in ───────────────────────────────────────────────
     *
     * One row is right for the common case — a search and two dropdowns sit
     * comfortably across a page and reading them left to right is natural.
     * It stops being right somewhere around the fourth filter: the row wraps
     * at whatever width the browser happens to break at, the spacer that
     * pushes the view toggles right ends up on a line of its own, and the
     * same screen looks different at every window size.
     *
     * Screens with that many filters say so, rather than every screen in the
     * application changing shape because one of them needed to.
     */
    stacked?: boolean;
    /** Additional class names */
    className?: string;
};

/**
 * Filter bar component for list views.
 * 
 * Provides search, filters, view controls in a consistent layout.
 * 
 * Example:
 * ```tsx
 * <FilterBar
 *   searchValue={search}
 *   onSearchChange={setSearch}
 *   searchPlaceholder="Search customers..."
 *   filters={
 *     <>
 *       <Select value={status} onChange={setStatus}>
 *         <option value="">All statuses</option>
 *         <option value="active">Active</option>
 *         <option value="inactive">Inactive</option>
 *       </Select>
 *       <DateRangePicker from={dateFrom} to={dateTo} onChange={handleDateChange} />
 *     </>
 *   }
 *   viewControls={
 *     <div className="flex gap-1">
 *       <button onClick={() => setView('grid')}>Grid</button>
 *       <button onClick={() => setView('list')}>List</button>
 *     </div>
 *   }
 *   actions={
 *     <button className="btn btn-secondary">Export</button>
 *   }
 * />
 * ```
 */
export function FilterBar({
    searchValue = '',
    onSearchChange,
    searchPlaceholder = 'Search...',
    filters,
    viewControls,
    actions,
    compact = false,
    trailingSearch,
    stacked = false,
    className,
}: FilterBarProps) {
    const search = onSearchChange && (
        <div className={cn('relative min-w-[200px] flex-1', stacked ? 'max-w-none' : 'max-w-[400px]')}>
            <Icon
                name="magnifying-glass"
                size={16}
                className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]"
            />
            {/*
              A stated height rather than padding.
            
              Vertical padding plus a line box came to 38px against the 36 of
              every button beside it, and two pixels is enough to see on a row
              of controls without being enough to name. Stating the height means
              it cannot drift again when the type size changes.
            */}
            <input
                type="text"
                value={searchValue}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder={searchPlaceholder}
                className="h-8 w-full border border-[var(--color-border-light)] bg-[var(--color-card-bg)] pl-10 pr-3 text-sm text-[var(--color-text-main)] placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                style={{ borderRadius: 'var(--shell-radius)' }}
            />
        </div>
    );

    const trailing = (viewControls || actions) && (
        <div className="flex items-center gap-2">
            {viewControls && <div className="flex items-center gap-1">{viewControls}</div>}
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );

    // A full border and the shell radius, so the filter reads as a block on the
    // page rather than a strip welded to whatever is beneath it.
    const shell = cn(
        'rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)]',
        className,
    );

    if (stacked) {
        return (
            <div className={shell}>
                {/* What you are looking for, and how you want to see it. */}
                {(search || trailing) && (
                    <div
                        className={cn(
                            'flex flex-wrap items-center gap-3 px-4',
                            compact ? 'py-2.5' : 'py-3',
                        )}
                    >
                        {search}
                        <div className="min-w-0 flex-1" />
                        {trailing}
                    </div>
                )}

                {/*
                  And how it is narrowed down. Its own row, divided from the
                  search, so the number of filters cannot change the shape of
                  anything above it.
                */}
                {filters && (
                    <div
                        className={cn(
                            'flex flex-wrap items-center gap-2 px-4',
                            compact ? 'py-2' : 'py-2.5',
                            (search || trailing) && 'border-t border-[var(--color-border-light)]',
                        )}
                    >
                        {filters}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 px-4',
                compact ? 'py-2.5' : 'py-3',
                shell,
            )}
        >
            {search}

            {trailingSearch}

            {filters && <div className="flex flex-wrap items-center gap-2">{filters}</div>}

            <div className="min-w-0 flex-1" />

            {trailing}
        </div>
    );
}

/**
 * View toggle button for use in FilterBar viewControls.
 */
export function ViewToggleButton({
    icon,
    label,
    active = false,
    onClick,
    badge,
}: {
    icon: string;
    label?: string;
    active?: boolean;
    onClick: () => void;
    /** Count shown next to the label, e.g. an unread/queue count */
    badge?: number;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={label}
            className={cn(
                'flex h-8 items-center gap-1.5 border border-[var(--color-border-light)] px-3 text-sm font-medium transition-colors',
                active
                    ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]'
                    : 'bg-[var(--color-card-bg)] text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
            )}
            style={{ borderRadius: 'var(--shell-radius-sm)' }}
        >
            <Icon name={icon} size={16} weight={active ? 'fill' : 'regular'} />
            {label && <span>{label}</span>}
            {badge !== undefined && badge > 0 && (
                <span
                    className={cn(
                        'rounded-full px-1.5 py-0.5 text-xs font-semibold',
                        active
                            ? 'bg-white/20 text-white'
                            : 'bg-[var(--color-brand-subtle)] text-[var(--color-brand)]',
                    )}
                >
                    {badge}
                </span>
            )}
        </button>
    );
}

/**
 * Filter select dropdown for use in FilterBar filters.
 */
export function FilterSelect({
    label,
    value,
    onChange,
    options,
    placeholder = 'All',
    block = false,
}: {
    label?: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    placeholder?: string;
    /**
     * Fill the width instead of sizing to the longest option.
     *
     * ── Why it is a choice and not the default ───────────────────────────────
     *
     * Stacked in a panel, three of these sizing themselves to "All statuses",
     * "All payments" and "All stores" gave three different widths down one
     * narrow column, which reads as three unrelated controls rather than one
     * set of filters. Filling is obviously right there.
     *
     * On a toolbar it is obviously wrong: `flex-1` on a row of selects makes
     * each one grow to a share of whatever is left, so a two-option filter
     * ends up as wide as the search box beside it.
     *
     * The container knows which of those it is; this component cannot.
     */
    block?: boolean;
}) {
    return (
        /* gap-1.5, the same distance the select-all checkbox keeps from its
           own words. A label and the control it names are one thing, and eight
           pixels between them reads as two. */
        <div className={cn('flex items-center gap-1.5', block && 'w-full')}>
            {label && (
                <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                    {label}:
                </label>
            )}
            {/* min-w-0 as well as flex-1: a flex item's floor is its content,
                and a long option name would push the box past the panel's edge
                rather than truncating inside it. The dropdown itself is drawn
                by the browser and shows every label in full whatever width the
                closed control is. */}
            <div className={cn('relative', block && 'min-w-0 flex-1')}>
                <select
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={cn(
                        'appearance-none border border-[var(--color-border-light)] bg-[var(--color-card-bg)] py-1.5 pl-2.5 pr-7 text-sm text-[var(--color-text-main)] focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)] cursor-pointer',
                        block && 'w-full truncate',
                    )}
                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                >
                    <option value="">{placeholder}</option>
                    {options.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                            {opt.label}
                        </option>
                    ))}
                </select>
                <Icon
                    name="caret-down"
                    size={12}
                    className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]"
                />
            </div>
        </div>
    );
}
