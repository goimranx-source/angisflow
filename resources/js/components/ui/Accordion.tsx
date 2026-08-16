import { createContext, useContext, useState, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type AccordionContextType = {
    openItems: Set<string>;
    toggleItem: (value: string) => void;
    allowMultiple: boolean;
};

const AccordionContext = createContext<AccordionContextType | null>(null);

function useAccordionContext() {
    const context = useContext(AccordionContext);
    if (!context) {
        throw new Error('Accordion components must be used within <Accordion>');
    }
    return context;
}

type AccordionProps = {
    /** Accordion items */
    children: ReactNode;
    /** Allow multiple items open at once */
    allowMultiple?: boolean;
    /** Default open items (values) */
    defaultValue?: string | string[];
    /** Controlled open items */
    value?: string | string[];
    /** Callback when items change */
    onValueChange?: (value: string | string[]) => void;
    /** Additional class */
    className?: string;
};

/**
 * Accordion container component.
 *
 * Features:
 * - Single or multiple items open
 * - Controlled and uncontrolled modes
 * - Smooth expand/collapse animation
 * - Keyboard navigation
 * - Accessible
 *
 * @example
 * ```tsx
 * <Accordion defaultValue="item-1">
 *   <AccordionItem value="item-1">
 *     <AccordionTrigger>What is Angisflow?</AccordionTrigger>
 *     <AccordionContent>
 *       Angisflow is an all-in-one business management platform.
 *     </AccordionContent>
 *   </AccordionItem>
 *   
 *   <AccordionItem value="item-2">
 *     <AccordionTrigger>How does pricing work?</AccordionTrigger>
 *     <AccordionContent>
 *       We offer flexible pricing based on your needs.
 *     </AccordionContent>
 *   </AccordionItem>
 * </Accordion>
 * ```
 *
 * @example Multiple items open
 * ```tsx
 * <Accordion allowMultiple defaultValue={["item-1", "item-2"]}>
 *   <AccordionItem value="item-1">
 *     <AccordionTrigger>Section 1</AccordionTrigger>
 *     <AccordionContent>Content 1</AccordionContent>
 *   </AccordionItem>
 *   <AccordionItem value="item-2">
 *     <AccordionTrigger>Section 2</AccordionTrigger>
 *     <AccordionContent>Content 2</AccordionContent>
 *   </AccordionItem>
 * </Accordion>
 * ```
 */
export function Accordion({
    children,
    allowMultiple = false,
    defaultValue,
    value: controlledValue,
    onValueChange,
    className,
}: AccordionProps) {
    // Normalize default value to Set
    const getInitialOpenItems = () => {
        if (defaultValue === undefined) return new Set<string>();
        if (Array.isArray(defaultValue)) return new Set(defaultValue);
        return new Set([defaultValue]);
    };

    const [internalOpenItems, setInternalOpenItems] = useState(getInitialOpenItems);

    // Use controlled value if provided, otherwise use internal state
    const openItems = (() => {
        if (controlledValue !== undefined) {
            if (Array.isArray(controlledValue)) return new Set(controlledValue);
            return new Set([controlledValue]);
        }
        return internalOpenItems;
    })();

    const toggleItem = (value: string) => {
        const newOpenItems = new Set(openItems);

        if (newOpenItems.has(value)) {
            newOpenItems.delete(value);
        } else {
            if (!allowMultiple) {
                newOpenItems.clear();
            }
            newOpenItems.add(value);
        }

        // Update internal state if uncontrolled
        if (controlledValue === undefined) {
            setInternalOpenItems(newOpenItems);
        }

        // Call onChange with proper format
        if (onValueChange) {
            if (allowMultiple) {
                onValueChange(Array.from(newOpenItems));
            } else {
                onValueChange(newOpenItems.size > 0 ? Array.from(newOpenItems)[0]! : '');
            }
        }
    };

    return (
        <AccordionContext.Provider value={{ openItems, toggleItem, allowMultiple }}>
            <div className={cn('space-y-2', className)}>{children}</div>
        </AccordionContext.Provider>
    );
}

type AccordionItemProps = {
    /** Unique value for this item */
    value: string;
    /** Item content (trigger + content) */
    children: ReactNode;
    /** Whether item is disabled */
    disabled?: boolean;
    /** Additional class */
    className?: string;
};

/**
 * Accordion item component.
 */
export function AccordionItem({ value, children, disabled = false, className }: AccordionItemProps) {
    const { openItems } = useAccordionContext();
    const isOpen = openItems.has(value);

    return (
        <div
            className={cn(
                'card overflow-hidden transition-all',
                isOpen && 'ring-1 ring-[var(--color-brand-subtle)]',
                disabled && 'opacity-50',
                className,
            )}
            data-state={isOpen ? 'open' : 'closed'}
            data-disabled={disabled || undefined}
        >
            {children}
        </div>
    );
}

type AccordionTriggerProps = {
    /** Trigger content */
    children: ReactNode;
    /** Additional class */
    className?: string;
    /** Icon position */
    iconPosition?: 'left' | 'right';
};

/**
 * Accordion trigger button.
 */
export function AccordionTrigger({
    children,
    className,
    iconPosition = 'right',
}: AccordionTriggerProps) {
    const itemElement = document.querySelector('[data-state]');
    const value = itemElement?.getAttribute('data-value') || '';
    const disabled = itemElement?.getAttribute('data-disabled') === 'true';

    const { openItems, toggleItem } = useAccordionContext();
    const isOpen = openItems.has(value);

    const handleClick = () => {
        if (!disabled && value) {
            toggleItem(value);
        }
    };

    return (
        <button
            type="button"
            onClick={handleClick}
            disabled={disabled}
            aria-expanded={isOpen}
            className={cn(
                'flex w-full items-center justify-between gap-3 px-5 py-4',
                'text-left text-sm font-medium text-[var(--color-text-main)]',
                'transition-colors',
                'hover:bg-[var(--color-brand-subtle)]',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand)] focus-visible:ring-offset-2',
                disabled && 'cursor-not-allowed hover:bg-transparent',
                className,
            )}
        >
            {iconPosition === 'left' && (
                <Icon
                    name="caret-down"
                    size={16}
                    weight="bold"
                    className={cn(
                        'flex-none transition-transform duration-200',
                        isOpen && 'rotate-180',
                    )}
                />
            )}

            <span className="flex-1">{children}</span>

            {iconPosition === 'right' && (
                <Icon
                    name="caret-down"
                    size={16}
                    weight="bold"
                    className={cn(
                        'flex-none transition-transform duration-200',
                        isOpen && 'rotate-180',
                    )}
                />
            )}
        </button>
    );
}

type AccordionContentProps = {
    /** Content to display when expanded */
    children: ReactNode;
    /** Additional class */
    className?: string;
};

/**
 * Accordion content panel.
 */
export function AccordionContent({ children, className }: AccordionContentProps) {
    const itemElement = document.querySelector('[data-state]');
    const value = itemElement?.getAttribute('data-value') || '';

    const { openItems } = useAccordionContext();
    const isOpen = openItems.has(value);

    if (!isOpen) {
        return null;
    }

    return (
        <div
            className={cn(
                'overflow-hidden px-5 pb-4 pt-0',
                'animate-in slide-in-from-top-2 duration-200',
                className,
            )}
            role="region"
        >
            <div className="text-sm text-[var(--color-text-body)]">{children}</div>
        </div>
    );
}
