import { forwardRef, type SelectHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> & {
    /** Label text */
    label?: string;
    /** Helper text below select */
    helperText?: string;
    /** Error message to display below select */
    error?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Full width */
    fullWidth?: boolean;
    /** Placeholder option */
    placeholder?: string;
};

/**
 * Select dropdown component.
 *
 * Features:
 * - Size variants (sm, md, lg)
 * - Error state styling
 * - Disabled state
 * - Full width option
 * - Custom arrow icon
 * - Placeholder option
 * - Focus states with brand color
 *
 * @example
 * ```tsx
 * <Select
 *   value={country}
 *   onChange={(e) => setCountry(e.target.value)}
 *   placeholder="Select country..."
 *   error={errors.country}
 * >
 *   <option value="US">United States</option>
 *   <option value="CA">Canada</option>
 *   <option value="UK">United Kingdom</option>
 * </Select>
 * ```
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
    { className, label, helperText, error, size = 'md', fullWidth = false, disabled, placeholder, children, ...props },
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
            <div className={cn('relative', fullWidth && 'w-full')}>
                <select
                    ref={ref}
                    disabled={disabled}
                    aria-invalid={error ? 'true' : undefined}
                    style={{ borderRadius: 'var(--shell-radius)' }}
                    className={cn(
                        'appearance-none border bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
                        'transition-all duration-150',
                        'focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                        // Size variants (extra padding-right for icon)
                        size === 'sm' && 'px-2.5 py-1.5 pr-8 text-xs',
                        size === 'md' && 'px-3 py-2 pr-9 text-sm',
                        size === 'lg' && 'px-4 py-2.5 pr-10 text-base',
                        // Error state
                        error
                            ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                            : 'border-[var(--color-border-light)]',
                        // Disabled state
                        disabled && 'cursor-not-allowed bg-[var(--color-brand-subtle)] opacity-60',
                        // Full width
                        fullWidth && 'w-full',
                        // Placeholder styling
                        !props.value && 'text-[var(--color-text-subtle)]',
                        className,
                    )}
                    {...props}
                >
                    {placeholder && (
                        <option value="" disabled>
                            {placeholder}
                        </option>
                    )}
                    {children}
                </select>

                {/* Custom dropdown icon */}
                <div
                    className={cn(
                        'pointer-events-none absolute right-0 top-0 flex h-full items-center',
                        'text-[var(--color-text-muted)]',
                        size === 'sm' && 'pr-2',
                        size === 'md' && 'pr-2.5',
                        size === 'lg' && 'pr-3',
                    )}
                >
                    <Icon name="caret-down" size={size === 'sm' ? 14 : size === 'lg' ? 18 : 16} weight="bold" />
                </div>
            </div>

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

