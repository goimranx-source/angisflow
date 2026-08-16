import { createContext, useContext, useState, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type TabsContextType = {
    activeTab: string;
    setActiveTab: (value: string) => void;
};

const TabsContext = createContext<TabsContextType | null>(null);

function useTabsContext() {
    const context = useContext(TabsContext);
    if (!context) {
        throw new Error('Tabs components must be used within <Tabs>');
    }
    return context;
}

type TabsProps = {
    /** Default active tab value */
    defaultValue: string;
    /** Controlled active value */
    value?: string;
    /** Callback when tab changes */
    onValueChange?: (value: string) => void;
    /** Tab content */
    children: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Tabs container component.
 *
 * Provides context for tab list and tab panels.
 * Supports both controlled and uncontrolled modes.
 *
 * @example
 * ```tsx
 * <Tabs defaultValue="general">
 *   <TabsList>
 *     <TabsTrigger value="general">General</TabsTrigger>
 *     <TabsTrigger value="security">Security</TabsTrigger>
 *     <TabsTrigger value="billing">Billing</TabsTrigger>
 *   </TabsList>
 *   
 *   <TabsContent value="general">
 *     <p>General settings content</p>
 *   </TabsContent>
 *   <TabsContent value="security">
 *     <p>Security settings content</p>
 *   </TabsContent>
 *   <TabsContent value="billing">
 *     <p>Billing settings content</p>
 *   </TabsContent>
 * </Tabs>
 * ```
 */
export function Tabs({
    defaultValue,
    value: controlledValue,
    onValueChange,
    children,
    className,
}: TabsProps) {
    const [internalValue, setInternalValue] = useState(defaultValue);

    const activeTab = controlledValue !== undefined ? controlledValue : internalValue;

    const setActiveTab = (newValue: string) => {
        if (controlledValue === undefined) {
            setInternalValue(newValue);
        }
        onValueChange?.(newValue);
    };

    return (
        <TabsContext.Provider value={{ activeTab, setActiveTab }}>
            <div className={className}>{children}</div>
        </TabsContext.Provider>
    );
}

type TabsListProps = {
    /** Tab triggers */
    children: ReactNode;
    /** Additional class */
    className?: string;
    /** Variant */
    variant?: 'default' | 'pills';
};

/**
 * Tab list component (container for triggers).
 */
export function TabsList({ children, className, variant = 'default' }: TabsListProps) {
    return (
        <div
            role="tablist"
            className={cn(
                'flex items-center',
                variant === 'default' && 'gap-6 border-b border-[var(--color-border-light)]',
                variant === 'pills' && 'gap-2 rounded-lg bg-[var(--color-brand-subtle)] p-1',
                className,
            )}
        >
            {children}
        </div>
    );
}

type TabsTriggerProps = {
    /** Value that identifies this tab */
    value: string;
    /** Tab label */
    children: ReactNode;
    /** Whether tab is disabled */
    disabled?: boolean;
    /** Additional class */
    className?: string;
    /** Badge (e.g., count) */
    badge?: string | number;
};

/**
 * Tab trigger (button to switch tabs).
 */
export function TabsTrigger({ value, children, disabled = false, className, badge }: TabsTriggerProps) {
    const { activeTab, setActiveTab } = useTabsContext();
    const isActive = activeTab === value;

    // Determine variant from parent
    const variant = className?.includes('rounded') ? 'pills' : 'default';

    return (
        <button
            type="button"
            role="tab"
            aria-selected={isActive}
            aria-controls={`tabpanel-${value}`}
            onClick={() => setActiveTab(value)}
            disabled={disabled}
            className={cn(
                'relative flex items-center gap-2 px-1 py-2 text-sm font-medium transition-colors duration-150',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand-subtle)] focus-visible:ring-offset-2',
                // Default variant
                variant === 'default' && [
                    isActive
                        ? 'text-[var(--color-brand)] after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:bg-[var(--color-brand)]'
                        : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                ],
                // Pills variant
                variant === 'pills' && [
                    'rounded-md px-3',
                    isActive
                        ? 'bg-[var(--color-card-bg)] text-[var(--color-text-main)] shadow-sm'
                        : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                ],
                // Disabled
                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            <span>{children}</span>
            {badge !== undefined && (
                <span
                    className={cn(
                        'rounded-full px-1.5 py-0.5 text-xs font-semibold',
                        isActive
                            ? 'bg-[var(--color-brand-subtle)] text-[var(--color-brand)]'
                            : 'bg-[var(--color-border-light)] text-[var(--color-text-muted)]',
                    )}
                >
                    {badge}
                </span>
            )}
        </button>
    );
}

type TabsContentProps = {
    /** Value that identifies this tab panel */
    value: string;
    /** Panel content */
    children: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Tab content panel.
 */
export function TabsContent({ value, children, className }: TabsContentProps) {
    const { activeTab } = useTabsContext();
    const isActive = activeTab === value;

    if (!isActive) {
        return null;
    }

    return (
        <div
            role="tabpanel"
            id={`tabpanel-${value}`}
            aria-labelledby={`tab-${value}`}
            className={cn('mt-4', className)}
        >
            {children}
        </div>
    );
}

