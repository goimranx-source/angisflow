import { useEffect, useRef, useState, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type TooltipPosition = 'top' | 'bottom' | 'left' | 'right';

type TooltipProps = {
    /** Element that triggers the tooltip */
    children: ReactNode;
    /** Tooltip content */
    content: ReactNode;
    /** Position relative to trigger */
    position?: TooltipPosition;
    /** Delay before showing (ms) */
    delay?: number;
    /** Whether tooltip is disabled */
    disabled?: boolean;
    /** Additional class for tooltip content */
    className?: string;
};

/**
 * Tooltip component for hover information.
 *
 * Features:
 * - Hover to show
 * - Focus to show (keyboard accessible)
 * - Position options (top, bottom, left, right)
 * - Configurable delay
 * - Smart positioning with arrow
 * - Smooth fade-in animation
 * - Accessible
 *
 * @example
 * ```tsx
 * <Tooltip content="Click to edit" position="top">
 *   <button>Edit</button>
 * </Tooltip>
 *
 * <Tooltip
 *   content="This action cannot be undone"
 *   position="right"
 *   delay={500}
 * >
 *   <button>Delete</button>
 * </Tooltip>
 * ```
 */
export function Tooltip({
    children,
    content,
    position = 'top',
    delay = 200,
    disabled = false,
    className,
}: TooltipProps) {
    const [isVisible, setIsVisible] = useState(false);
    const timeoutRef = useRef<NodeJS.Timeout | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);

    /*
     * The side actually used, which is not always the side asked for.
     *
     * A tooltip on a heading near the top of the page, told to open upward,
     * opens above the viewport — it exists, and cannot be read. So the
     * preferred side is checked against the room available and flipped when
     * there is none. Roughly measured: the bubble is not in the DOM yet at this
     * point, and 140px is comfortably more than the tallest hint.
     */
    const [side, setSide] = useState(position);

    const resolveSide = () => {
        const box = containerRef.current?.getBoundingClientRect();

        if (!box) {
            return position;
        }

        const needed = 140;

        if (position === 'top' && box.top < needed) return 'bottom';
        if (position === 'bottom' && window.innerHeight - box.bottom < needed) return 'top';
        if (position === 'left' && box.left < 300) return 'right';
        if (position === 'right' && window.innerWidth - box.right < 300) return 'left';

        return position;
    };

    const handleMouseEnter = () => {
        if (disabled) return;

        timeoutRef.current = setTimeout(() => {
            setSide(resolveSide());
            setIsVisible(true);
        }, delay);
    };

    const handleMouseLeave = () => {
        if (timeoutRef.current) {
            clearTimeout(timeoutRef.current);
        }
        setIsVisible(false);
    };

    const handleFocus = () => {
        if (disabled) return;
        setSide(resolveSide());
        setIsVisible(true);
    };

    const handleBlur = () => {
        setIsVisible(false);
    };

    useEffect(() => {
        return () => {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
        };
    }, []);

    return (
        <div
            ref={containerRef}
            className="relative inline-block"
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
            onFocus={handleFocus}
            onBlur={handleBlur}
        >
            {children}

            {isVisible && !disabled && (
                <div
                    role="tooltip"
                    className={cn(
                        'absolute z-50 px-3 py-2',
                        // A panel like every other flyout in the shell, not an
                        // inverted chip: light on light, dark on dark. Inverting
                        // made it the one black rectangle on a white page.
                        'rounded-[var(--shell-radius)] border border-[var(--shell-border)]',
                        'bg-[var(--shell-bg)] text-[var(--color-text-body)]',
                        'text-xs font-medium leading-snug',
                        'shadow-[var(--shadow-lg)]',
                        'animate-in fade-in-0 zoom-in-95 duration-150',
                        // Wraps, with a readable measure. nowrap was fine for a
                        // two-word label and ran a sentence clean off the side
                        // of the viewport — which is what these now carry.
                        'w-max max-w-[17rem] text-left whitespace-normal',
                        // Position
                        side === 'top' && 'bottom-full left-1/2 mb-2 -translate-x-1/2',
                        side === 'bottom' && 'left-1/2 top-full mt-2 -translate-x-1/2',
                        side === 'left' && 'right-full top-1/2 mr-2 -translate-y-1/2',
                        side === 'right' && 'left-full top-1/2 ml-2 -translate-y-1/2',
                        className,
                    )}
                >
                    {content}

                    {/* Arrow */}
                    <div
                        className={cn(
                            'absolute size-2 rotate-45 bg-[var(--shell-bg)]',
                            // Only the two outward edges carry the border, so
                            // the hairline continues around the bubble instead
                            // of drawing a cross through the arrow.
                            side === 'top' &&
                                'bottom-[-5px] left-1/2 -translate-x-1/2 border-r border-b border-[var(--shell-border)]',
                            side === 'bottom' &&
                                'left-1/2 top-[-5px] -translate-x-1/2 border-l border-t border-[var(--shell-border)]',
                            side === 'left' &&
                                'right-[-5px] top-1/2 -translate-y-1/2 border-t border-r border-[var(--shell-border)]',
                            side === 'right' &&
                                'left-[-5px] top-1/2 -translate-y-1/2 border-b border-l border-[var(--shell-border)]',
                        )}
                    />
                </div>
            )}
        </div>
    );
}

type TooltipIconProps = {
    /** Tooltip content */
    content: ReactNode;
    /** Icon to show (defaults to info circle) */
    icon?: ReactNode;
    /** Position relative to icon */
    position?: TooltipPosition;
    /** Additional class for icon */
    className?: string;
};

/**
 * Tooltip icon helper - shows an info icon with tooltip.
 *
 * @example
 * ```tsx
 * <label>
 *   Username
 *   <TooltipIcon content="Must be 3-20 characters" />
 * </label>
 * ```
 */
export function TooltipIcon({
    content,
    icon,
    position = 'top',
    className,
}: TooltipIconProps) {
    return (
        <Tooltip content={content} position={position}>
            <span
                className={cn(
                    'inline-flex items-center justify-center',
                    'text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                    'transition-colors cursor-help',
                    className,
                )}
                tabIndex={0}
            >
                {icon ? (
                    icon
                ) : (
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 16 16"
                        fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path d="M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm.5 12H7V7h1.5v5zm0-6.5H7V4h1.5v1.5z" />
                    </svg>
                )}
            </span>
        </Tooltip>
    );
}
