import { useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { cn } from '@/lib/utils';

type ConfirmVariant = 'default' | 'danger' | 'warning';

type ConfirmProps = {
    /** Whether confirm dialog is open */
    open: boolean;
    /** Callback when dialog should close */
    onClose: () => void;
    /** Callback when confirmed */
    onConfirm: () => void | Promise<void>;
    /** Title */
    title: string;
    /** Description/message */
    description: string;
    /** Confirm button text */
    confirmText?: string;
    /** Cancel button text */
    cancelText?: string;
    /** Visual variant */
    variant?: ConfirmVariant;
    /** Show loading state during async confirm */
    loading?: boolean;
};

/**
 * Confirmation dialog component.
 *
 * A specialized Modal for confirmations (delete, submit, etc.).
 *
 * Features:
 * - Visual variants (default, danger, warning)
 * - Async confirm support
 * - Loading state
 * - Keyboard shortcuts (Enter to confirm, ESC to cancel)
 * - Accessible
 *
 * @example
 * ```tsx
 * const [isOpen, setIsOpen] = useState(false);
 *
 * <Confirm
 *   open={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   onConfirm={async () => {
 *     await deleteItem();
 *     setIsOpen(false);
 *   }}
 *   title="Delete item"
 *   description="This will permanently delete this item. This action cannot be undone."
 *   confirmText="Delete"
 *   variant="danger"
 * />
 * ```
 */
export function Confirm({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    variant = 'default',
    loading: externalLoading,
}: ConfirmProps) {
    const [internalLoading, setInternalLoading] = useState(false);
    const loading = externalLoading ?? internalLoading;

    const handleConfirm = async () => {
        const result = onConfirm();

        // Handle async confirmations
        if (result instanceof Promise) {
            setInternalLoading(true);
            try {
                await result;
            } finally {
                setInternalLoading(false);
            }
        }
    };

    // Keyboard shortcut - Enter to confirm
    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !loading) {
            e.preventDefault();
            void handleConfirm();
        }
    };

    const icons: Record<ConfirmVariant, string> = {
        default: 'info',
        danger: 'warning',
        warning: 'warning-circle',
    };

    const iconColors: Record<ConfirmVariant, string> = {
        default: 'text-blue-600',
        danger: 'text-red-600',
        warning: 'text-amber-600',
    };

    const iconBgColors: Record<ConfirmVariant, string> = {
        default: 'bg-blue-100',
        danger: 'bg-red-100',
        warning: 'bg-amber-100',
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="sm"
            closeOnBackdrop={!loading}
            showClose={!loading}
        >
            <div onKeyDown={handleKeyDown}>
                {/* Icon */}
                <div className="flex justify-center">
                    <div
                        className={cn('inline-flex rounded-full p-3', iconBgColors[variant])}
                    >
                        <Icon
                            name={icons[variant]}
                            size={28}
                            weight="fill"
                            className={iconColors[variant]}
                        />
                    </div>
                </div>

                {/* Title & Description */}
                <div className="mt-4 text-center">
                    <h3 className="text-lg font-semibold text-[var(--color-text-main)]">
                        {title}
                    </h3>
                    <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                        {description}
                    </p>
                </div>

                {/* Actions */}
                <div className="mt-6 flex gap-3">
                    <Button
                        variant="secondary"
                        onClick={onClose}
                        disabled={loading}
                        block
                    >
                        {cancelText}
                    </Button>
                    <Button
                        variant={variant === 'danger' ? 'danger' : 'primary'}
                        onClick={handleConfirm}
                        busy={loading}
                        disabled={loading}
                        block
                    >
                        {confirmText}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}

/**
 * Simplified confirm utility function.
 *
 * For quick confirmations without managing state manually.
 *
 * @example
 * ```tsx
 * if (await confirm('Delete item?', 'This cannot be undone.')) {
 *   await deleteItem();
 * }
 * ```
 */
export function confirm(
    title: string,
    description: string,
    _options?: {
        confirmText?: string;
        cancelText?: string;
        variant?: ConfirmVariant;
    },
): Promise<boolean> {
    return new Promise((resolve) => {
        // This would require a global confirm provider
        // For now, components should use the Confirm component directly
        // This is a placeholder for future implementation
        const result = window.confirm(`${title}\n\n${description}`);
        resolve(result);
    });
}

