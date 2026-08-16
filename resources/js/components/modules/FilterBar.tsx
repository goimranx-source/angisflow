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
    className,
}: FilterBarProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 border-b border-[var(--color-border-light)] bg-white px-4',
                compact ? 'py-2.5' : 'py-3',
                className,
            )}
        >
            {/* Search Input */}
            {onSearchChange && (
                <div className="relative flex-1 min-w-[200px] max-w-[400px]">
                    <Icon
                        name="magnifying-glass"
                        size={16}
                        className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]"
                    />
                    <input
                        type="text"
                        value={searchValue}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder={searchPlaceholder}
                        className="w-full border border-[var(--color-border-light)] bg-white py-2 pl-10 pr-3 text-sm text-[var(--color-text-main)] placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    />
                </div>
            )}

            {/* Filters */}
            {filters && (
                <div className="flex flex-wrap items-center gap-2">
                    {filters}
                </div>
            )}

            {/* Spacer */}
            <div className="flex-1 min-w-0" />

            {/* View Controls */}
            {viewControls && (
                <div className="flex items-center gap-1">
                    {viewControls}
                </div>
            )}

            {/* Actions */}
            {actions && (
                <div className="flex items-center gap-2">
                    {actions}
                </div>
            )}
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
                'flex items-center gap-1.5 border border-[var(--color-border-light)] px-3 py-1.5 text-sm font-medium transition-colors',
                active
                    ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]'
                    : 'bg-white text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
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
}: {
    label?: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    placeholder?: string;
}) {
    return (
        <div className="flex items-center gap-2">
            {label && (
                <label className="text-xs font-medium text-[var(--color-text-muted)] whitespace-nowrap">
                    {label}:
                </label>
            )}
            <div className="relative">
                <select
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="appearance-none border border-[var(--color-border-light)] bg-white py-1.5 pl-3 pr-8 text-sm text-[var(--color-text-main)] focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)] cursor-pointer"
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
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)] pointer-events-none"
                />
            </div>
        </div>
    );
}
