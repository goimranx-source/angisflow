import { forwardRef, type ButtonHTMLAttributes } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
    size?: 'sm' | 'md' | 'lg';
    /** A request is in flight: shows the spinner and blocks further presses. */
    busy?: boolean;
    block?: boolean;
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'primary', size = 'md', busy = false, block = false, className, children, disabled, ...props },
    ref,
) {
    return (
        <button
            ref={ref}
            // A submit button that stays clickable while its request is in
            // flight is how one order becomes three. Disabled by the same flag
            // that draws the spinner, so the two can never disagree.
            disabled={disabled || busy}
            aria-busy={busy || undefined}
            style={{ borderRadius: 'var(--shell-radius)' }}
            className={cn(
                'btn',
                `btn-${variant}`,
                size === 'sm' && 'px-3 py-1.5 text-[0.8125rem]',
                size === 'lg' && 'px-5 py-2.5 text-[0.9375rem]',
                block && 'w-full',
                className,
            )}
            {...props}
        >
            {busy && <Icon name="spinner" size={16} className="animate-spin" />}
            {children}
        </button>
    );
});
