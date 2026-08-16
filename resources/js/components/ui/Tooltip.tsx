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

    const handleMouseEnter = () => {
        if (disabled) return;

        timeoutRef.current = setTimeout(() => {
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
                        'absolute z-50 rounded-lg px-3 py-2',
                        'bg-[var(--color-text-main)] text-[var(--color-card-bg)]',
                        'text-xs font-medium leading-snug',
                        'shadow-lg',
                        'animate-in fade-in-0 zoom-in-95 duration-150',
                        'whitespace-nowrap',
                        // Position
                        position === 'top' && 'bottom-full left-1/2 mb-2 -translate-x-1/2',
                        position === 'bottom' && 'left-1/2 top-full mt-2 -translate-x-1/2',
                        position === 'left' && 'right-full top-1/2 mr-2 -translate-y-1/2',
                        position === 'right' && 'left-full top-1/2 ml-2 -translate-y-1/2',
                        className,
                    )}
                >
                    {content}

                    {/* Arrow */}
                    <div
                        className={cn(
                            'absolute size-2 rotate-45 bg-[var(--color-text-main)]',
                            position === 'top' && 'bottom-[-4px] left-1/2 -translate-x-1/2',
                            position === 'bottom' && 'left-1/2 top-[-4px] -translate-x-1/2',
                            position === 'left' && 'right-[-4px] top-1/2 -translate-y-1/2',
                            position === 'right' && 'left-[-4px] top-1/2 -translate-y-1/2',
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
