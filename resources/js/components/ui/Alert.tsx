import { useState, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type AlertVariant = 'info' | 'success' | 'warning' | 'error';

type AlertProps = {
    /** Visual variant */
    variant?: AlertVariant;
    /** Alert title */
    title?: string;
    /** Alert content */
    children: ReactNode;
    /** Show dismiss button */
    dismissible?: boolean;
    /** Callback when dismissed */
    onDismiss?: () => void;
    /** Additional class */
    className?: string;
    /** Icon override (defaults based on variant) */
    icon?: string;
};

/**
 * Alert/Banner component for inline messages.
 *
 * Features:
 * - Multiple variants (info, success, warning, error)
 * - Optional title
 * - Dismissible
 * - Icons
 * - Accessible
 *
 * @example
 * ```tsx
 * <Alert variant="success" title="Success!">
 *   Your settings have been saved.
 * </Alert>
 *
 * <Alert variant="warning" dismissible onDismiss={() => setShowAlert(false)}>
 *   Your session will expire in 5 minutes.
 * </Alert>
 *
 * <Alert variant="error" title="Error">
 *   Failed to save changes. Please try again.
 * </Alert>
 * ```
 */
export function Alert({
    variant = 'info',
    title,
    children,
    dismissible = false,
    onDismiss,
    className,
    icon: customIcon,
}: AlertProps) {
    const [isVisible, setIsVisible] = useState(true);

    const handleDismiss = () => {
        setIsVisible(false);
        onDismiss?.();
    };

    if (!isVisible) {
        return null;
    }

    const variantStyles: Record<AlertVariant, { bg: string; border: string; text: string; iconBg: string; iconColor: string }> = {
        info: {
            bg: 'bg-blue-50',
            border: 'border-blue-200',
            text: 'text-blue-800',
            iconBg: 'bg-blue-100',
            iconColor: 'text-blue-600',
        },
        success: {
            bg: 'bg-green-50',
            border: 'border-green-200',
            text: 'text-green-800',
            iconBg: 'bg-green-100',
            iconColor: 'text-green-600',
        },
        warning: {
            bg: 'bg-amber-50',
            border: 'border-amber-200',
            text: 'text-amber-800',
            iconBg: 'bg-amber-100',
            iconColor: 'text-amber-600',
        },
        error: {
            bg: 'bg-red-50',
            border: 'border-red-200',
            text: 'text-red-800',
            iconBg: 'bg-red-100',
            iconColor: 'text-red-600',
        },
    };

    const defaultIcons: Record<AlertVariant, string> = {
        info: 'info',
        success: 'check-circle',
        warning: 'warning',
        error: 'x-circle',
    };

    const icon = customIcon || defaultIcons[variant];
    const styles = variantStyles[variant];

    return (
        <div
            role="alert"
            className={cn(
                'rounded-lg border p-4',
                styles.bg,
                styles.border,
                className,
            )}
        >
            <div className="flex items-start gap-3">
                {/* Icon */}
                <div className={cn('flex-none rounded-lg p-1.5', styles.iconBg)}>
                    <Icon name={icon} size={18} weight="fill" className={styles.iconColor} />
                </div>

                {/* Content */}
                <div className="flex-1 min-w-0">
                    {title && (
                        <h3 className={cn('font-semibold', styles.text)}>
                            {title}
                        </h3>
                    )}
                    <div className={cn('text-sm', title && 'mt-1', styles.text)}>
                        {children}
                    </div>
                </div>

                {/* Dismiss button */}
                {dismissible && (
                    <button
                        type="button"
                        onClick={handleDismiss}
                        className={cn(
                            'flex-none rounded-lg p-1 transition-colors',
                            styles.iconColor,
                            'hover:bg-white/50',
                        )}
                        aria-label="Dismiss"
                    >
                        <Icon name="x" size={16} weight="bold" />
                    </button>
                )}
            </div>
        </div>
    );
}

