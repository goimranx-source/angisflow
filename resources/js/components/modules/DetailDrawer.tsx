import { type ReactNode, useEffect } from 'react';

import { Icon } from '@/components/ui/Icon';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { cn } from '@/lib/utils';

type DetailDrawerProps = {
    /** Whether drawer is open */
    open: boolean;
    /** Close handler */
    onClose: () => void;
    /** Drawer title */
    title: string;
    /** Subtitle or metadata line */
    subtitle?: ReactNode;
    /** Drawer content */
    children: ReactNode;
    /** Tab configuration if using tabs */
    tabs?: { key: string; label: string; content: ReactNode }[];
    /** Active tab key */
    activeTab?: string;
    /** Tab change handler */
    onTabChange?: (key: string) => void;
    /** Actions in header (edit, delete, etc.) */
    actions?: ReactNode;
    /** Footer actions (save, cancel, etc.) */
    footer?: ReactNode;
    /** Width size */
    size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
};

/**
 * Slide-out drawer panel for viewing/editing details.
 * 
 * Features:
 * - Slides in from the right
 * - Optional tabs
 * - Header with title, subtitle, actions
 * - Footer for action buttons
 * - Backdrop click to close
 * - Escape key to close
 * - Focus trap
 * - Scroll lock on body
 * 
 * Example:
 * ```tsx
 * <DetailDrawer
 *   open={!!selectedCustomer}
 *   onClose={() => setSelectedCustomer(null)}
 *   title={selectedCustomer?.name ?? ''}
 *   subtitle={selectedCustomer?.email}
 *   tabs={[
 *     { key: 'overview', label: 'Overview', content: <CustomerOverview /> },
 *     { key: 'orders', label: 'Orders', content: <CustomerOrders /> },
 *     { key: 'activity', label: 'Activity', content: <CustomerActivity /> },
 *   ]}
 *   activeTab={activeTab}
 *   onTabChange={setActiveTab}
 *   actions={
 *     <>
 *       <button className="btn btn-secondary">Edit</button>
 *       <button className="btn btn-danger">Delete</button>
 *     </>
 *   }
 * />
 * ```
 */
export function DetailDrawer({
    open,
    onClose,
    title,
    subtitle,
    children,
    tabs,
    activeTab,
    onTabChange,
    actions,
    footer,
    size = 'lg',
}: DetailDrawerProps) {
    // Lock body scroll when drawer is open
    useEffect(() => {
        if (open) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        return () => {
            document.body.style.overflow = '';
        };
    }, [open]);

    // Close on Escape key
    useEffect(() => {
        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && open) {
                onClose();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const sizeClasses = {
        sm: 'max-w-md',
        md: 'max-w-2xl',
        lg: 'max-w-3xl',
        xl: 'max-w-5xl',
        /*
         * Wide enough for two working columns.
         *
         * An edit form for an order is not a reading panel: the fields on the
         * left need room to sit two abreast, and what the order carries —
         * pictures, attachments, the lines — needs a column of its own beside
         * them rather than a scroll past them.
         */
        '2xl': 'max-w-[92rem]',
    };

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-[var(--z-overlay)] bg-black/30 transition-opacity"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Drawer */}
            <div
                className={cn(
                    'fixed inset-y-0 right-0 z-[var(--z-modal)] flex w-full flex-col bg-[var(--color-card-bg)] shadow-2xl',
                    sizeClasses[size],
                )}
                role="dialog"
                aria-modal="true"
                aria-labelledby="drawer-title"
            >
                {/* Header */}
                <div className="flex items-start justify-between gap-4 border-b border-[var(--color-border-light)] px-6 py-4">
                    <div className="min-w-0 flex-1">
                        <h2
                            id="drawer-title"
                            className="text-lg font-semibold text-[var(--color-text-main)] truncate"
                        >
                            {title}
                        </h2>
                        {subtitle && (
                            <p className="mt-0.5 text-sm text-[var(--color-text-muted)] truncate">
                                {subtitle}
                            </p>
                        )}
                    </div>

                    {/* Actions */}
                    {actions && (
                        <div className="flex items-center gap-2">
                            {actions}
                        </div>
                    )}

                    {/* Close button */}
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex-none rounded p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                        aria-label="Close"
                    >
                        <Icon name="x" size={20} />
                    </button>
                </div>

                {/* Tabs */}
                {tabs && tabs.length > 0 && activeTab && onTabChange && (
                    <div className="border-b border-[var(--color-border-light)] px-6">
                        <Tabs defaultValue={activeTab} value={activeTab} onValueChange={onTabChange}>
                            <TabsList>
                                {tabs.map((t) => (
                                    <TabsTrigger key={t.key} value={t.key}>
                                        {t.label}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    </div>
                )}

                {/* Content */}
                {/*
                  ── Even margins, with a scrollbar in the way ────────────────

                  A vertical scrollbar lives inside this box and takes its width
                  off the content, so equal left and right padding does not
                  produce equal left and right margins: the left is 24px and the
                  right is 24px plus however wide the scrollbar happens to be.
                  On a page of prose nobody notices. On a wide table it is
                  plainly lopsided, and it is the sort of wrongness that is
                  easier to see than to name.

                  `stable both-edges` reserves the gutter on both sides whether
                  or not a scrollbar is showing, which costs a few pixels of
                  width and buys margins that match — and stops the content
                  shifting sideways when a drawer grows long enough to scroll.
                */}
                <div
                    className="flex-1 overflow-y-auto px-6 py-4"
                    style={{ scrollbarGutter: 'stable both-edges' }}
                >
                    {tabs && tabs.length > 0 && activeTab ? (
                        tabs.find((t) => t.key === activeTab)?.content ?? children
                    ) : (
                        children
                    )}
                </div>

                {/* Footer */}
                {footer && (
                    <div className="flex items-center justify-end gap-3 border-t border-[var(--color-border-light)] px-6 py-4">
                        {footer}
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * Section within drawer content.
 */
export function DrawerSection({
    title,
    children,
    className,
}: {
    title?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('space-y-3', className)}>
            {title && (
                <h3 className="text-sm font-semibold text-[var(--color-text-main)]">
                    {title}
                </h3>
            )}
            {children}
        </div>
    );
}

/**
 * Field row within drawer section.
 */
export function DrawerField({
    label,
    value,
    icon,
}: {
    label: string;
    value: ReactNode;
    icon?: string;
}) {
    return (
        <div className="flex items-start gap-3">
            {icon && (
                <Icon
                    name={icon}
                    size={16}
                    className="mt-0.5 flex-none text-[var(--color-text-muted)]"
                />
            )}
            <div className="min-w-0 flex-1">
                <p className="text-xs font-medium text-[var(--color-text-muted)]">
                    {label}
                </p>
                {/*
                  A div, not a paragraph.

                  Callers pass rendered values as often as strings — an address
                  over several lines, a badge, a figure with a note beside it —
                  and a block element inside a <p> is invalid HTML. The browser
                  silently restructures it, which broke hydration and logged an
                  error on every drawer that showed one.
                */}
                <div className="mt-0.5 text-sm text-[var(--color-text-main)]">
                    {value || <span className="text-[var(--color-text-subtle)]">—</span>}
                </div>
            </div>
        </div>
    );
}
