import { forwardRef, useId, useState, type InputHTMLAttributes, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type FieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
    label: string;
    error?: string;
    hint?: ReactNode;
};

/**
 * A labelled input that reports its own errors.
 *
 * The wiring here is the accessibility that usually gets skipped: the label is
 * bound to the input by id, the error is announced through aria-describedby and
 * marked aria-invalid, and the message carries role="alert" so a screen reader
 * says it when it appears rather than when the user next tabs past it. On a
 * sign-in form that is the difference between "it didn't work" and knowing why.
 */
export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field(
    { label, error, hint, className, type = 'text', ...props },
    ref,
) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    const [revealed, setRevealed] = useState(false);
    const isPassword = type === 'password';

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                {label}
            </label>

            <div className="relative">
                <input
                    ref={ref}
                    id={id}
                    type={isPassword && revealed ? 'text' : type}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={cn(error && errorId, hint && hintId) || undefined}
                    className={cn('field', isPassword && 'pr-11', className)}
                    {...props}
                />

                {isPassword && (
                    <button
                        type="button"
                        onClick={() => setRevealed((was) => !was)}
                        // Not in the tab order: somebody tabbing through a login
                        // form wants to reach the submit button, not a toggle
                        // they did not ask for. Still reachable by pointer and
                        // by screen reader.
                        tabIndex={-1}
                        aria-label={revealed ? 'Hide password' : 'Show password'}
                        className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)]"
                    >
                        <Icon name={revealed ? 'eye-slash' : 'eye'} size={17} weight="regular" />
                    </button>
                )}
            </div>

            {hint && !error && (
                <p id={hintId} className="text-xs text-[var(--color-text-muted)]">
                    {hint}
                </p>
            )}

            {error && (
                <p id={errorId} role="alert" className="text-xs font-medium text-[var(--color-danger-text)]">
                    {error}
                </p>
            )}
        </div>
    );
});
