import { forwardRef, useState, type InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type MoneyInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange' | 'size'> & {
    /** Currency code (e.g., 'USD', 'EUR', 'GBP') */
    currency: string;
    /** Value in minor units (e.g., 1500 for $15.00) */
    value: number;
    /** Callback when value changes (receives minor units) */
    onChange: (minorUnits: number) => void;
    /** Error message */
    error?: string;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Full width */
    fullWidth?: boolean;
    /** Allow negative values */
    allowNegative?: boolean;
};

/**
 * Money input component with currency formatting.
 *
 * Integrates with Angisflow's Money value object (integer minor units).
 * Displays formatted currency but stores as integer minor units internally.
 *
 * Features:
 * - Automatic formatting (1,500.00)
 * - Currency symbol display
 * - Handles decimal input correctly
 * - Converts to/from minor units
 * - Prevents invalid input
 * - Size variants
 * - Error states
 *
 * @example
 * ```tsx
 * const [amount, setAmount] = useState(150000); // $1,500.00
 *
 * <MoneyInput
 *   currency="USD"
 *   value={amount}
 *   onChange={setAmount}
 *   error={errors.amount}
 * />
 * ```
 */
export const MoneyInput = forwardRef<HTMLInputElement, MoneyInputProps>(function MoneyInput(
    {
        className,
        currency,
        value,
        onChange,
        error,
        size = 'md',
        fullWidth = false,
        allowNegative = false,
        disabled,
        ...props
    },
    ref,
) {
    // Display value (formatted decimal string)
    const [displayValue, setDisplayValue] = useState(() => formatMinorToDecimal(value));
    const [isFocused, setIsFocused] = useState(false);

    const currencySymbol = getCurrencySymbol(currency);

    const handleFocus = (e: React.FocusEvent<HTMLInputElement>) => {
        setIsFocused(true);
        // Select all on focus for easy replacement
        e.target.select();
        props.onFocus?.(e);
    };

    const handleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
        setIsFocused(false);
        
        // Re-format on blur
        const minorUnits = parseDecimalToMinor(displayValue);
        const formatted = formatMinorToDecimal(minorUnits);
        setDisplayValue(formatted);
        
        props.onBlur?.(e);
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        let inputValue = e.target.value;

        // Remove currency symbol and spaces
        inputValue = inputValue.replace(/[^\d.,-]/g, '');

        // Handle negative sign
        const isNegative = inputValue.startsWith('-');
        if (isNegative && !allowNegative) {
            return;
        }

        // Remove extra decimals (only keep first decimal point)
        const parts = inputValue.split('.');
        if (parts.length > 2) {
            inputValue = parts[0] + '.' + parts.slice(1).join('');
        }

        // Limit to 2 decimal places
        if (parts[1] && parts[1].length > 2) {
            inputValue = parts[0] + '.' + parts[1].substring(0, 2);
        }

        setDisplayValue(inputValue);

        // Convert to minor units and call onChange
        const minorUnits = parseDecimalToMinor(inputValue);
        onChange(minorUnits);
    };

    return (
        <div className={cn(fullWidth && 'w-full')}>
            <div className="relative">
                {/* Currency symbol */}
                <div
                    className={cn(
                        'pointer-events-none absolute left-0 top-0 flex h-full items-center font-medium text-[var(--color-text-muted)]',
                        size === 'sm' && 'pl-2.5 text-xs',
                        size === 'md' && 'pl-3 text-sm',
                        size === 'lg' && 'pl-4 text-base',
                    )}
                >
                    {currencySymbol}
                </div>

                {/* Input */}
                <input
                    ref={ref}
                    type="text"
                    inputMode="decimal"
                    value={isFocused ? displayValue : formatWithCommas(displayValue)}
                    onChange={handleChange}
                    onFocus={handleFocus}
                    onBlur={handleBlur}
                    disabled={disabled}
                    aria-invalid={error ? 'true' : undefined}
                    className={cn(
                        'rounded-lg border bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
                        'transition-all duration-150',
                        'font-mono',
                        'focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                        // Size variants (extra padding for currency symbol)
                        size === 'sm' && 'py-1.5 pl-7 pr-3 text-xs',
                        size === 'md' && 'py-2 pl-9 pr-3 text-sm',
                        size === 'lg' && 'py-2.5 pl-11 pr-4 text-base',
                        // Error state
                        error
                            ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                            : 'border-[var(--color-border-light)]',
                        // Disabled state
                        disabled && 'cursor-not-allowed bg-[var(--color-brand-subtle)] opacity-60',
                        // Full width
                        fullWidth && 'w-full',
                        // Right-align for better number reading
                        'text-right',
                        className,
                    )}
                    {...props}
                />
            </div>

            {error && (
                <p className="mt-1 text-xs font-medium text-red-600" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
});

/**
 * Convert minor units (integer) to decimal string.
 * Example: 150000 → "1500.00"
 */
function formatMinorToDecimal(minorUnits: number): string {
    const decimal = minorUnits / 100;
    return decimal.toFixed(2);
}

/**
 * Parse decimal string to minor units (integer).
 * Example: "1500.00" → 150000
 */
function parseDecimalToMinor(value: string): number {
    // Remove commas and parse
    const cleaned = value.replace(/,/g, '');
    const parsed = parseFloat(cleaned);
    
    if (isNaN(parsed)) {
        return 0;
    }
    
    // Convert to minor units (multiply by 100 and round)
    return Math.round(parsed * 100);
}

/**
 * Add thousand separators to decimal string.
 * Example: "1500.00" → "1,500.00"
 */
function formatWithCommas(value: string): string {
    const parts = value.split('.');
    const integerPart = parts[0];
    const decimalPart = parts[1];
    
    // Add commas to integer part
    const withCommas = integerPart?.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    
    return decimalPart !== undefined ? `${withCommas}.${decimalPart}` : withCommas || '0';
}

/**
 * Get currency symbol from currency code.
 * Expand this mapping as needed.
 */
function getCurrencySymbol(currency: string): string {
    const symbols: Record<string, string> = {
        USD: '$',
        EUR: '€',
        GBP: '£',
        JPY: '¥',
        CNY: '¥',
        INR: '₹',
        AUD: 'A$',
        CAD: 'C$',
        CHF: 'CHF',
        BDT: '৳',
        PKR: '₨',
        LKR: '₨',
        AED: 'د.إ',
        SAR: '﷼',
    };

    return symbols[currency.toUpperCase()] || currency;
}

