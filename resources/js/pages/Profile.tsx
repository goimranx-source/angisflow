import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useApiForm } from '@/hooks/useApiForm';
import { api, ApiError } from '@/lib/api';
import { toast } from '@/lib/toast';
import { isPasskeySupported, registerPasskey } from '@/lib/webauthn';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

type Passkey = {
    id: string;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
    disabled: boolean;
};

/**
 * Profile and security, on one screen.
 *
 * Everything here that changes how somebody proves who they are — enabling
 * two-factor, adding a passkey, changing a password — is behind a fresh
 * password check on the server. A 423 comes back if it has gone stale, and the
 * client sends them to confirm and then straight back to what they were doing.
 */
export default function Profile() {
    const { auth, apply, refresh } = useSession();
    const navigate = useNavigate();
    const [search, setSearch] = useSearchParams();

    useDocumentTitle('Profile & security');

    // Arriving back from the verification link.
    useEffect(() => {
        if (search.get('verified') === '1') {
            toast.success('Email verified.');
            void refresh();
            setSearch({}, { replace: true });
        }
    }, [search, setSearch, refresh]);

    if (!auth) {
        return null;
    }

    /** Anything refused for a stale password sends them to confirm, then back. */
    const handleSensitive = (error: unknown) => {
        if (error instanceof ApiError && error.needsPasswordConfirmation) {
            navigate('/confirm-password?next=/profile');

            return true;
        }

        return false;
    };

    return (
        <div className="mx-auto max-w-3xl space-y-5">
            <PageHeader title="Profile & security" description={auth.user.email} />

            {!auth.user.email_verified && <VerifyEmailNotice />}

            <DetailsCard onSaved={apply} />
            <PasswordCard onSensitive={handleSensitive} />
            <TwoFactorCard onSensitive={handleSensitive} />
            {isPasskeySupported() && <PasskeysCard onSensitive={handleSensitive} />}
            
            {/* Danger Zone - Account Deletion */}
            <DangerZoneCard />
        </div>
    );
}

// ── Email ────────────────────────────────────────────────────────────────────

