import { createContext, useContext, useState, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

/**
 * ── Two levels, and why there are only two ───────────────────────────────────
 *
 * Tabs nest in this application: a page has them, the drawer opened from that
 * page has them, and a panel inside that drawer has them again. Three sets of
 * tabs on screen at once, and until now three different designs — an underline
 * here, a row of bordered buttons there, a filled segmented control below that —
 * none of which said anything about which contained which.
 *
 * So there are two looks, and they mean something.
 *
 *   underline    Where am I? The top of a page, the top of a drawer. Reads as
 *                part of the surface it sits on, because it is naming the
 *                surface.
 *
 *   segmented    Which view of this? A control inside a panel, sitting in its
 *                own tinted track so it reads as an object placed on the page
 *                rather than as a division of it.
 *
 * Two is the limit on purpose. A third level of tabs is a sign the screen wants
 * splitting, not that this component wants another variant.
 *
 * ── Why the variant travels in context ───────────────────────────────────────
 *
 * It used to be guessed, by each trigger looking at its own className for the
 * word "rounded". That worked by coincidence and broke silently the moment
 * anybody passed a rounded corner for an unrelated reason. The list knows what
 * it is; the triggers should be told rather than left to infer.
 */
type Variant = 'underline' | 'segmented';

type TabsContextType = {
    activeTab: string;
    setActiveTab: (value: string) => void;
    variant: Variant;
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
    /**
     * 'underline' names the surface — a page or a drawer.
     * 'segmented' chooses a view within one, and is what nested tabs use.
     */
    variant?: Variant;
    children: ReactNode;
    className?: string;
};

/**
 * Tabs container.
 *
 * Controlled when `value` is given, uncontrolled otherwise.
 *
 * @example
 * ```tsx
 * <Tabs defaultValue="all" value={tab} onValueChange={setTab}>
 *   <TabsList>
 *     <TabsTrigger value="all">All orders</TabsTrigger>
 *     <TabsTrigger value="archived" icon="archive" badge={12}>Archived</TabsTrigger>
 *   </TabsList>
 * </Tabs>
 * ```
 */
export function Tabs({
    defaultValue,
    value: controlledValue,
    onValueChange,
    variant = 'underline',
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
        <TabsContext.Provider value={{ activeTab, setActiveTab, variant }}>
            <div className={className}>{children}</div>
        </TabsContext.Provider>
    );
}

/** Container for the triggers. */
export function TabsList({ children, className }: { children: ReactNode; className?: string }) {
    const { variant } = useTabsContext();

    return (
        <div
            role="tablist"
            className={cn(
                'flex items-center',

                // A rule the full width of the surface, with the active tab's
                // marker sitting on it. The rule is what makes it read as a
                // division of the page rather than as a row of buttons.
                variant === 'underline' && 'gap-1 border-b border-[var(--color-border-light)]',

                // A track, so the group reads as one control. Tight, because it
                // sits inside something that already has its own padding.
                variant === 'segmented' &&
                    'gap-0.5 rounded-[var(--shell-radius)] border border-[var(--shell-border)] p-0.5',
                className,
            )}
        >
            {children}
        </div>
    );
}

type TabsTriggerProps = {
    value: string;
    children: ReactNode;
    disabled?: boolean;
    className?: string;
    /** A count, or anything else short worth saying beside the label. */
    badge?: string | number;
    /** An Icon name, shown before the label. */
    icon?: string;
};

/** One tab button. */
export function TabsTrigger({ value, children, disabled = false, className, badge, icon }: TabsTriggerProps) {
    const { activeTab, setActiveTab, variant } = useTabsContext();
    const isActive = activeTab === value;

    return (
        <button
            type="button"
            role="tab"
            id={`tab-${value}`}
            aria-selected={isActive}
            aria-controls={`tabpanel-${value}`}
            onClick={() => setActiveTab(value)}
            disabled={disabled}
            className={cn(
                'relative flex items-center gap-1.5 text-sm font-medium transition-colors duration-150',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-brand-subtle)]',

                variant === 'underline' && [
                    'px-3 py-2',

                    /*
                     * The marker sits *on* the rule, not above it.
                     *
                     * -bottom-px puts it over the list's own border, so the
                     * active tab looks joined to the panel below rather than
                     * underlined twice.
                     */
                    isActive
                        ? 'text-[var(--color-brand)] after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:bg-[var(--color-brand)]'
                        : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                ],

                variant === 'segmented' && [
                    'rounded-[var(--shell-radius-sm)] px-3 py-1',
                    isActive
                        ? 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                        : 'text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]',
                ],

                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            {icon && <Icon name={icon} size={14} className="shrink-0" />}

            <span>{children}</span>

            {badge !== undefined && badge !== '' && (
                <span
                    className={cn(
                        'rounded-full px-1.5 text-[11px] font-semibold leading-5',

                        // On a filled segment the badge cannot use the brand
                        // colour it normally would — it would disappear into the
                        // background it is sitting on.
                        variant === 'segmented' && isActive
                            ? 'bg-[color-mix(in_srgb,var(--color-text-on-accent)_25%,transparent)] text-[var(--color-text-on-accent)]'
                            : isActive
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

/** One tab panel. Renders nothing unless its tab is the active one. */
export function TabsContent({
    value,
    children,
    className,
}: {
    value: string;
    children: ReactNode;
    className?: string;
}) {
    const { activeTab } = useTabsContext();

    if (activeTab !== value) {
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
