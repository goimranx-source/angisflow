import { forwardRef, type InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type RadioProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> & {
    /** Label text */
    label?: string;
    /** Description text below label */
    description?: string;
    /** Error message */
    error?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
};

/**
 * Radio button input component.
 *
 * Features:
 * - Optional label and description
 * - Size variants
 * - Error state
 * - Disabled state
 * - Custom styling with brand colors
 * - Accessible
 *
 * @example
 * ```tsx
 * <div>
 *   <Radio
 *     name="plan"
 *     value="starter"
 *     label="Starter"
 *     description="For small teams"
 *     checked={plan === 'starter'}
 *     onChange={(e) => setPlan(e.target.value)}
 *   />
 *   <Radio
 *     name="plan"
 *     value="pro"
 *     label="Professional"
 *     description="For growing businesses"
 *     checked={plan === 'pro'}
 *     onChange={(e) => setPlan(e.target.value)}
 *   />
 * </div>
 * ```
 */
export const Radio = forwardRef<HTMLInputElement, RadioProps>(function Radio(
    { className, label, description, error, size = 'md', disabled, ...props },
    ref,
) {
    const radioId = props.id || `radio-${Math.random().toString(36).substr(2, 9)}`;

    return (
        <div className="flex items-start gap-3">
            {/* Radio */}
            <div className="flex h-5 items-center">
                <input
                    ref={ref}
                    id={radioId}
                    type="radio"
                    disabled={disabled}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={description ? `${radioId}-desc` : undefined}
                    className={cn(
                        'rounded-full border bg-[var(--color-card-bg)]',
                        'transition-all duration-150',
                        'focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)] focus:ring-offset-1',
                        // Size variants
                        size === 'sm' && 'h-3.5 w-3.5',
                        size === 'md' && 'h-4 w-4',
                        size === 'lg' && 'h-5 w-5',
                        // Error state
                        error
                            ? 'border-red-300 focus:ring-red-100'
                            : 'border-[var(--color-border-light)]',
                        // Checked state
                        'checked:border-[var(--color-brand)] checked:bg-[var(--color-brand)]',
                        'checked:hover:border-[var(--color-brand-hover)] checked:hover:bg-[var(--color-brand-hover)]',
                        // Inner dot (via background-image)
                        'checked:bg-[radial-gradient(circle,white_35%,transparent_40%)]',
                        // Disabled state
                        disabled && 'cursor-not-allowed opacity-50',
                        // Default cursor
                        !disabled && 'cursor-pointer',
                        className,
                    )}
                    {...props}
                />
            </div>

            {/* Label & Description */}
            {(label || description) && (
                <div className="flex-1">
                    {label && (
                        <label
                            htmlFor={radioId}
                            className={cn(
                                'block font-medium text-[var(--color-text-main)]',
                                size === 'sm' && 'text-xs',
                                size === 'md' && 'text-sm',
                                size === 'lg' && 'text-base',
                                !disabled && 'cursor-pointer',
                                disabled && 'cursor-not-allowed opacity-50',
                            )}
                        >
                            {label}
                        </label>
                    )}
                    {description && (
                        <p
                            id={`${radioId}-desc`}
                            className={cn(
                                'mt-0.5 text-[var(--color-text-muted)]',
                                size === 'sm' && 'text-[0.6875rem]',
                                size === 'md' && 'text-xs',
                                size === 'lg' && 'text-sm',
                            )}
                        >
                            {description}
                        </p>
                    )}
                    {error && (
                        <p className="mt-1 text-xs font-medium text-red-600" role="alert">
                            {error}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
});