function VerifyEmailNotice() {
    const [busy, setBusy] = useState(false);

    const resend = async () => {
        setBusy(true);

        try {
            const result = await api.post<{ message: string }>('/auth/email/resend');
            toast.success(result.message);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not send it.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div
            className="card flex flex-wrap items-center gap-3 p-4"
            style={{ background: 'var(--color-warning-subtle)', borderColor: 'var(--color-warning)' }}
        >
            <Icon name="warning" size={18} style={{ color: 'var(--color-warning)' }} />
            <p className="flex-1 text-sm text-[var(--color-text-body)]">
                Your email is not verified yet. Nothing is locked — it just means we cannot reach you
                about the account.
            </p>
            <Button variant="secondary" busy={busy} onClick={() => void resend()}>
                Send the link again
            </Button>
        </div>
    );
}

// ── Details ──────────────────────────────────────────────────────────────────

function DetailsCard({ onSaved }: { onSaved: (boot: BootPayload) => void }) {
    const { auth } = useSession();
    const [avatarUploading, setAvatarUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useApiForm({
        name: auth?.user.name ?? '',
        email: auth?.user.email ?? '',
        timezone: auth?.user.timezone ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.patch<{ message: string; boot: BootPayload }>('/profile', {
            onSuccess: (result) => {
                // The shell shows the name and the verification chip, so it
                // comes back with the save rather than being re-fetched.
                onSaved(result.boot);
                toast.success(result.message);
            },
        });
    };

    const handleAvatarChange = async (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;

        // Validate file type
        if (!file.type.startsWith('image/')) {
            toast.error('Please select an image file');
            return;
        }

        // Validate file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            toast.error('Image must be smaller than 2MB');
            return;
        }

        setAvatarUploading(true);

        try {
            const formData = new FormData();
            formData.append('avatar', file);

            const result = await api.post<{ message: string; boot: BootPayload }>('/profile/avatar', formData);
            
            onSaved(result.boot);
            toast.success(result.message);
        } catch (error) {
            const apiError = error as { message?: string; errors?: Record<string, string[]> };
            
            if (apiError.errors?.avatar?.[0]) {
                toast.error(apiError.errors.avatar[0]);
            } else {
                toast.error(apiError.message ?? 'Could not upload profile picture.');
            }
        } finally {
            setAvatarUploading(false);
            // Clear the input
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    };

    const removeAvatar = async () => {
        if (!auth?.user.avatar) return;
        
        if (!window.confirm('Remove your profile picture?')) {
            return;
        }

        setAvatarUploading(true);

        try {
            const result = await api.delete<{ message: string; boot: BootPayload }>('/profile/avatar');
            
            onSaved(result.boot);
            toast.success(result.message);
        } catch (error) {
            const apiError = error as { message?: string };
            toast.error(apiError.message ?? 'Could not remove profile picture.');
        } finally {
            setAvatarUploading(false);
        }
    };

    return (
        <Card title="Your details">
            <div className="space-y-6">
                {/* Profile Picture Section */}
                <div className="space-y-3">
                    <label className="block text-sm font-semibold text-[var(--color-text-main)]">
                        Profile picture
                    </label>
                    
                    <div className="flex items-start gap-4">
                        {/* Avatar Preview */}
                        <div className="flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--color-brand-subtle)] text-[var(--color-brand)]">
                            {auth?.user.avatar ? (
                                <img
                                    src={auth.user.avatar}
                                    alt="Profile picture"
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <Icon name="user" size={32} weight="regular" />
                            )}
                        </div>

                        {/* Upload Controls */}
                        <div className="flex-1 space-y-2">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/*"
                                onChange={handleAvatarChange}
                                className="hidden"
                                id="avatar-upload"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    busy={avatarUploading}
                                    onClick={() => fileInputRef.current?.click()}
                                >
                                    <Icon name="upload-simple" size={14} />
                                    {auth?.user.avatar ? 'Change picture' : 'Upload picture'}
                                </Button>
                                {auth?.user.avatar && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        busy={avatarUploading}
                                        onClick={removeAvatar}
                                    >
                                        <Icon name="trash" size={14} />
                                        Remove
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-[var(--color-text-muted)]">
                                PNG, JPG up to 2MB. Square images work best.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Existing Form Fields */}
                <form onSubmit={submit} className="space-y-4">
                    <Field
                        label="Name" error={form.errors.name}
                        type="text"
                        value={form.data.name}
                        onChange={(event) => form.set('name', event.target.value)}
                        required
                    />

                    <Field
                        label="Email"
                        error={form.errors.email}
                        hint="Changing this means verifying the new address"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.set('email', event.target.value)}
                        required
                    />

                    <Field
                        label="Timezone" error={form.errors.timezone} hint="Dates are shown in this"
                        type="text"
                        value={form.data.timezone}
                        onChange={(event) => form.set('timezone', event.target.value)}
                        placeholder="Asia/Dhaka"
                    />

                    <Button type="submit" busy={form.processing}>
                        Save
                    </Button>
                </form>
            </div>
        </Card>
    );
}

// ── Password ─────────────────────────────────────────────────────────────────

function PasswordCard({ onSensitive }: { onSensitive: (error: unknown) => boolean }) {
    const { auth } = useSession();

    const form = useApiForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.patch<{ message: string }>('/auth/password', {
            reset: ['current_password', 'password', 'password_confirmation'],
            onSuccess: (result) => {
                toast.success(result.message);
            },
            onError: onSensitive,
        });
    };

    return (
        <Card
            title={auth?.user.has_password ? 'Change password' : 'Set a password'}
            description="Changing it signs out every other session but this one."
        >
            <form onSubmit={submit} className="space-y-4">
                {auth?.user.has_password && (
                    <Field
                    label="Current password" error={form.errors.current_password}
                    type="password"
                    value={form.data.current_password}
                    onChange={(event) => form.set('current_password', event.target.value)}
                    autoComplete="current-password"
                />
                )}

                <Field
                    label="New password" error={form.errors.password}
                    type="password"
                    value={form.data.password}
                    onChange={(event) => form.set('password', event.target.value)}
                    autoComplete="new-password"
                />

                <Field
                    label="Confirm it" error={form.errors.password_confirmation}
                    type="password"
                    value={form.data.password_confirmation}
                    onChange={(event) => form.set('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                />

                <Button type="submit" busy={form.processing}>
                    Update password
                </Button>
            </form>
        </Card>
    );
}

// ── Two-factor ───────────────────────────────────────────────────────────────

function TwoFactorCard({ onSensitive }: { onSensitive: (error: unknown) => boolean }) {
    const { auth, refresh } = useSession();
    const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);

    const confirmForm = useApiForm({ code: '' });

    const begin = async () => {
        setBusy(true);

        try {
            setSetup(await api.post<{ secret: string; qr: string }>('/auth/two-factor/enable'));
        } catch (error) {
            if (!onSensitive(error)) {
                toast.error(error instanceof ApiError ? error.message : 'Could not start.');
            }
        } finally {
            setBusy(false);
        }
    };

    const confirm = (event: React.FormEvent) => {
        event.preventDefault();

        void confirmForm.post<{ recovery_codes: string[] }>('/auth/two-factor/confirm', {
            reset: ['code'],
            onSuccess: async (result) => {
                setSetup(null);
                // Shown once, now. Nothing stored could produce them again —
                // they are hashed the moment they are saved.
                setCodes(result.recovery_codes);
                await refresh();
                toast.success('Two-factor is on.');
            },
            onError: onSensitive,
        });
    };

    const disable = async () => {
        if (!window.confirm('Turn off two-factor authentication?')) {
            return;
        }

        setBusy(true);

        try {
            await api.delete('/auth/two-factor');
            await refresh();
            toast.success('Two-factor is off.');
        } catch (error) {
            if (!onSensitive(error)) {
                toast.error('Could not turn it off.');
            }
        } finally {
            setBusy(false);
        }
    };

    if (codes) {
        return (
            <Card
                title="Save these recovery codes"
                description="Each works once, and only if you lose your authenticator. This is the only time they are shown."
            >
                <div className="grid grid-cols-2 gap-2 rounded-xl bg-[var(--color-brand-subtle)] p-4 font-[family-name:var(--font-mono)] text-sm">
                    {codes.map((code) => (
                        <span key={code}>{code}</span>
                    ))}
                </div>

                <div className="mt-4 flex gap-2">
                    <Button
                        variant="secondary"
                        onClick={() => {
                            void navigator.clipboard.writeText(codes.join('\n'));
                            toast.success('Copied.');
                        }}
                    >
                        <Icon name="copy" size={15} />
                        Copy
                    </Button>
                    <Button onClick={() => setCodes(null)}>I have saved them</Button>
                </div>
            </Card>
        );
    }

    if (setup) {
        return (
            <Card
                title="Scan this"
                description="Point your authenticator app at the code, then type what it shows."
            >
                <div
                    className="mx-auto w-fit rounded-xl bg-white p-3"
                    // The SVG is generated on the server, so the secret never
                    // has to reach a JavaScript QR library.
                    dangerouslySetInnerHTML={{ __html: setup.qr }}
                />

                <p className="mt-3 text-center text-xs text-[var(--color-text-muted)]">
                    Cannot scan? Enter this key by hand:
                </p>
                <p className="mt-1 text-center font-[family-name:var(--font-mono)] text-sm break-all">
                    {setup.secret}
                </p>

                <form onSubmit={confirm} className="mt-5 space-y-3">
                    <Field
                    label="Code from the app" error={confirmForm.errors.code}
                    type="text"
                    className="text-center font-[family-name:var(--font-mono)] tracking-[0.3em]"
                    value={confirmForm.data.code}
                    onChange={(event) =>
                    confirmForm.set('code', event.target.value.replace(/\D/g, '').slice(0, 6))
                    }
                    inputMode="numeric"
                    maxLength={6}
                    autoFocus
                />

                    <div className="flex gap-2">
                        <Button type="submit" busy={confirmForm.processing}>
                            Turn it on
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setSetup(null)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </Card>
        );
    }

    return (
        <Card
            title="Two-factor authentication"
            description="A code from your phone as well as your password."
        >
            {auth?.user.two_factor_enabled ? (
                <div className="flex flex-wrap items-center gap-3">
                    <span className="chip bg-[var(--color-success-subtle)] text-[var(--color-success)]">
                        <Icon name="check" size={12} weight="bold" />
                        On
                    </span>
                    <Button variant="danger" busy={busy} onClick={() => void disable()}>
                        Turn off
                    </Button>
                </div>
            ) : (
                <Button busy={busy} onClick={() => void begin()}>
                    <Icon name="shield-check" size={16} />
                    Turn on
                </Button>
            )}
        </Card>
    );
}

// ── Passkeys ─────────────────────────────────────────────────────────────────

function PasskeysCard({ onSensitive }: { onSensitive: (error: unknown) => boolean }) {
    const [busy, setBusy] = useState(false);

    const { data, refetch, isPending } = useQuery({
        queryKey: ['passkeys'],
        queryFn: ({ signal }) => api.get<{ data: Passkey[] }>('/passkeys', { signal }),
    });

    const add = async () => {
        setBusy(true);

        try {
            await registerPasskey();
            await refetch();
            toast.success('Passkey added.');
        } catch (error) {
            if (error instanceof DOMException && error.name === 'NotAllowedError') {
                return;
            }

            if (!onSensitive(error)) {
                toast.error(error instanceof ApiError ? error.message : 'Could not add it.');
            }
        } finally {
            setBusy(false);
        }
    };

    const remove = async (passkey: Passkey) => {
        if (!window.confirm(`Remove "${passkey.name}"?`)) {
            return;
        }

        try {
            await api.delete(`/passkeys/${passkey.id}`);
            await refetch();
            toast.success('Removed.');
        } catch (error) {
            if (!onSensitive(error)) {
                toast.error(error instanceof ApiError ? error.message : 'Could not remove it.');
            }
        }
    };

    const passkeys = data?.data ?? [];

    return (
        <Card
            title="Passkeys"
            description="Sign in with a fingerprint, a face or a device PIN. Nothing to type, and nothing a fake sign-in page can capture."
        >
            {isPending ? (
                <p className="text-sm text-[var(--color-text-muted)]">Loading…</p>
            ) : passkeys.length === 0 ? (
                <p className="text-sm text-[var(--color-text-muted)]">No passkeys yet.</p>
            ) : (
                <ul className="divide-y divide-[var(--color-border-light)]">
                    {passkeys.map((passkey) => (
                        <li key={passkey.id} className="flex items-center gap-3 py-3">
                            <Icon
                                name="fingerprint"
                                size={18}
                                className="flex-none text-[var(--color-text-muted)]"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">{passkey.name}</span>
                                {passkey.created_at && (
                                    <span className="block text-xs text-[var(--color-text-muted)]">
                                        Added {new Date(passkey.created_at).toLocaleDateString()}
                                    </span>
                                )}
                            </span>
                            <button
                                type="button"
                                onClick={() => void remove(passkey)}
                                className="flex-none rounded-lg p-1.5 text-[var(--color-danger-text)] hover:bg-[var(--color-danger-subtle)]"
                                aria-label={`Remove ${passkey.name}`}
                            >
                                <Icon name="trash" size={16} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <Button variant="secondary" busy={busy} onClick={() => void add()} className="mt-4">
                <Icon name="plus" size={15} />
                Add a passkey
            </Button>
        </Card>
    );
}

// ── Danger Zone ──────────────────────────────────────────────────────────────

function DangerZoneCard() {
    const { auth } = useSession();
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [confirmation, setConfirmation] = useState('');
    const [reason, setReason] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleDelete = async () => {
        setErrors({});

        if (confirmation !== 'DELETE') {
            setErrors({ confirmation: 'You must type DELETE to confirm' });
            return;
        }

        setDeleting(true);

        try {
            console.log('Attempting to delete account with data:', {
                confirmation,
                reason: reason.trim() || undefined,
            });

            const result = await api.delete<{ message: string }>('/profile/account', {
                confirmation,
                reason: reason.trim() || undefined,
            });

            console.log('Delete successful:', result);
            toast.success(result.message);
            
            // Wait a moment for the message to be seen, then redirect to login
            setTimeout(() => {
                window.location.href = '/login?deleted=1';
            }, 2000);
        } catch (error) {
            console.error('Delete failed:', error);
            const apiError = error as { message?: string; errors?: Record<string, string[]> };
            
            if (apiError.errors) {
                const formattedErrors: Record<string, string> = {};
                Object.entries(apiError.errors).forEach(([key, messages]) => {
                    if (messages[0]) formattedErrors[key] = messages[0];
                });
                setErrors(formattedErrors);
                toast.error('Validation failed. Please check the form.');
            } else {
                toast.error(apiError.message ?? 'Failed to delete account.');
            }
            setDeleting(false);
        }
    };

    // Only show for account owners
    if (!auth?.user.is_owner) {
        return null;
    }

    return (
        <>
            <section 
                className="card p-6"
                style={{ 
                    borderColor: 'var(--color-danger)', 
                    background: 'var(--color-danger-subtle)' 
                }}
            >
                <div className="flex items-start gap-3">
                    <Icon 
                        name="warning-diamond" 
                        size={24} 
                        className="flex-shrink-0 text-[var(--color-danger)]" 
                    />
                    <div className="flex-1">
                        <h2 className="font-[family-name:var(--font-heading)] text-lg font-bold text-[var(--color-danger)]">
                            Danger Zone
                        </h2>
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                            Irreversible actions that affect your entire account
                        </p>
                        
                        <div className="mt-5 rounded-lg border-2 border-[var(--color-danger)] bg-[var(--color-card-bg)] p-4" style={{ borderRadius: 'var(--shell-radius)' }}>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="flex-1 min-w-0">
                                    <h3 className="text-sm font-semibold text-[var(--color-text-main)]">
                                        Delete this account
                                    </h3>
                                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                        Once deleted, your account will be suspended immediately and permanently 
                                        removed after 60 days. All workspaces, businesses, and data will be deleted. 
                                        Any active subscription will be cancelled.
                                    </p>
                                    <p className="mt-2 text-sm font-medium text-[var(--color-text-main)]">
                                        This action can be reversed within 60 days by contacting support.
                                    </p>
                                </div>
                                <Button 
                                    variant="danger"
                                    onClick={() => setShowDeleteModal(true)}
                                >
                                    <Icon name="trash" size={16} />
                                    Delete account
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Delete Confirmation Modal */}
            {showDeleteModal && (
                <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div 
                        className="flex w-full max-w-xl flex-col bg-[var(--color-card-bg)] shadow-2xl"
                        style={{ 
                            borderRadius: 'var(--shell-radius)',
                            maxHeight: 'calc(100vh - 2rem)'
                        }}
                    >
                        {/* Header */}
                        <div className="flex-shrink-0 border-b border-[var(--color-border-light)] px-8 py-6">
                            <div className="flex items-start gap-4">
                                <div 
                                    className="flex h-14 w-14 shrink-0 items-center justify-center bg-[var(--color-danger-subtle)]"
                                    style={{ borderRadius: 'var(--shell-radius)' }}
                                >
                                    <Icon name="warning-diamond" size={28} className="text-[var(--color-danger)]" />
                                </div>
                                <div className="flex-1">
                                    <h2 className="font-[family-name:var(--font-heading)] text-xl font-bold text-[var(--color-text-main)]">
                                        Delete your account
                                    </h2>
                                    <p className="mt-1.5 text-sm text-[var(--color-text-muted)]">
                                        This action is serious but reversible within 60 days
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Content */}
                        <div className="flex-1 overflow-y-auto px-8 py-6">
                            <div className="space-y-6">
                                {/* Warning Box */}
                                <div 
                                    className="border-l-4 border-[var(--color-danger)] bg-[var(--color-danger-subtle)] p-5"
                                    style={{ borderRadius: 'var(--shell-radius)' }}
                                >
                                    <h3 className="text-sm font-semibold text-[var(--color-danger)]">
                                        What will happen
                                    </h3>
                                    <ul className="mt-3 space-y-2.5">
                                        <li className="flex items-start gap-3 text-sm text-[var(--color-text-body)]">
                                            <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-danger)] bg-opacity-10">
                                                <div className="h-1.5 w-1.5 rounded-full bg-[var(--color-danger)]" />
                                            </div>
                                            <span>Your account will be immediately suspended and inaccessible</span>
                                        </li>
                                        <li className="flex items-start gap-3 text-sm text-[var(--color-text-body)]">
                                            <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-danger)] bg-opacity-10">
                                                <div className="h-1.5 w-1.5 rounded-full bg-[var(--color-danger)]" />
                                            </div>
                                            <span>All active subscriptions will be cancelled</span>
                                        </li>
                                        <li className="flex items-start gap-3 text-sm text-[var(--color-text-body)]">
                                            <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-danger)] bg-opacity-10">
                                                <div className="h-1.5 w-1.5 rounded-full bg-[var(--color-danger)]" />
                                            </div>
                                            <span>All workspaces, businesses, and data will be marked for deletion</span>
                                        </li>
                                        <li className="flex items-start gap-3 text-sm text-[var(--color-text-body)]">
                                            <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-danger)] bg-opacity-10">
                                                <div className="h-1.5 w-1.5 rounded-full bg-[var(--color-danger)]" />
                                            </div>
                                            <span>After 60 days, everything will be permanently deleted</span>
                                        </li>
                                    </ul>
                                </div>

                                {/* Recovery Notice */}
                                <div 
                                    className="flex gap-3 border border-[var(--color-border-light)] bg-[var(--color-success-subtle)] p-4"
                                    style={{ borderRadius: 'var(--shell-radius)' }}
                                >
                                    <Icon name="arrow-counter-clockwise" size={20} className="shrink-0 text-[var(--color-success)]" />
                                    <div className="flex-1">
                                        <p className="text-sm font-medium text-[var(--color-text-main)]">
                                            Recovery period available
                                        </p>
                                        <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                            You have 60 days to contact support and request account restoration
                                        </p>
                                    </div>
                                </div>

                                {/* Reason Field */}
                                <div className="space-y-2">
                                    <label className="block text-sm font-medium text-[var(--color-text-main)]">
                                        Help us improve (optional)
                                    </label>
                                    <textarea
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        placeholder="What made you decide to leave? Your feedback helps us improve..."
                                        rows={3}
                                        maxLength={500}
                                        className="field-input w-full resize-none text-sm"
                                        style={{ borderRadius: 'var(--shell-radius)' }}
                                    />
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs text-[var(--color-text-subtle)]">
                                            Your feedback is valuable to us
                                        </p>
                                        <p className="text-xs text-[var(--color-text-subtle)]">
                                            {reason.length}/500
                                        </p>
                                    </div>
                                </div>

                                {/* Confirmation Field */}
                                <div className="space-y-2">
                                    <label className="block text-sm font-medium text-[var(--color-text-main)]">
                                        Type <span className="font-mono font-bold text-[var(--color-danger)]">DELETE</span> to confirm
                                    </label>
                                    <input
                                        type="text"
                                        value={confirmation}
                                        onChange={(e) => setConfirmation(e.target.value.toUpperCase())}
                                        placeholder="Type DELETE here"
                                        className="field-input w-full font-mono text-base tracking-wider"
                                        style={{ borderRadius: 'var(--shell-radius)' }}
                                        autoComplete="off"
                                        autoFocus
                                    />
                                    {errors.confirmation && (
                                        <div className="flex items-center gap-2 text-xs font-medium text-red-600">
                                            <Icon name="warning-circle" size={14} />
                                            <span>{errors.confirmation}</span>
                                        </div>
                                    )}
                                    {confirmation && confirmation !== 'DELETE' && (
                                        <p className="text-xs text-[var(--color-text-muted)]">
                                            Keep typing... ({confirmation.length}/6)
                                        </p>
                                    )}
                                    {confirmation === 'DELETE' && (
                                        <div className="flex items-center gap-2 text-xs font-medium text-[var(--color-success)]">
                                            <Icon name="check-circle" size={14} />
                                            <span>Confirmation complete</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Footer */}
                        <div className="flex flex-shrink-0 items-center justify-between gap-3 border-t border-[var(--color-border-light)] bg-[var(--color-card-raised)] px-8 py-5">
                            <Button
                                variant="ghost"
                                size="lg"
                                onClick={() => {
                                    setShowDeleteModal(false);
                                    setConfirmation('');
                                    setReason('');
                                    setErrors({});
                                }}
                                disabled={deleting}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="danger"
                                size="lg"
                                busy={deleting}
                                disabled={confirmation !== 'DELETE' || deleting}
                                onClick={handleDelete}
                            >
                                <Icon name="trash" size={18} />
                                {deleting ? 'Deleting account...' : 'Delete my account'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ── Shared ───────────────────────────────────────────────────────────────────

function Card({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="card p-6">
            <h2 className="font-[family-name:var(--font-heading)] text-lg font-bold">{title}</h2>
            {description && (
                <p className="mt-1 text-sm text-[var(--color-text-muted)]">{description}</p>
            )}
            <div className="mt-5">{children}</div>
        </section>
    );
}
