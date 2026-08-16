import { forwardRef, useEffect, useState, type InputHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type SearchInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> & {
    /** Callback when search value changes (debounced) */
    onSearch?: (value: string) => void;
    /** Debounce delay in milliseconds */
    debounceMs?: number;
    /** Size variant */
    size?: 'sm' | 'md' | 'lg';
    /** Full width */
    fullWidth?: boolean;
    /** Show loading indicator */
    loading?: boolean;
};

/**
 * Search input with debounce and clear button.
 *
 * Features:
 * - Debounced search callback (default 300ms)
 * - Clear button (X) when input has value
 * - Loading indicator
 * - Size variants
 * - Search icon
 * - Full width option
 *
 * @example
 * ```tsx
 * <SearchInput
 *   placeholder="Search products..."
 *   onSearch={(value) => setSearchQuery(value)}
 *   debounceMs={500}
 *   loading={isSearching}
 * />
 * ```
 */
export const SearchInput = forwardRef<HTMLInputElement, SearchInputProps>(function SearchInput(
    {
        className,
        onSearch,
        debounceMs = 300,
        size = 'md',
        fullWidth = false,
        loading = false,
        value: controlledValue,
        onChange,
        ...props
    },
    ref,
) {
    const [internalValue, setInternalValue] = useState<string>('');
    
    // Use controlled value if provided, otherwise use internal state
    const value = controlledValue !== undefined ? String(controlledValue) : internalValue;
    const hasValue = value.length > 0;

    // Debounced search effect
    useEffect(() => {
        if (!onSearch) return;

        const timer = setTimeout(() => {
            onSearch(value);
        }, debounceMs);

        return () => clearTimeout(timer);
    }, [value, debounceMs, onSearch]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const newValue = e.target.value;
        
        // Update internal state if uncontrolled
        if (controlledValue === undefined) {
            setInternalValue(newValue);
        }
        
        // Call onChange if provided
        onChange?.(e);
    };

    const handleClear = () => {
        const newValue = '';
        
        // Update internal state if uncontrolled
        if (controlledValue === undefined) {
            setInternalValue(newValue);
        }
        
        // Create synthetic event for onChange
        const syntheticEvent = {
            target: { value: newValue },
            currentTarget: { value: newValue },
        } as React.ChangeEvent<HTMLInputElement>;
        
        onChange?.(syntheticEvent);
        onSearch?.(newValue);
    };

    return (
        <div className={cn('relative', fullWidth && 'w-full')}>
            {/* Search icon */}
            <div
                className={cn(
                    'pointer-events-none absolute left-0 top-0 flex h-full items-center text-[var(--color-text-muted)]',
                    size === 'sm' && 'pl-2',
                    size === 'md' && 'pl-3',
                    size === 'lg' && 'pl-4',
                )}
            >
                <Icon name="magnifying-glass" size={size === 'sm' ? 14 : size === 'lg' ? 18 : 16} />
            </div>

            {/* Input */}
            <input
                ref={ref}
                type="search"
                value={value}
                onChange={handleChange}
                className={cn(
                    'rounded-lg border border-[var(--color-border-light)] bg-[var(--color-card-bg)] text-[var(--color-text-main)]',
                    'transition-all duration-150',
                    'placeholder:text-[var(--color-text-subtle)]',
                    'focus:border-[var(--color-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-subtle)]',
                    // Size variants (extra padding for icons)
                    size === 'sm' && 'py-1.5 pl-7 pr-8 text-xs',
                    size === 'md' && 'py-2 pl-9 pr-10 text-sm',
                    size === 'lg' && 'py-2.5 pl-11 pr-12 text-base',
                    // Full width
                    fullWidth && 'w-full',
                    className,
                )}
                {...props}
            />

            {/* Loading or Clear button */}
            <div
                className={cn(
                    'absolute right-0 top-0 flex h-full items-center',
                    size === 'sm' && 'pr-2',
                    size === 'md' && 'pr-2.5',
                    size === 'lg' && 'pr-3',
                )}
            >
                {loading ? (
                    <Icon
                        name="spinner"
                        size={size === 'sm' ? 14 : size === 'lg' ? 18 : 16}
                        className="animate-spin text-[var(--color-text-muted)]"
                    />
                ) : hasValue ? (
                    <button
                        type="button"
                        onClick={handleClear}
                        className={cn(
                            'rounded-md p-0.5 text-[var(--color-text-muted)] transition-colors',
                            'hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                        )}
                        aria-label="Clear search"
                    >
                        <Icon name="x" size={size === 'sm' ? 14 : size === 'lg' ? 18 : 16} weight="bold" />
                    </button>
                ) : null}
            </div>
        </div>
    );
});

