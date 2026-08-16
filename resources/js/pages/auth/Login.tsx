import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';

import { Turnstile } from '@/components/auth/Turnstile';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { useApiForm } from '@/hooks/useApiForm';
import { ApiError } from '@/lib/api';
import { GuestLayout } from '@/layouts/GuestLayout';
import { toast } from '@/lib/toast';
import { isPasskeySupported, signInWithPasskey } from '@/lib/webauthn';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

type LoginResult = {
    two_factor_required: boolean;
    redirect: string;
    boot?: BootPayload;
};

export default function Login() {
    const { config, apply } = useSession();
    const navigate = useNavigate();
    const location = useLocation();
    const [passkeyBusy, setPasskeyBusy] = useState(false);

    // Where they were headed before being sent here. Sending somebody back to
    // the page they asked for is the difference between signing in and being
    // interrupted.
    const intended = (location.state as { from?: string } | null)?.from ?? null;

    const form = useApiForm({
        email: '',
        password: '',
        remember: false,
        'cf-turnstile-response': '',
    });

    const land = (result: LoginResult) => {
        if (result.boot) {
            // The whole shell arrives with the sign-in, so the application is
            // drawn from this response rather than from a second request asking
            // who just signed in.
            apply(result.boot);
        }

        navigate(result.two_factor_required ? '/two-factor' : (intended ?? result.redirect), {
            replace: true,
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post<LoginResult>('/auth/login', {
            reset: ['password'],
            onSuccess: land,
        });
    };

    const usePasskey = async () => {
        setPasskeyBusy(true);

        try {
            land(await signInWithPasskey());
        } catch (error) {
            // A cancelled prompt is not a failure — the user changed their
            // mind, and telling them off for it is noise.
            if (error instanceof DOMException && error.name === 'NotAllowedError') {
                return;
            }

            toast.error(
                error instanceof ApiError ? error.message : 'That passkey could not be used.',
            );
        } finally {
            setPasskeyBusy(false);
        }
    };

    return (
        <GuestLayout
            title="Sign in"
            description="Welcome back."
            footer={
                config.registration_enabled ? (
                    <>
                        No account yet?{' '}
                        <Link to="/register" className="font-semibold text-[var(--color-link)]">
                            Start a business
                        </Link>
                    </>
                ) : undefined
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email" error={form.errors.email}
                    type="email"
                    name="email"
                    value={form.data.email}
                    onChange={(event) => form.set('email', event.target.value)}
                    // The browser's own credential manager. It is faster
                    // than anything we could build and it is what stops
                    // people reusing one password everywhere.
                    autoComplete="username"
                    autoFocus
                    required
                    aria-invalid={form.errors.email ? 'true' : undefined}
                />

                <Field
                    label="Password"
                    error={form.errors.password}
                    hint={
                    <Link to="/forgot-password" className="text-[var(--color-link)]">
                    Forgotten it?
                    </Link>
                    }
                    type="password"
                    name="password"
                    value={form.data.password}
                    onChange={(event) => form.set('password', event.target.value)}
                    autoComplete="current-password"
                    required
                    aria-invalid={form.errors.password ? 'true' : undefined}
                />

                <label className="flex items-center gap-2 text-sm text-[var(--color-text-body)]">
                    <input
                        type="checkbox"
                        checked={form.data.remember}
                        onChange={(event) => form.set('remember', event.target.checked)}
                        className="size-4 rounded border-[var(--color-border-strong)]"
                    />
                    Keep me signed in
                </label>

                <Turnstile
                    siteKey={config.turnstile_site_key}
                    onToken={(token) => form.set('cf-turnstile-response', token)}
                />

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing} className="w-full">
                    Sign in
                </Button>
            </form>

            {isPasskeySupported() && (
                <>
                    <div className="my-5 flex items-center gap-3 text-xs text-[var(--color-text-muted)]">
                        <span className="h-px flex-1 bg-[var(--color-border-light)]" />
                        or
                        <span className="h-px flex-1 bg-[var(--color-border-light)]" />
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        busy={passkeyBusy}
                        onClick={() => void usePasskey()}
                        className="w-full"
                    >
                        <Icon name="fingerprint" size={17} />
                        Use a passkey
                    </Button>
                </>
            )}
        </GuestLayout>
    );
}
