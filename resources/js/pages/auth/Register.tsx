import { Link, useNavigate } from 'react-router';

import { Turnstile } from '@/components/auth/Turnstile';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { useApiForm } from '@/hooks/useApiForm';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

/**
 * Signing up creates a subscriber, not a login.
 *
 * Behind this one form the server builds an account, a trial, an owner, a first
 * set of books and a starting set of roles — in one transaction, so a failure
 * half way leaves nothing behind. See App\Domain\Identity\Actions\RegisterAccount.
 */
export default function Register() {
    const { config, apply } = useSession();
    const navigate = useNavigate();

    const form = useApiForm({
        name: '',
        business: '',
        email: '',
        password: '',
        password_confirmation: '',
        // Read from the browser rather than asked for. One fewer question on a
        // form, and it is right far more often than a picker somebody scrolls
        // past.
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? '',
        'cf-turnstile-response': '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post<{ redirect: string; boot: BootPayload }>('/auth/register', {
            reset: ['password', 'password_confirmation'],
            onSuccess: (result) => {
                apply(result.boot);
                navigate(result.redirect, { replace: true });
            },
        });
    };

    return (
        <GuestLayout
            title="Start your business"
            description="Two minutes, and the books are ready."
            footer={
                <>
                    Already have an account?{' '}
                    <Link to="/login" className="font-semibold text-[var(--color-link)]">
                        Sign in
                    </Link>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Your name" error={form.errors.name}
                    type="text"
                    value={form.data.name}
                    onChange={(event) => form.set('name', event.target.value)}
                    autoComplete="name"
                    autoFocus
                    required
                />

                <Field
                    label="Business name"
                    error={form.errors.business}
                    hint="Optional — you can change it later"
                    type="text"
                    value={form.data.business}
                    onChange={(event) => form.set('business', event.target.value)}
                    autoComplete="organization"
                />

                <Field
                    label="Email" error={form.errors.email}
                    type="email"
                    value={form.data.email}
                    onChange={(event) => form.set('email', event.target.value)}
                    autoComplete="username"
                    required
                />

                <Field
                    label="Password"
                    error={form.errors.password}
                    hint="At least 10 characters, and not one that has appeared in a known breach"
                    type="password"
                    value={form.data.password}
                    onChange={(event) => form.set('password', event.target.value)}
                    autoComplete="new-password"
                    required
                />

                <Field
                    label="Confirm password" error={form.errors.password_confirmation}
                    type="password"
                    value={form.data.password_confirmation}
                    onChange={(event) => form.set('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                    required
                />

                <Turnstile
                    siteKey={config.turnstile_site_key}
                    onToken={(token) => form.set('cf-turnstile-response', token)}
                />

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing} className="w-full">
                    Create my account
                </Button>
            </form>
        </GuestLayout>
    );
}
