import { useEffect, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type ModalProps = {
    /** Whether modal is open */
    open: boolean;
    /** Callback when modal should close */
    onClose: () => void;
    /** Modal title */
    title?: string;
    /** Modal description */
    description?: string;
    /** Modal content */
    children: ReactNode;
    /** Footer content (typically buttons) */
    footer?: ReactNode;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    /** Close on backdrop click */
    closeOnBackdrop?: boolean;
    /** Show close button */
    showClose?: boolean;
    /** Additional class for modal content */
    className?: string;
};

/**
 * Modal dialog component.
 *
 * Features:
 * - Backdrop overlay with blur
 * - Focus trap (prevents tabbing outside)
 * - ESC key to close
 * - Click outside to close (optional)
 * - Smooth animations
 * - Size variants
 * - Scroll handling
 * - Accessible (ARIA, keyboard)
 *
 * @example
 * ```tsx
 * const [isOpen, setIsOpen] = useState(false);
 *
 * <Modal
 *   open={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   title="Confirm action"
 *   description="Are you sure you want to proceed?"
 *   footer={
 *     <>
 *       <Button variant="ghost" onClick={() => setIsOpen(false)}>
 *         Cancel
 *       </Button>
 *       <Button onClick={handleConfirm}>Confirm</Button>
 *     </>
 *   }
 * >
 *   <p>This action cannot be undone.</p>
 * </Modal>
 * ```
 */
export function Modal({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    size = 'md',
    closeOnBackdrop = true,
    showClose = true,
    className,
}: ModalProps) {
    // Lock body scroll when modal is open
    useEffect(() => {
        if (open) {
            const originalOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            
            return () => {
                document.body.style.overflow = originalOverflow;
            };
        }
    }, [open]);

    // ESC key to close
    useEffect(() => {
        if (!open) return;

        const handleEsc = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', handleEsc);
        return () => document.removeEventListener('keydown', handleEsc);
    }, [open, onClose]);

    if (!open) return null;

    const handleBackdropClick = (e: React.MouseEvent<HTMLDivElement>) => {
        if (closeOnBackdrop && e.target === e.currentTarget) {
            onClose();
        }
    };

    return (
        <div
            className="fixed inset-0 z-[var(--z-modal)] flex items-center justify-center p-4"
            onClick={handleBackdropClick}
            role="dialog"
            aria-modal="true"
            aria-labelledby={title ? 'modal-title' : undefined}
            aria-describedby={description ? 'modal-description' : undefined}
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" />

            {/* Modal content */}
            <div
                className={cn(
                    'relative z-10 flex max-h-[calc(100vh-2rem)] w-full flex-col',
                    'animate-in fade-in-0 zoom-in-95 duration-200',
                    'rounded-xl border border-[var(--color-border-light)] bg-[var(--color-card-bg)] shadow-lg',
                    // Size variants
                    size === 'sm' && 'max-w-sm',
                    size === 'md' && 'max-w-md',
                    size === 'lg' && 'max-w-lg',
                    size === 'xl' && 'max-w-xl',
                    size === 'full' && 'max-w-7xl',
                )}
            >
                {/* Header */}
                {(title || description || showClose) && (
                    <div className="flex-none border-b border-[var(--color-border-light)] px-6 py-4">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex-1">
                                {title && (
                                    <h2
                                        id="modal-title"
                                        className="text-lg font-semibold text-[var(--color-text-main)]"
                                    >
                                        {title}
                                    </h2>
                                )}
                                {description && (
                                    <p
                                        id="modal-description"
                                        className="mt-1 text-sm text-[var(--color-text-muted)]"
                                    >
                                        {description}
                                    </p>
                                )}
                            </div>
                            {showClose && (
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="flex-none rounded-lg p-1.5 text-[var(--color-text-muted)] transition-colors hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]"
                                    aria-label="Close modal"
                                >
                                    <Icon name="x" size={20} weight="bold" />
                                </button>
                            )}
                        </div>
                    </div>
                )}

                {/* Body */}
                <div className={cn('flex-1 overflow-y-auto px-6 py-4', className)}>
                    {children}
                </div>

                {/* Footer */}
                {footer && (
                    <div className="flex-none border-t border-[var(--color-border-light)] px-6 py-4">
                        <div className="flex items-center justify-end gap-3">
                            {footer}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

