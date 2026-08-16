import { useNavigate, useParams, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { useApiForm } from '@/hooks/useApiForm';
import { GuestLayout } from '@/layouts/GuestLayout';
import { toast } from '@/lib/toast';

/**
 * Setting a new password from a reset link.
 *
 * The token comes from the path and the address from the query string, exactly
 * as the mail put them there. Both are sent back untouched — the server pairs
 * them against what it issued, so neither is trusted here.
 *
 * A successful reset also drops every other session for that user. Somebody
 * resetting a password usually believes their account is compromised, and a new
 * password is worth nothing while the intruder's session is still signed in
 * beside it.
 */
export default function ResetPassword() {
    const { token } = useParams<{ token: string }>();
    const [search] = useSearchParams();
    const navigate = useNavigate();

    const form = useApiForm({
        token: token ?? '',
        email: search.get('email') ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post<{ message: string; redirect: string }>('/auth/password/reset', {
            reset: ['password', 'password_confirmation'],
            onSuccess: (result) => {
                toast.success(result.message);
                navigate(result.redirect, { replace: true });
            },
        });
    };

    return (
        <GuestLayout title="Choose a new password">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email" error={form.errors.email}
                    type="email"
                    value={form.data.email}
                    onChange={(event) => form.set('email', event.target.value)}
                    autoComplete="username"
                    required
                />

                <Field
                    label="New password"
                    error={form.errors.password}
                    hint="At least 10 characters, and not one that has appeared in a known breach"
                    type="password"
                    value={form.data.password}
                    onChange={(event) => form.set('password', event.target.value)}
                    autoComplete="new-password"
                    autoFocus
                    required
                />

                <Field
                    label="Confirm it" error={form.errors.password_confirmation}
                    type="password"
                    value={form.data.password_confirmation}
                    onChange={(event) => form.set('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                    required
                />

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing} className="w-full">
                    Save and sign in
                </Button>
            </form>
        </GuestLayout>
    );
}
