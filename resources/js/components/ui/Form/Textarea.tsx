import { forwardRef, type TextareaHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & {
    /** Error message to display below textarea */
    error?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Full width */
    fullWidth?: boolean;
    /** Auto-resize to fit content */
    autoResize?: boolean;
};

/**
 * Multi-line text input component.
 *
 * Features:
 * - Size variants (sm, md, lg)
 * - Error state styling
 * - Disabled state
 * - Full width option
 * - Optional auto-resize
 * - Focus states with brand color
 *
 * @example
 * ```tsx
 * <Textarea
 *   placeholder="Enter description..."
 *   value={description}
 *   onChange={(e) => setDescription(e.target.value)}
 *   rows={4}
 *   error={errors.description}
 * />
 * ```
 */
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
    { className, error, size = 'md', fullWidth = false, disabled, autoResize = false, ...props },
    ref,
) {
    return (
        <div className={cn(fullWidth && 'w-full')}>
            <textarea
                ref={ref}
                disabled={disabled}
                aria-invalid={error ? 'true' : undefined}
                className={cn(
                    'rounded-lg border bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
                    'transition-all duration-150',
                    'placeholder:text-[var(--color-text-subtle)]',
                    'focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                    // Size variants
                    size === 'sm' && 'px-2.5 py-1.5 text-xs',
                    size === 'md' && 'px-3 py-2 text-sm',
                    size === 'lg' && 'px-4 py-2.5 text-base',
                    // Error state
                    error
                        ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                        : 'border-[var(--color-border-light)]',
                    // Disabled state
                    disabled && 'cursor-not-allowed bg-[var(--color-brand-subtle)] opacity-60',
                    // Full width
                    fullWidth && 'w-full',
                    // Auto-resize
                    autoResize && 'resize-none overflow-hidden',
                    // Default resize behavior
                    !autoResize && 'resize-y',
                    className,
                )}
                {...props}
            />
            {error && (
                <p className="mt-1 text-xs font-medium text-red-600" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
});

