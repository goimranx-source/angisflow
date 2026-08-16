import { forwardRef, type InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type SwitchProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> & {
    /** Label text */
    label?: string;
    /** Description text below label */
    description?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
};

/**
 * Toggle switch component.
 *
 * A more visual alternative to Checkbox for boolean settings.
 *
 * Features:
 * - Optional label and description
 * - Size variants
 * - Smooth animation
 * - Disabled state
 * - Brand colors
 * - Accessible
 *
 * @example
 * ```tsx
 * <Switch
 *   label="Enable notifications"
 *   description="Receive email alerts for important updates"
 *   checked={notificationsEnabled}
 *   onChange={(e) => setNotificationsEnabled(e.target.checked)}
 * />
 * ```
 */
export const Switch = forwardRef<HTMLInputElement, SwitchProps>(function Switch(
    { className, label, description, size = 'md', disabled, ...props },
    ref,
) {
    const switchId = props.id || `switch-${Math.random().toString(36).substr(2, 9)}`;

    // Size configurations
    const sizes = {
        sm: {
            track: 'h-5 w-9',
            thumb: 'h-4 w-4',
            translate: 'translate-x-4',
        },
        md: {
            track: 'h-6 w-11',
            thumb: 'h-5 w-5',
            translate: 'translate-x-5',
        },
        lg: {
            track: 'h-7 w-14',
            thumb: 'h-6 w-6',
            translate: 'translate-x-7',
        },
    };

    const config = sizes[size];

    return (
        <div className="flex items-start gap-3">
            {/* Switch */}
            <div className="relative">
                <input
                    ref={ref}
                    id={switchId}
                    type="checkbox"
                    disabled={disabled}
                    aria-describedby={description ? `${switchId}-desc` : undefined}
                    className="peer sr-only"
                    {...props}
                />
                <label
                    htmlFor={switchId}
                    className={cn(
                        'flex items-center rounded-full',
                        'transition-all duration-200',
                        'bg-[var(--color-border-strong)]',
                        'peer-checked:bg-[var(--color-brand)]',
                        'peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--color-brand-subtle)] peer-focus-visible:ring-offset-2',
                        disabled && 'cursor-not-allowed opacity-50',
                        !disabled && 'cursor-pointer',
                        config.track,
                        className,
                    )}
                >
                    <span
                        className={cn(
                            'block rounded-full bg-white shadow-sm',
                            'transition-transform duration-200',
                            'ml-0.5',
                            'peer-checked:' + config.translate,
                            config.thumb,
                        )}
                    />
                </label>
            </div>

            {/* Label & Description */}
            {(label || description) && (
                <div className="flex-1">
                    {label && (
                        <label
                            htmlFor={switchId}
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
                            id={`${switchId}-desc`}
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
                </div>
            )}
        </div>
    );
});

