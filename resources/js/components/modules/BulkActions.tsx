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
                /*
                  The same card as everything else, at the same height as the
                  toolbar it mirrors.

                  It was `bg-white` -- which is a colour, not a surface, so in
                  dark mode the one bar carrying destructive actions was the one
                  thing on the screen still painted for daylight. It was also
                  `rounded-lg` floating and --shell-radius docked, so the same
                  component had two different corners depending on where it was
                  put.
                */
                'flex items-center gap-3 rounded-[var(--shell-radius)] border px-3 py-2 animate-in fade-in slide-in-from-bottom-2',
                positionClasses[position],
                className,
            )}
            style={{
                borderColor: 'var(--shell-border)',
                background: 'var(--color-card-bg)',
                boxShadow: position === 'floating' ? 'var(--shadow-lg)' : undefined,
            }}
        >
            {/*
              The count in a mark of its own.

              This bar arrives without being asked for, over the page somebody
              was reading, and it is the only thing on screen that acts on many
              records at once. A filled mark is what carries that from the
              corner of the eye — the words beside it are read second, and only
              once somebody has noticed the bar is there at all.

              Sized to its digits rather than fixed: "3" and "1,204" both fit,
              where a square holding a four-digit number is a square with the
              number spilling out of it.
            */}
            <span className="flex shrink-0 items-center gap-2">
                <span
                    className="flex h-7 min-w-7 items-center justify-center rounded-[var(--shell-radius-sm)] px-1.5 text-sm font-semibold tabular-nums"
                    style={{
                        background: 'var(--color-brand)',
                        color: 'var(--color-text-on-accent)',
                    }}
                >
                    {selectedCount}
                </span>
                <span className="whitespace-nowrap text-sm text-[var(--color-text-body)]">
                    selected
                </span>
            </span>

            <span
                className="h-5 w-px shrink-0"
                style={{ background: 'var(--shell-border)' }}
                aria-hidden="true"
            />

            <div className="flex flex-wrap items-center gap-2">{children}</div>

            {/*
              Last, and set apart.

              It was `ml-2` after the actions, which put a plain word at the end
              of a row of buttons where it read as a fifth action. `ml-auto`
              takes it to the far end instead, so the bar reads as "this many,
              do these things" with the way out where a way out goes.
            */}
            <button
                type="button"
                onClick={onClearSelection}
                title="Clear the selection"
                aria-label="Clear the selection"
                className="ml-auto flex size-7 shrink-0 items-center justify-center rounded-[var(--shell-radius-sm)] text-[var(--color-text-muted)] transition-colors hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
            >
                <Icon name="x" size={15} />
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
