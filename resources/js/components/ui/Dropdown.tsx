import { useEffect, useRef, useState, type ReactNode } from 'react';

import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { cn } from '@/lib/utils';

type DropdownProps = {
    /** Trigger element (button, etc.) */
    trigger: ReactNode;
    /** Dropdown content */
    children: ReactNode;
    /** Alignment relative to trigger */
    align?: 'start' | 'end' | 'center';
    /** Side to open on */
    side?: 'top' | 'bottom' | 'left' | 'right';
    /** Additional class for dropdown content */
    className?: string;
    /** Close on item click */
    closeOnClick?: boolean;
};

/**
 * Dropdown menu component.
 *
 * Features:
 * - Click to open/close
 * - Click outside to close
 * - ESC key to close
 * - Alignment options
 * - Side options
 * - Smooth animation
 * - Accessible
 *
 * @example
 * ```tsx
 * <Dropdown
 *   trigger={<Button>Actions</Button>}
 *   align="end"
 * >
 *   <DropdownItem onClick={() => handleEdit()}>
 *     Edit
 *   </DropdownItem>
 *   <DropdownItem onClick={() => handleDelete()}>
 *     Delete
 *   </DropdownItem>
 * </Dropdown>
 * ```
 */
export function Dropdown({
    trigger,
    children,
    align = 'start',
    side = 'bottom',
    className,
    closeOnClick = true,
}: DropdownProps) {
    const [isOpen, setIsOpen] = useState(false);
    const dropdownRef = useRef<HTMLDivElement>(null);

    // Close on click outside
    useEffect(() => {
        if (!isOpen) return;

        const handleClickOutside = (event: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen]);

    // Close on ESC key
    useEffect(() => {
        if (!isOpen) return;

        const handleEsc = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('keydown', handleEsc);
        return () => document.removeEventListener('keydown', handleEsc);
    }, [isOpen]);

    const handleTriggerClick = () => {
        setIsOpen(!isOpen);
    };

    const handleContentClick = () => {
        if (closeOnClick) {
            setIsOpen(false);
        }
    };

    return (
        <div ref={dropdownRef} className="relative inline-block">
            {/* Trigger */}
            <div onClick={handleTriggerClick}>
                {trigger}
            </div>

            {/* Dropdown content */}
            {isOpen && <FlyoutBackdrop onClose={() => setIsOpen(false)} dismissOnOutsidePress={false} />}

            {isOpen && (
                <div
                    onClick={handleContentClick}
                    className={cn(
                        'absolute z-50 mt-1 rounded-lg border border-[var(--color-border-light)]',
                        'bg-[var(--color-card-bg)] shadow-lg',
                        'animate-in fade-in-0 zoom-in-95 duration-100',
                        'min-w-[12rem]',
                        // Alignment
                        side === 'bottom' && 'top-full',
                        side === 'top' && 'bottom-full mb-1',
                        side === 'left' && 'right-full mr-1',
                        side === 'right' && 'left-full ml-1',
                        // Horizontal alignment
                        align === 'start' && 'left-0',
                        align === 'end' && 'right-0',
                        align === 'center' && 'left-1/2 -translate-x-1/2',
                        className,
                    )}
                    role="menu"
                    aria-orientation="vertical"
                >
                    {children}
                </div>
            )}
        </div>
    );
}

type DropdownItemProps = {
    /** Item content */
    children: ReactNode;
    /** Click handler */
    onClick?: () => void;
    /** Whether item is disabled */
    disabled?: boolean;
    /** Visual variant */
    variant?: 'default' | 'danger';
    /** Icon (ReactNode) */
    icon?: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Dropdown menu item.
 *
 * @example
 * ```tsx
 * <DropdownItem onClick={handleEdit} icon={<Icon name="pencil" />}>
 *   Edit
 * </DropdownItem>
 * ```
 */
export function DropdownItem({
    children,
    onClick,
    disabled = false,
    variant = 'default',
    icon,
    className,
}: DropdownItemProps) {
    const handleClick = () => {
        if (!disabled && onClick) {
            onClick();
        }
    };

    return (
        <button
            type="button"
            onClick={handleClick}
            disabled={disabled}
            role="menuitem"
            className={cn(
                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                'transition-colors duration-150',
                'first:rounded-t-lg last:rounded-b-lg',
                // Default variant
                variant === 'default' &&
                    !disabled &&
                    'text-[var(--color-text-main)] hover:bg-[var(--color-brand-subtle)]',
                // Danger variant
                variant === 'danger' && !disabled && 'text-red-600 hover:bg-red-50',
                // Disabled state
                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            {icon && <span className="flex-none">{icon}</span>}
            <span className="flex-1">{children}</span>
        </button>
    );
}

/**
 * Dropdown separator.
 */
export function DropdownSeparator() {
    return <div className="my-1 h-px bg-[var(--color-border-light)]" role="separator" />;
}

/**
 * Dropdown label/header.
 *
 * @example
 * ```tsx
 * <DropdownLabel>Actions</DropdownLabel>
 * ```
 */
export function DropdownLabel({ children }: { children: ReactNode }) {
    return (
        <div className="px-3 py-2 text-xs font-semibold text-[var(--color-text-muted)]">
            {children}
        </div>
    );
}

