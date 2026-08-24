import { type FormEvent, type ReactNode, useEffect, useRef } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type QuickCreateModalProps = {
    /** Whether modal is open */
    open: boolean;
    /** Close handler */
    onClose: () => void;
    /** Modal title */
    title: string;
    /** Modal content */
    children: ReactNode;
    /** Submit handler */
    onSubmit: () => void;
    /** Submit button label */
    submitLabel?: string;
    /** Whether submission is in progress */
    submitting?: boolean;
    /** Size */
    size?: 'sm' | 'md' | 'lg';
    /** Additional actions in footer */
    footerActions?: ReactNode;
};

/**
 * Quick create modal for adding new items.
 * 
 * Features:
 * - Centered modal overlay
 * - Form wrapper with submit
 * - Close on backdrop click
 * - Close on Escape key
 * - Focus trap
 * - Scroll lock
 * - Loading state
 * 
 * Example:
 * ```tsx
 * <QuickCreateModal
 *   open={showModal}
 *   onClose={() => setShowModal(false)}
 *   title="Add Customer"
 *   onSubmit={handleSubmit}
 *   submitLabel="Create Customer"
 *   submitting={isSubmitting}
 * >
 *   <Field label="Name" required>
 *     <input type="text" value={name} onChange={(e) => setName(e.target.value)} />
 *   </Field>
 *   <Field label="Email" required>
 *     <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
 *   </Field>
 * </QuickCreateModal>
 * ```
 */
export function QuickCreateModal({
    open,
    onClose,
    title,
    children,
    onSubmit,
    submitLabel = 'Create',
    submitting = false,
    size = 'md',
    footerActions,
}: QuickCreateModalProps) {
    const modalRef = useRef<HTMLDivElement>(null);

    // Lock body scroll when modal is open
    useEffect(() => {
        if (open) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        return () => {
            document.body.style.overflow = '';
        };
    }, [open]);

    // Close on Escape key
    useEffect(() => {
        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && open) {
                onClose();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [open, onClose]);

    // Focus first input when opened
    useEffect(() => {
        if (open && modalRef.current) {
            const firstInput = modalRef.current.querySelector<HTMLInputElement>(
                'input, select, textarea',
            );
            firstInput?.focus();
        }
    }, [open]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit();
    };

    if (!open) {
        return null;
    }

    const sizeClasses = {
        sm: 'max-w-md',
        md: 'max-w-xl',
        lg: 'max-w-3xl',
    };

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-50 bg-black/40 transition-opacity animate-in fade-in"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Modal */}
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div
                    ref={modalRef}
                    className={cn(
                        'w-full bg-white shadow-2xl animate-in fade-in zoom-in-95',
                        sizeClasses[size],
                    )}
                    style={{ borderRadius: 'var(--shell-radius)' }}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="modal-title"
                    onClick={(e) => e.stopPropagation()}
                >
                    <form onSubmit={handleSubmit}>
                        {/* Header */}
                        <div className="flex items-center justify-between border-b border-[var(--color-border-light)] px-6 py-4">
                            <h2
                                id="modal-title"
                                className="text-lg font-semibold text-[var(--color-text-main)]"
                            >
                                {title}
                            </h2>
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                                aria-label="Close"
                            >
                                <Icon name="x" size={20} />
                            </button>
                        </div>

                        {/* Content */}
                        <div className="max-h-[70vh] overflow-y-auto px-6 py-5 space-y-4">
                            {children}
                        </div>

                        {/* Footer */}
                        <div className="flex items-center justify-end gap-3 border-t border-[var(--color-border-light)] px-6 py-4">
                            {footerActions}
                            <button
                                type="button"
                                onClick={onClose}
                                className="btn btn-secondary"
                                disabled={submitting}
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={submitting}
                            >
                                {submitting ? (
                                    <>
                                        <Icon name="circle-notch" size={16} className="animate-spin" />
                                        <span>Creating...</span>
                                    </>
                                ) : (
                                    submitLabel
                                )}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}

/**
 * Quick action button that opens a popover or modal.
 */
export function QuickActionButton({
    icon,
    label,
    onClick,
    variant = 'primary',
}: {
    icon: string;
    label: string;
    onClick: () => void;
    variant?: 'primary' | 'secondary';
}) {
    const variantClasses = {
        primary: 'bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)]',
        secondary: 'border border-[var(--color-border-light)] bg-white text-[var(--color-text-body)] hover:bg-[var(--shell-hover)]',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                /*
                  A height, not vertical padding.

                  This sat beside two .btn secondaries in every page header and
                  measured 36px against their 32 — padding plus a 14px line box
                  happens to land somewhere, and where it lands is not where a
                  button with a stated height lands. Matching .btn exactly is
                  the point: they are three buttons on one row.
                */
                'flex h-8 items-center gap-2 px-4 text-sm font-semibold transition-colors',
                variantClasses[variant],
            )}
            style={{ borderRadius: 'var(--shell-radius)' }}
        >
            <Icon name={icon} size={16} weight="bold" />
            <span>{label}</span>
        </button>
    );
}
