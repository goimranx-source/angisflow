import { forwardRef, type InputHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type DatePickerProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> & {
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Error message */
    error?: string;
    /** Label for the input */
    label?: string;
    /** Helper text */
    helperText?: string;
};

/**
 * Date picker component (native input wrapper).
 *
 * Features:
 * - Native date input with consistent styling
 * - Calendar icon
 * - Size variants
 * - Error states
 * - Disabled states
 * - Min/max date support
 * - Accessible
 *
 * Note: This is a native date input wrapper. For a more advanced date picker
 * with custom UI, consider integrating a library like react-datepicker.
 *
 * @example
 * ```tsx
 * <DatePicker
 *   label="Start Date"
 *   value={startDate}
 *   onChange={(e) => setStartDate(e.target.value)}
 *   min="2024-01-01"
 *   max="2024-12-31"
 * />
 *
 * <DatePicker
 *   label="Birth Date"
 *   value={birthDate}
 *   onChange={(e) => setBirthDate(e.target.value)}
 *   error={errors.birthDate}
 *   required
 * />
 * ```
 */
export const DatePicker = forwardRef<HTMLInputElement, DatePickerProps>(function DatePicker(
    { size = 'md', error, label, helperText, className, disabled, required, ...props },
    ref,
) {
    const inputId = props.id || `date-${Math.random().toString(36).slice(2, 9)}`;

    return (
        <div className="w-full">
            {/* Label */}
            {label && (
                <label
                    htmlFor={inputId}
                    className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]"
                >
                    {label}
                    {required && <span className="ml-1 text-red-500">*</span>}
                </label>
            )}

            {/* Input wrapper */}
            <div className="relative">
                <input
                    ref={ref}
                    id={inputId}
                    type="date"
                    disabled={disabled}
                    required={required}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={
                        error ? `${inputId}-error` : helperText ? `${inputId}-helper` : undefined
                    }
                    className={cn(
                        'w-full rounded-lg border transition-all duration-150',
                        'bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
                        'placeholder:text-[var(--color-text-subtle)]',
                        // Sizes
                        size === 'sm' && 'px-2.5 py-1.5 pr-9 text-xs',
                        size === 'md' && 'px-3 py-2 pr-10 text-sm',
                        size === 'lg' && 'px-4 py-2.5 pr-11 text-base',
                        // States
                        error
                            ? 'border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-100'
                            : 'border-[var(--color-border-light)] focus:border-[var(--color-brand)] focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                        disabled &&
                            'cursor-not-allowed bg-[var(--color-border-light)] opacity-60',
                        'focus:outline-none',
                        className,
                    )}
                    {...props}
                />

                {/* Calendar icon */}
                <div
                    className={cn(
                        'pointer-events-none absolute top-1/2 -translate-y-1/2',
                        'text-[var(--color-text-subtle)]',
                        size === 'sm' && 'right-2.5',
                        size === 'md' && 'right-3',
                        size === 'lg' && 'right-4',
                    )}
                >
                    <Icon
                        name="calendar-blank"
                        size={size === 'sm' ? 14 : size === 'lg' ? 18 : 16}
                        weight="duotone"
                    />
                </div>
            </div>

            {/* Error message */}
            {error && (
                <p
                    id={`${inputId}-error`}
                    className="mt-1.5 text-xs text-red-600"
                    role="alert"
                >
                    {error}
                </p>
            )}

            {/* Helper text */}
            {!error && helperText && (
                <p
                    id={`${inputId}-helper`}
                    className="mt-1.5 text-xs text-[var(--color-text-muted)]"
                >
                    {helperText}
                </p>
            )}
        </div>
    );
});

type DateRangePickerProps = {
    /** Start date value (YYYY-MM-DD) */
    startDate?: string;
    /** End date value (YYYY-MM-DD) */
    endDate?: string;
    /** Callback when start date changes */
    onStartDateChange?: (date: string) => void;
    /** Callback when end date changes */
    onEndDateChange?: (date: string) => void;
    /** Label for start date */
    startLabel?: string;
    /** Label for end date */
    endLabel?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Error message for start date */
    startError?: string;
    /** Error message for end date */
    endError?: string;
    /** Minimum date */
    min?: string;
    /** Maximum date */
    max?: string;
    /** Whether dates are required */
    required?: boolean;
    /** Disabled state */
    disabled?: boolean;
    /** Additional class */
    className?: string;
};

/**
 * Date range picker component.
 *
 * @example
 * ```tsx
 * <DateRangePicker
 *   startDate={startDate}
 *   endDate={endDate}
 *   onStartDateChange={setStartDate}
 *   onEndDateChange={setEndDate}
 *   startLabel="From"
 *   endLabel="To"
 *   required
 * />
 * ```
 */
export function DateRangePicker({
    startDate,
    endDate,
    onStartDateChange,
    onEndDateChange,
    startLabel = 'Start Date',
    endLabel = 'End Date',
    size = 'md',
    startError,
    endError,
    min,
    max,
    required,
    disabled,
    className,
}: DateRangePickerProps) {
    return (
        <div className={cn('grid gap-4 sm:grid-cols-2', className)}>
            <DatePicker
                label={startLabel}
                value={startDate}
                onChange={(e) => onStartDateChange?.(e.target.value)}
                size={size}
                error={startError}
                min={min}
                max={endDate || max}
                required={required}
                disabled={disabled}
            />
            <DatePicker
                label={endLabel}
                value={endDate}
                onChange={(e) => onEndDateChange?.(e.target.value)}
                size={size}
                error={endError}
                min={startDate || min}
                max={max}
                required={required}
                disabled={disabled}
            />
        </div>
    );
}
