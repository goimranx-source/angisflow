import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

/*
 * ── Ten states needed more than six colours ─────────────────────────────────
 *
 * An order can be in ten, and there were six tones to say so — which meant On
 * hold and Follow-up were the same amber, Cancelled and Failed the same red,
 * and Pending, Refunded and Changed all the same grey. A colour shared by two
 * states is a colour that answers "which one is this?" with "one of these two",
 * which is most of the way to answering nothing.
 *
 * Four more, chosen to stay apart from the six already here and from each
 * other: purple, teal, orange and rose. They are separate hues rather than
 * shades of the existing ones, because two ambers a step apart are harder to
 * tell from each other than one amber and one orange.
 */
export type BadgeVariant =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'brand'
    | 'purple'
    | 'teal'
    | 'orange'
    | 'rose'
    /*
     * ── Three kept for statuses this application did not name ───────────────
     *
     * A business can add its own — "Awaiting parts", "With the workshop" — and
     * nothing here knows what they mean, so none of the ten above is the right
     * colour for one. They were all neutral, which made every added status the
     * same grey as Pending and as each other.
     *
     * These three are set aside for them. Kept apart from the ten so an added
     * status never wears the colour of a built-in one and gets read as it.
     */
    | 'indigo'
    | 'lime'
    | 'slate';
type BadgeSize = 'sm' | 'md' | 'lg';

type StatusBadgeProps = {
    /** Badge label */
    label: string;
    /** Visual variant */
    variant?: BadgeVariant;
    /** Optional icon */
    icon?: string;
    /** Size */
    size?: BadgeSize;
    /** Dot indicator instead of full background */
    dot?: boolean;
    /** Additional class names */
    className?: string;
};

/**
 * Status badge component for displaying order status, payment status, etc.
 * 
 * Features:
 * - Multiple color variants
 * - Optional icon
 * - Optional dot indicator
 * - Multiple sizes
 * 
 * Example:
 * ```tsx
 * <StatusBadge label="Paid" variant="success" icon="check-circle" />
 * <StatusBadge label="Pending" variant="warning" dot />
 * <StatusBadge label="Failed" variant="danger" />
 * <StatusBadge label="Draft" variant="neutral" />
 * ```
 */
export function StatusBadge({
    label,
    variant = 'neutral',
    icon,
    size = 'sm',
    dot = false,
    className,
}: StatusBadgeProps) {
    const variantClasses = {
        success: dot
            ? 'text-[var(--color-success)] bg-[var(--color-success-subtle)]'
            : 'bg-[var(--color-success)] text-white',
        warning: dot
            ? 'text-amber-700 bg-[var(--color-warning-subtle)]'
            : 'bg-[var(--color-warning)] text-white',
        danger: dot
            ? 'text-[var(--color-danger-text)] bg-[var(--color-danger-subtle)]'
            : 'bg-[var(--color-danger)] text-white',
        info: dot
            ? 'text-blue-700 bg-blue-50'
            : 'bg-blue-600 text-white',
        neutral: dot
            ? 'text-gray-700 bg-gray-100'
            : 'bg-gray-600 text-white',
        brand: dot
            ? 'text-[var(--color-brand-text)] bg-[var(--color-brand-subtle)]'
            : 'bg-[var(--color-brand)] text-white',
        purple: dot
            ? 'text-purple-700 bg-purple-50'
            : 'bg-purple-600 text-white',
        teal: dot
            ? 'text-teal-700 bg-teal-50'
            : 'bg-teal-600 text-white',
        orange: dot
            ? 'text-orange-700 bg-orange-50'
            : 'bg-orange-600 text-white',
        rose: dot
            ? 'text-rose-700 bg-rose-50'
            : 'bg-rose-600 text-white',
        indigo: dot
            ? 'text-indigo-700 bg-indigo-50'
            : 'bg-indigo-600 text-white',
        lime: dot
            ? 'text-lime-800 bg-lime-50'
            : 'bg-lime-600 text-white',
        slate: dot
            ? 'text-slate-700 bg-slate-100'
            : 'bg-slate-600 text-white',
    };

    const sizeClasses = {
        sm: 'text-xs px-2 py-0.5',
        md: 'text-sm px-2.5 py-1',
        lg: 'text-sm px-3 py-1.5',
    };

    const iconSizes = {
        sm: 12,
        md: 14,
        lg: 16,
    };

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 font-medium whitespace-nowrap',
                variantClasses[variant],
                sizeClasses[size],
                className,
            )}
            style={{ borderRadius: 'var(--shell-radius-sm)' }}
        >
            {dot && (
                <span
                    className={cn(
                        'inline-block rounded-full',
                        size === 'sm' ? 'size-1.5' : size === 'md' ? 'size-2' : 'size-2.5',
                        variant === 'success' && 'bg-[var(--color-success)]',
                        variant === 'warning' && 'bg-[var(--color-warning)]',
                        variant === 'danger' && 'bg-[var(--color-danger)]',
                        variant === 'info' && 'bg-blue-600',
                        variant === 'neutral' && 'bg-gray-600',
                        variant === 'brand' && 'bg-[var(--color-brand)]',
                    )}
                />
            )}
            {icon && !dot && <Icon name={icon} size={iconSizes[size]} />}
            {label}
        </span>
    );
}

