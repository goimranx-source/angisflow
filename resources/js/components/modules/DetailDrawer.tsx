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
    tabs?: { key: string; label: string; content: ReactNode; icon?: string }[];
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
         * Wide enough for two working columns, and never the whole screen.
         *
         * An edit form for an order is not a reading panel: the fields on the
         * left need room to sit two abreast, and what the order carries —
         * pictures, attachments, the lines — needs a column of its own beside
         * them rather than a scroll past them. 92rem is what that costs.
         *
         * On anything narrower than 92rem that cap did nothing, and the panel
         * ran the full width of the window. A panel with no page beside it is
         * not a panel — there is nothing to go back to, so it reads as a screen
         * you have navigated to rather than a layer over the one you were on.
         *
         * The second term keeps a hand's width of page showing whatever the
         * window is, and only bites on the screens where the first one did not.
         */
        '2xl': 'max-w-[min(92rem,calc(100vw-6rem))]',
    };

    return (
        <>
            {/* Backdrop */}
            {/* Darker than it was, because there is now page showing on all
                four sides of the panel rather than one strip down the left, and
                a 30% wash over that much of it left the two competing. */}
            <div
                className="fixed inset-0 z-[var(--z-overlay)] bg-[rgba(9,15,28,0.45)] transition-opacity"
                onClick={onClose}
                aria-hidden="true"
            />

            {/*
              ── A card, not a sheet welded to the edge ──────────────────────
              
              It ran corner to corner down the right-hand side, which makes a
              panel read as a second page that has slid over the first: there is
              no edge to it, so there is nothing to say where the thing you were
              reading went.

              Held off all four sides it reads as one card sitting on the page —
              the same card the list behind it is made of, and the same
              language: a hairline border, a soft shadow, the shell radius.
              The page is still visible around it, dimmed, which is what says
              this is a layer rather than a destination.

              `w-[calc(100%-2rem)]` rather than `w-full`, so the gap survives on
              a phone where the panel is as wide as it is allowed to get.
            */}
            <div
                className={cn(
                    /*
                      Centred, not pinned to one side.

                      A right-hand sheet is the shape of something you glance at
                      and dismiss — a filter, a preview, a list of alerts. This
                      one is a whole record with tabs, its own toolbar and a
                      form: it holds the screen for as long as somebody is
                      working in it, and a thing that holds the screen belongs
                      in the middle of it, with the page dimmed evenly on both
                      sides.

                      `left-1/2` and a translate rather than `inset-x-0
                      mx-auto`, so the max-width from sizeClasses still decides
                      how wide it is.
                    */
                    'fixed inset-y-4 left-1/2 z-[var(--z-modal)] flex w-[calc(100%-2rem)] -translate-x-1/2 flex-col overflow-hidden rounded-[var(--radius-md)] border bg-[var(--color-card-bg)]',
                    sizeClasses[size],
                )}
                style={{
                    borderColor: 'var(--color-border-light)',
                    boxShadow: 'var(--shadow-lg)',
                }}
                role="dialog"
                aria-modal="true"
                aria-labelledby="drawer-title"
            >
                {/*
                  ── The name and the places, in one box ─────────────────────
                  Header and tabs share a border, divided rather than separated.

                  They had a rule under each and a gap between, which drew three
                  horizontal lines within an inch and made the title look like a
                  thing apart from the tabs that belong to it. One outline says
                  what the gaps were trying to: this is the drawer's head, and
                  everything below it is the drawer's content.
                */}
                <div
                    className="mx-4 mt-4 shrink-0 overflow-hidden rounded-[var(--shell-radius)] border"
                    style={{
                        borderColor: 'var(--shell-border)',

                        /*
                         * A shadow small enough to be felt rather than seen.
                         *
                         * The head does not move while the content below it
                         * does, and without some depth the two read as one flat
                         * surface with rows sliding through it. Enough to lift
                         * it off the page and no more — a heavy shadow on a
                         * panel that never moves is just decoration.
                         */
                        boxShadow: '0 1px 2px rgb(0 0 0 / 0.04), 0 2px 8px rgb(0 0 0 / 0.04)',
                    }}
                >
                    <div className="flex items-start justify-between gap-3 px-3.5 py-2.5">
                        <div className="min-w-0 flex-1">
                            <h2
                                id="drawer-title"
                                className="truncate text-lg font-semibold text-[var(--color-text-main)]"
                            >
                                {title}
                            </h2>
                            {subtitle && (
                                <p className="mt-0.5 truncate text-sm text-[var(--color-text-muted)]">
                                    {subtitle}
                                </p>
                            )}
                        </div>

                        {actions && <div className="flex items-center gap-2">{actions}</div>}

                        <button
                            type="button"
                            onClick={onClose}
                            className="flex-none rounded p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                            aria-label="Close"
                        >
                            <Icon name="x" size={20} />
                        </button>
                    </div>

                    {tabs && tabs.length > 0 && activeTab && onTabChange && (
                        <>
                            <div
                                className="h-px"
                                style={{ background: 'var(--shell-border)' }}
                                aria-hidden="true"
                            />

                            {/*
                              Sharing the width between them.

                              A tab bar hugging its words leaves the rest of the
                              row empty and the group floating at one end of a
                              box it is supposed to fill. Divided evenly, the
                              targets are larger, and the box reads as one thing
                              rather than as a bar someone left in a corner.
                            */}
                            <Tabs
                                defaultValue={activeTab}
                                value={activeTab}
                                onValueChange={onTabChange}
                            >
                                {/*
                                  No gap between them, so a hover fills the whole
                                  tab rather than a rounded island inside it.
                                  Sharing the width is only convincing if the
                                  shares actually meet.
                                */}
                                <TabsList className="w-full !gap-0 !rounded-none !border-0 !p-0">
                                    {tabs.map((t) => (
                                        <TabsTrigger
                                            key={t.key}
                                            value={t.key}
                                            icon={t.icon}
                                            className="flex-1 justify-center !rounded-none py-2"
                                        >
                                            {t.label}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </Tabs>
                        </>
                    )}
                </div>

                {/* Content */}
                {/*
                  A column, so a panel inside it can ask for the height that is
                  left rather than guess at it.

                  `min-h-0` because a flex child's default minimum is its own
                  content — for a table of forty rows that is forty rows, and the
                  box grows to fit them and scrolls nothing.
                */}
                <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 pb-4 pt-[15px]">
                    {tabs && tabs.length > 0 && activeTab ? (
                        tabs.find((t) => t.key === activeTab)?.content ?? children
                    ) : (
                        children
                    )}
                </div>

                {/* Footer */}
                {footer && (
                    <div className="flex items-center justify-end gap-3 border-t border-[var(--color-border-light)] px-4 py-3">
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
