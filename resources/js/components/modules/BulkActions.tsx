import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type BulkActionsProps = {
    /** Number of selected items */
    selectedCount: number;
    /** Actions to show */
    children: ReactNode;
    /** Clear selection handler */
    onClearSelection: () => void;
    /** Position */
    position?: 'top' | 'bottom' | 'floating';
    /** Additional class names */
    className?: string;
};

/**
 * Bulk actions toolbar that appears when items are selected.
 * 
 * Features:
 * - Shows selected count
 * - Clear selection button
 * - Action buttons
 * - Can be positioned at top, bottom, or floating
 * - Smooth animations
 * 
 * Example:
 * ```tsx
 * <BulkActions
 *   selectedCount={selectedItems.length}
 *   onClearSelection={() => setSelectedItems([])}
 *   position="floating"
 * >
 *   <button className="btn btn-secondary" onClick={handleExport}>
 *     <Icon name="download" size={16} />
 *     Export
 *   </button>
 *   <button className="btn btn-danger" onClick={handleDelete}>
 *     <Icon name="trash" size={16} />
 *     Delete
 *   </button>
 * </BulkActions>
 * ```
 */
export function BulkActions({
    selectedCount,
    children,
    onClearSelection,
    position = 'floating',
    className,
}: BulkActionsProps) {
    if (selectedCount === 0) {
        return null;
    }

    const positionClasses = {
        top: 'sticky top-0 z-10',
        bottom: 'sticky bottom-0 z-10',
        floating: 'fixed bottom-6 left-1/2 -translate-x-1/2 z-20 shadow-lg',
    };

    return (
        <div
            className={cn(
                'flex items-center gap-4 border border-[var(--color-border-light)] bg-white px-4 py-3 animate-in fade-in slide-in-from-bottom-2',
                positionClasses[position],
                position === 'floating' && 'rounded-lg',
                className,
            )}
            style={
                position !== 'floating'
                    ? { borderRadius: 'var(--shell-radius)' }
                    : undefined
            }
        >
            {/* Selection count */}
            <div className="flex items-center gap-2">
                <div
                    className="flex size-8 items-center justify-center bg-[var(--color-brand)] text-white font-semibold text-sm"
                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                >
                    {selectedCount}
                </div>
                <span className="text-sm font-medium text-[var(--color-text-main)]">
                    {selectedCount === 1 ? '1 item selected' : `${selectedCount} items selected`}
                </span>
            </div>

            {/* Divider */}
            <div className="h-6 w-px bg-[var(--color-border-light)]" />

            {/* Actions */}
            <div className="flex items-center gap-2">
                {children}
            </div>

            {/* Clear selection */}
            <button
                type="button"
                onClick={onClearSelection}
                className="ml-2 flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition-colors"
            >
                <Icon name="x" size={16} />
                <span>Clear</span>
            </button>
        </div>
    );
}

/**
 * Bulk action button for use inside BulkActions.
 */
export function BulkActionButton({
    icon,
    label,
    onClick,
    variant = 'secondary',
    disabled = false,
}: {
    icon: string;
    label: string;
    onClick: () => void;
    variant?: 'primary' | 'secondary' | 'danger';
    disabled?: boolean;
}) {
    const variantClasses = {
        primary: 'bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)] border-[var(--color-brand)]',
        secondary: 'bg-white text-[var(--color-text-body)] hover:bg-[var(--shell-hover)] border-[var(--color-border-light)]',
        danger: 'bg-[var(--color-danger)] text-white hover:bg-[var(--color-danger-hover)] border-[var(--color-danger)]',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'flex items-center gap-2 border px-3 py-1.5 text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
                variantClasses[variant],
            )}
            style={{ borderRadius: 'var(--shell-radius-sm)' }}
        >
            <Icon name={icon} size={16} />
            <span>{label}</span>
        </button>
    );
}

/**
 * Checkbox for selecting items in a table.
 */
export function SelectCheckbox({
    checked,
    onChange,
    indeterminate = false,
    label,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    indeterminate?: boolean;
    label?: string;
}) {
    return (
        <label className="group flex cursor-pointer items-center gap-2">
            {/*
              The box centres on the words, rather than sitting a pixel or two
              above them.

              `items-center` on this label was already doing its part; the
              wrapper inside it was not. A block div containing an <input>
              takes the height of a line box, and the input aligns to that
              line's baseline — so the div centred correctly and the box inside
              it hung high. A flex wrapper has no baseline to hang from.
            */}
            <span className="relative flex items-center">
                <input
                    type="checkbox"
                    checked={checked}
                    ref={(el) => {
                        if (el) {
                            el.indeterminate = indeterminate;
                        }
                    }}
                    onChange={(e) => onChange(e.target.checked)}
                    className="block size-4 cursor-pointer border-2 border-[var(--color-border-strong)] text-[var(--color-brand)] focus:ring-2 focus:ring-[var(--color-brand)] focus:ring-offset-1"
                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                />
            </span>
            {label && (
                <span className="text-sm text-[var(--color-text-body)] group-hover:text-[var(--color-text-main)]">
                    {label}
                </span>
            )}
        </label>
    );
}
