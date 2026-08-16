import { useState } from 'react';
import { Link } from 'react-router';

import { Turnstile } from '@/components/auth/Turnstile';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { useApiForm } from '@/hooks/useApiForm';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useSession } from '@/providers/SessionProvider';

export default function ForgotPassword() {
    const { config } = useSession();
    const [sent, setSent] = useState<string | null>(null);

    const form = useApiForm({
        email: '',
        'cf-turnstile-response': '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post<{ message: string }>('/auth/password/forgot', {
            onSuccess: (result) => setSent(result.message),
        });
    };

    /*
     * The confirmation says the same thing whether the address exists or not,
     * and the server behaves the same way — see PasswordEndpoint::forgot.
     *
     * Reporting "we have no account for that" would turn this form into a way
     * to test a list of leaked addresses against the user base, one at a time,
     * with no password needed.
     */
    if (sent !== null) {
        return (
            <GuestLayout
                title="Check your email"
                footer={
                    <Link to="/login" className="font-semibold text-[var(--color-link)]">
                        Back to sign in
                    </Link>
                }
            >
                <p className="text-sm text-[var(--color-text-body)]">{sent}</p>
                <p className="mt-3 text-sm text-[var(--color-text-muted)]">
                    The link is good for an hour. If nothing arrives, check the spam folder before
                    asking for another.
                </p>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout
            title="Reset your password"
            description="Tell us the address you sign in with and we will send a link."
            footer={
                <Link to="/login" className="font-semibold text-[var(--color-link)]">
                    Back to sign in
                </Link>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email" error={form.errors.email}
                    type="email"
                    value={form.data.email}
                    onChange={(event) => form.set('email', event.target.value)}
                    autoComplete="username"
                    autoFocus
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
                    Send the link
                </Button>
            </form>
        </GuestLayout>
    );
}
