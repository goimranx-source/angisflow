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

// The same four tones .stat-tile and .notice already use, so a toast reads as
// the same status language as the rest of the shell rather than inventing its
// own palette out of raw Tailwind greens and reds.
const TONE_VARS: Record<ToastTone, { accent: string; subtle: string }> = {
    success: { accent: 'var(--color-success)', subtle: 'var(--color-success-subtle)' },
    error: { accent: 'var(--color-danger-text)', subtle: 'var(--color-danger-subtle)' },
    warning: { accent: 'var(--color-warning)', subtle: 'var(--color-warning-subtle)' },
    info: { accent: 'var(--color-info)', subtle: 'var(--color-info-subtle)' },
};

/**
 * Messages, bottom-left.
 *
 * Rendered by the shell rather than by each page, so a message survives the
 * navigation that produced it — a redirect after a save would otherwise unmount
 * whatever was showing it before anybody read it.
 *
 * Bottom-left rather than top-right: nothing else in the shell anchors there,
 * so a toast never competes with the header's own popovers (search, the
 * account menu, notifications) for the same corner of the screen. Stacked in
 * reverse — the newest arrival sits at the bottom, nearest the corner it slid
 * in from — so an unread message never gets pushed upward, out from under the
 * pointer, by the next one arriving.
 */
export function Toasts() {
    const toasts = useToasts();

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div
            className="pointer-events-none fixed bottom-4 left-4 z-[var(--z-toast)] flex w-[min(22rem,calc(100vw-2rem))] flex-col-reverse gap-2.5"
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

    const { accent, subtle } = TONE_VARS[toast.tone] ?? TONE_VARS.info;

    return (
        <div
            className={cn(
                'pointer-events-auto relative overflow-hidden',
                'rounded-[var(--shell-radius)] border',
                'transition-all duration-200 ease-out',
                // Slides in from the left — the corner it lives in — rather
                // than the top-right entrance a right-hand toast would use.
                isVisible ? 'translate-x-0 opacity-100' : '-translate-x-6 opacity-0',
                isExiting && '-translate-x-6 opacity-0',
            )}
            style={{
                background: 'var(--shell-bg)',
                borderColor: 'var(--shell-border)',
                boxShadow: 'var(--shadow-lg)',
            }}
        >
            {/* The one bit of colour: a bar down the leading edge, same
                treatment .notice uses for its tone — drawn as the border
                itself so it cannot add width and shift the contents. */}
            <div
                className="absolute inset-y-0 left-0 w-[3px]"
                style={{ background: accent }}
            />

            {/* Progress bar — only while it is still going to auto-dismiss. */}
            {!toast.persistent && (
                <div className="absolute inset-x-0 top-0 h-[2px] overflow-hidden" style={{ background: subtle }}>
                    <div
                        className="h-full origin-right"
                        style={{
                            background: accent,
                            animation: `toast-progress ${toast.tone === 'error' ? '8000ms' : '4000ms'} linear forwards`,
                        }}
                    />
                </div>
            )}

            <div className="flex items-start gap-3 py-3 pl-4 pr-3">
                {/* Icon, in the same subtle-tile treatment as a stat card. */}
                <div
                    className="mt-0.5 grid flex-none place-items-center rounded-[var(--shell-radius-sm)]"
                    style={{ background: subtle, color: accent, width: '1.75rem', height: '1.75rem' }}
                >
                    <Icon name={ICONS[toast.tone] ?? 'info'} size={16} weight="fill" />
                </div>

                {/* Message & action */}
                <div className="min-w-0 flex-1 space-y-1.5 pt-0.5">
                    <p className="text-sm leading-5 font-medium text-[var(--color-text-main)]">
                        {toast.message}
                    </p>

                    {toast.action && (
                        <button
                            type="button"
                            onClick={handleAction}
                            className="text-xs font-semibold underline underline-offset-2 transition-colors"
                            style={{ color: accent }}
                        >
                            {toast.action.label}
                        </button>
                    )}
                </div>

                {/* Dismiss */}
                <button
                    type="button"
                    onClick={handleDismiss}
                    className="flex-none rounded-[var(--shell-radius-sm)] p-1 text-[var(--color-text-muted)] transition-colors hover:bg-[var(--shell-hover)] hover:text-[var(--color-text-main)]"
                    aria-label="Dismiss"
                >
                    <Icon name="x" size={14} weight="bold" />
                </button>
            </div>
        </div>
    );
}

// The progress bar's keyframe — injected once, module-scope, rather than
// duplicated on every toast instance.
if (typeof document !== 'undefined' && !document.getElementById('toast-progress-keyframes')) {
    const style = document.createElement('style');
    style.id = 'toast-progress-keyframes';
    style.textContent = `
        @keyframes toast-progress {
            from {
                transform: scaleX(1);
            }
            to {
                transform: scaleX(0);
            }
        }
    `;
    document.head.appendChild(style);
}