/**
 * Preset status badges for common statuses.
 */
export const OrderStatus = {
    Pending: () => <StatusBadge label="Pending" variant="warning" icon="clock" />,
    Confirmed: () => <StatusBadge label="Confirmed" variant="info" icon="check" />,
    Processing: () => <StatusBadge label="Processing" variant="info" icon="hourglass" />,
    Shipped: () => <StatusBadge label="Shipped" variant="brand" icon="truck" />,
    Delivered: () => <StatusBadge label="Delivered" variant="success" icon="check-circle" />,
    Cancelled: () => <StatusBadge label="Cancelled" variant="danger" icon="x-circle" />,
    Returned: () => <StatusBadge label="Returned" variant="neutral" icon="arrow-u-down-left" />,
};

export const PaymentStatus = {
    Paid: () => <StatusBadge label="Paid" variant="success" icon="check-circle" />,
    Pending: () => <StatusBadge label="Pending" variant="warning" icon="clock" />,
    Failed: () => <StatusBadge label="Failed" variant="danger" icon="warning" />,
    Refunded: () => <StatusBadge label="Refunded" variant="neutral" icon="arrow-counter-clockwise" />,
    PartiallyPaid: () => <StatusBadge label="Partially Paid" variant="info" icon="circle-half" />,
};

export const InvoiceStatus = {
    Draft: () => <StatusBadge label="Draft" variant="neutral" icon="file-dashed" />,
    Sent: () => <StatusBadge label="Sent" variant="info" icon="paper-plane-tilt" />,
    Viewed: () => <StatusBadge label="Viewed" variant="brand" icon="eye" />,
    Paid: () => <StatusBadge label="Paid" variant="success" icon="check-circle" />,
    Overdue: () => <StatusBadge label="Overdue" variant="danger" icon="warning" />,
    Void: () => <StatusBadge label="Void" variant="neutral" icon="x-circle" />,
};

export const StockStatus = {
    InStock: () => <StatusBadge label="In Stock" variant="success" dot />,
    LowStock: () => <StatusBadge label="Low Stock" variant="warning" dot />,
    OutOfStock: () => <StatusBadge label="Out of Stock" variant="danger" dot />,
    OnOrder: () => <StatusBadge label="On Order" variant="info" dot />,
};

export const UserStatus = {
    Active: () => <StatusBadge label="Active" variant="success" dot />,
    Inactive: () => <StatusBadge label="Inactive" variant="neutral" dot />,
    Invited: () => <StatusBadge label="Invited" variant="info" dot />,
    Suspended: () => <StatusBadge label="Suspended" variant="danger" dot />,
};

export const BookingStatus = {
    Scheduled: () => <StatusBadge label="Scheduled" variant="info" icon="calendar" />,
    Confirmed: () => <StatusBadge label="Confirmed" variant="success" icon="check" />,
    InProgress: () => <StatusBadge label="In Progress" variant="brand" icon="hourglass" />,
    Completed: () => <StatusBadge label="Completed" variant="success" icon="check-circle" />,
    Cancelled: () => <StatusBadge label="Cancelled" variant="danger" icon="x-circle" />,
    NoShow: () => <StatusBadge label="No Show" variant="neutral" icon="warning" />,
};
