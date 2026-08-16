import { useState } from 'react';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { useApiForm } from '@/hooks/useApiForm';
import { api } from '@/lib/api';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

/**
 * The second factor, asked for after the password has already been accepted.
 *
 * Sits outside the shell and outside its guards on purpose: putting the
 * challenge behind the check that the challenge has been answered is a loop
 * nobody escapes.
 */
export default function TwoFactor() {
    const { apply, clear } = useSession();
    const navigate = useNavigate();
    const [useRecovery, setUseRecovery] = useState(false);

    const form = useApiForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post<{ redirect: string; boot: BootPayload }>('/auth/two-factor/challenge', {
            reset: ['code', 'recovery_code'],
            onSuccess: (result) => {
                apply(result.boot);
                navigate(result.redirect, { replace: true });
            },
        });
    };

    // A way out that does not involve closing the tab. Somebody who cannot
    // produce a code and cannot find a recovery code is otherwise stuck on this
    // screen with a half-authenticated session.
    const signOut = async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            clear();
            navigate('/login', { replace: true });
        }
    };

    return (
        <GuestLayout
            title="One more step"
            description={
                useRecovery
                    ? 'Enter one of the recovery codes you saved when you turned this on.'
                    : 'Enter the six-digit code from your authenticator app.'
            }
            footer={
                <button
                    type="button"
                    onClick={() => void signOut()}
                    className="font-semibold text-[var(--color-link)]"
                >
                    Sign in as somebody else
                </button>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                {useRecovery ? (
                    <Field
                    label="Recovery code" error={form.errors.recovery_code}
                    type="text"
                    className="font-[family-name:var(--font-mono)]"
                    value={form.data.recovery_code}
                    onChange={(event) => form.set('recovery_code', event.target.value)}
                    autoComplete="one-time-code"
                    autoFocus
                    required
                />
                ) : (
                    <Field
                    label="Authentication code" error={form.errors.code}
                    type="text"
                    className="text-center font-[family-name:var(--font-mono)] text-lg tracking-[0.3em]"
                    value={form.data.code}
                    onChange={(event) =>
                    // Digits only, and capped at six. The two things
                    // that actually go wrong here are a pasted space
                    // and a typed seventh digit.
                    form.set('code', event.target.value.replace(/\D/g, '').slice(0, 6))
                    }
                    inputMode="numeric"
                    // Lets a phone offer the code straight from the
                    // notification, which removes the retyping entirely.
                    autoComplete="one-time-code"
                    maxLength={6}
                    autoFocus
                    required
                />
                )}

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing} className="w-full">
                    Continue
                </Button>

                <button
                    type="button"
                    onClick={() => setUseRecovery((was) => !was)}
                    className="w-full text-center text-sm text-[var(--color-link)]"
                >
                    {useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
                </button>
            </form>
        </GuestLayout>
    );
}
