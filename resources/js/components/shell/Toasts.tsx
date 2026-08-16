import { useEffect, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { dismissToast, useToasts, type ToastTone } from '@/lib/toast';
import { cn } from '@/lib/utils';

const ICONS: Record<ToastTone, string> = {
    success: 'check-circle',
    error: 'x-circle',
    warning: 'warning-circle',
    info: 'info',
};

/**
 * Messages, in the corner.
 *
 * Rendered by the shell rather than by each page, so a message survives the
 * navigation that produced it — a redirect after a save would otherwise unmount
 * whatever was showing it before anybody read it.
 *
 * Enhanced with:
 * - Professional styling matching the design system
 * - Smooth animations (slide-in, fade-out)
 * - Progress bar showing time remaining
 * - Better contrast and spacing
 * - Theme-aware colors (light/dark compatible)
 */
export function Toasts() {
    const toasts = useToasts();

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div
            className="pointer-events-none fixed top-4 right-4 z-[100] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3"
            // Announced by a screen reader without stealing focus, which is
            // what a status message should do and what an alert should not.
            role="status"
            aria-live="polite"
        >
            {toasts.map((toast) => (
                <Toast key={toast.id} toast={toast} />
            ))}
        </div>
    );
}

type ToastProps = {
    toast: {
        id: number;
        tone: ToastTone;
        message: string;
        action?: {
            label: string;
            onClick: () => void;
        };
        persistent?: boolean;
    };
};

function Toast({ toast }: ToastProps) {
    const [isExiting, setIsExiting] = useState(false);
    const [isVisible, setIsVisible] = useState(false);

    // Mount animation
    useEffect(() => {
        // Trigger enter animation after mount
        requestAnimationFrame(() => {
            setIsVisible(true);
        });
    }, []);

    const handleDismiss = () => {
        setIsExiting(true);
        // Wait for exit animation before actually dismissing
        setTimeout(() => {
            dismissToast(toast.id);
        }, 200);
    };

    const handleAction = () => {
        if (toast.action) {
            toast.action.onClick();
            handleDismiss();
        }
    };

    return (
        <div
            className={cn(
                'pointer-events-auto relative overflow-hidden rounded-xl border shadow-lg',
                'bg-[var(--color-card-bg)] backdrop-blur-sm',
                'transition-all duration-200 ease-out',
                // Enter animation
                isVisible ? 'translate-x-0 opacity-100' : 'translate-x-full opacity-0',
                // Exit animation
                isExiting && 'translate-x-full opacity-0',
                // Tone-specific border colors
                toast.tone === 'success' && 'border-green-200',
                toast.tone === 'error' && 'border-red-200',
                toast.tone === 'warning' && 'border-amber-200',
                toast.tone === 'info' && 'border-blue-200',
            )}
        >
            {/* Progress bar - only show if not persistent */}
            {!toast.persistent && (
                <div className="absolute top-0 left-0 right-0 h-1 overflow-hidden">
                    <div
                        className={cn(
                            'h-full',
                            toast.tone === 'success' && 'bg-green-500',
                            toast.tone === 'error' && 'bg-red-500',
                            toast.tone === 'warning' && 'bg-amber-500',
                            toast.tone === 'info' && 'bg-blue-500',
                        )}
                        style={{
                            animation: `toast-progress ${toast.tone === 'error' ? '8000ms' : '4000ms'} linear forwards`,
                        }}
                    />
                </div>
            )}

            <div className="flex items-start gap-3 px-4 py-3.5">
                {/* Icon */}
                <div
                    className={cn(
                        'flex-none rounded-lg p-1.5',
                        toast.tone === 'success' && 'bg-green-100 text-green-700',
                        toast.tone === 'error' && 'bg-red-100 text-red-700',
                        toast.tone === 'warning' && 'bg-amber-100 text-amber-700',
                        toast.tone === 'info' && 'bg-blue-100 text-blue-700',
                    )}
                >
                    <Icon name={ICONS[toast.tone] ?? 'info'} size={18} weight="fill" />
                </div>

                {/* Message & Action */}
                <div className="flex-1 min-w-0 space-y-2 pt-1">
                    <p className="text-sm font-medium leading-5 text-[var(--color-text-main)]">
                        {toast.message}
                    </p>
                    
                    {/* Action button */}
                    {toast.action && (
                        <button
                            type="button"
                            onClick={handleAction}
                            className={cn(
                                'text-xs font-semibold underline underline-offset-2',
                                'transition-colors',
                                toast.tone === 'success' && 'text-green-700 hover:text-green-800',
                                toast.tone === 'error' && 'text-red-700 hover:text-red-800',
                                toast.tone === 'warning' && 'text-amber-700 hover:text-amber-800',
                                toast.tone === 'info' && 'text-blue-700 hover:text-blue-800',
                            )}
                        >
                            {toast.action.label}
                        </button>
                    )}
                </div>

                {/* Dismiss button */}
                <button
                    type="button"
                    onClick={handleDismiss}
                    className={cn(
                        'flex-none rounded-lg p-1 transition-colors',
                        'text-[var(--color-text-muted)]',
                        'hover:bg-[var(--color-brand-subtle)] hover:text-[var(--color-text-main)]',
                    )}
                    aria-label="Dismiss"
                >
                    <Icon name="x" size={16} weight="bold" />
                </button>
            </div>
        </div>
    );
}

// Add keyframe animation for progress bar
if (typeof document !== 'undefined') {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes toast-progress {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0%);
            }
        }
    `;
    document.head.appendChild(style);
}
