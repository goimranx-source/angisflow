import { useNavigate, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { useApiForm } from '@/hooks/useApiForm';
import { GuestLayout } from '@/layouts/GuestLayout';

/**
 * "Confirm your password" — the check in front of the handful of things that
 * would be catastrophic if somebody sat down at an unlocked laptop.
 *
 * A live session proves somebody signed in this morning. It does not prove the
 * person at the keyboard now is the same one, and for changing how an account
 * is secured that difference is the whole point.
 */
export default function ConfirmPassword() {
    const navigate = useNavigate();
    const [search] = useSearchParams();

    // Where to go back to once confirmed. Defaults to the profile, which is
    // where all of these actions live.
    const back = search.get('next') ?? '/profile';

    const form = useApiForm({ password: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.post('/auth/password/confirm', {
            reset: ['password'],
            onSuccess: () => navigate(back, { replace: true }),
        });
    };

    return (
        <GuestLayout
            title="Confirm it is you"
            description="This is a secure area. Enter your password to carry on."
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Password" error={form.errors.password}
                    type="password"
                    value={form.data.password}
                    onChange={(event) => form.set('password', event.target.value)}
                    autoComplete="current-password"
                    autoFocus
                    required
                />

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing} className="w-full">
                    Confirm
                </Button>
            </form>
        </GuestLayout>
    );
}
