import { forwardRef, type InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> & {
    /** Label text */
    label?: string;
    /** Helper text below input */
    helperText?: string;
    /** Error message to display below input */
    error?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Full width */
    fullWidth?: boolean;
};

/**
 * Basic text input component.
 *
 * Simpler than Field.tsx - no label, just the input itself.
 * Use this when building custom form layouts or when you need just the input.
 * Use Field.tsx when you need the full labeled input with error handling.
 *
 * Features:
 * - Size variants (sm, md, lg)
 * - Error state styling
 * - Disabled state
 * - Full width option
 * - Focus states with brand color
 *
 * @example
 * ```tsx
 * <Input
 *   placeholder="Enter email..."
 *   value={email}
 *   onChange={(e) => setEmail(e.target.value)}
 *   error={errors.email}
 * />
 * ```
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
    { className, label, helperText, error, size = 'md', fullWidth = true, disabled, ...props },
    ref,
) {
    return (
        <div className={cn('space-y-1.5', fullWidth && 'w-full')}>
            {label && (
                <label
                    htmlFor={props.id}
                    className="block text-sm font-medium text-[var(--color-text-main)]"
                >
                    {label} {props.required && <span className="text-red-600">*</span>}
                </label>
            )}
            <input
                ref={ref}
                disabled={disabled}
                aria-invalid={error ? 'true' : undefined}
                style={{ borderRadius: 'var(--shell-radius)' }}
                className={cn(
                    'w-full border bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
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
                    className,
                )}
                {...props}
            />
            {helperText && !error && (
                <p className="text-xs text-[var(--color-text-muted)]">{helperText}</p>
            )}
            {error && (
                <p className="text-xs font-medium text-red-600" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
});

