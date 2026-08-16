import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

import { MediaPicker } from '@/components/media/MediaPicker';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Icon } from '@/components/ui/Icon';
import { useApiForm } from '@/hooks/useApiForm';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';
import type { MediaItem, SettingsPanel } from '@/types/settings';

import { SettingsCard } from './SettingsCard';

/**
 * The body this panel sends.
 *
 * Short names, not "appearance.brand_name": the group is in the URL, and a dot
 * in a field name is read by the validator as a path into a nested array — so
 * the prefixed form validates against nothing and saves nothing. See
 * SettingsRegistry::shortKey().
 */
type Values = {
    'brand_name': string;
    'tagline': string;
    'logo': string;
    'logo_mark': string;
    'favicon': string;
    'show_logo': boolean;
};

/**
 * What the tool calls itself, and the marks it wears.
 *
 * Images are *chosen from the library* rather than uploaded here. A file input
 * on each slot leaves the same mark on disk four times under four names, with
 * nothing saying which of them anything is using — which is exactly what the
 * library exists to stop.
 */
export default function AppearancePanel() {
    const { apply } = useSession();
    const [picking, setPicking] = useState<keyof Values | null>(null);

    const { data, isPending, refetch } = useQuery({
        queryKey: ['settings', 'appearance'],
        queryFn: ({ signal }) => api.get<{ data: SettingsPanel }>('/settings/appearance', { signal }),
    });

    const form = useApiForm<Values>({
        'brand_name': '',
        'tagline': '',
        'logo': '',
        'logo_mark': '',
        'favicon': '',
        'show_logo': true,
    });

    // Seeded once the server answers. `setData` rather than a key on the hook,
    // so a half-typed form is not thrown away by a background refetch.
    const values = data?.data.values;

    useEffect(() => {
        if (!values) {
            return;
        }

        form.setData({
            'brand_name': String(values['brand_name'] ?? ''),
            'tagline': String(values['tagline'] ?? ''),
            'logo': String(values['logo'] ?? ''),
            'logo_mark': String(values['logo_mark'] ?? ''),
            'favicon': String(values['favicon'] ?? ''),
            'show_logo': Boolean(values['show_logo']),
        });
        // Only when the server's copy changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values]);

    const urls: Record<string, string | null> = {
        'logo': (values?.['logo:url'] as string | null) ?? null,
        'logo_mark': (values?.['logo_mark:url'] as string | null) ?? null,
        'favicon': (values?.['favicon:url'] as string | null) ?? null,
    };

    // Picked in this session but not yet saved — so the preview updates the
    // moment something is chosen rather than after a save and a refetch.
    const [previews, setPreviews] = useState<Record<string, string>>({});

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        void form.patch<{ message: string; boot: BootPayload }>('/settings/appearance', {
            onSuccess: async (result) => {
                // The sidebar and the browser tab read this, so the shell is
                // replaced from the save rather than re-fetched.
                apply(result.boot);
                setPreviews({});
                await refetch();
                toast.success(result.message);
            },
        });
    };

    const choose = (item: MediaItem) => {
        if (!picking) {
            return;
        }

        form.set(picking, item.id as never);
        setPreviews((current) => ({ ...current, [picking]: item.url }));
        setPicking(null);
    };

    if (isPending) {
        return <PanelSkeleton />;
    }

    return (
        <>
            <form onSubmit={submit} className="space-y-5">
                <SettingsCard
                    title="Naming"
                    blurb="What the tool calls itself, in the sidebar and on the browser tab."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Name"
                            error={form.errors['brand_name']}
                            hint="Left blank, the platform name is used."
                            type="text"
                            value={form.data['brand_name']}
                            onChange={(e) => form.set('brand_name', e.target.value)}
                            placeholder="Prism"
                        />
                        <Field
                            label="Tagline"
                            error={form.errors['tagline']}
                            hint="Optional. Shown under the name on the sign-in screen."
                            type="text"
                            value={form.data['tagline']}
                            onChange={(e) => form.set('tagline', e.target.value)}
                        />
                    </div>
                </SettingsCard>

                <SettingsCard
                    title="Marks"
                    blurb="Chosen from the library, so the same image used in three places is one file."
                >
                    <div className="space-y-4">
                        <MediaSlot
                            label="Sidebar logo"
                            help="Shown at the top of the sidebar when it is open. A wide mark works best."
                            url={previews['logo'] ?? urls['logo'] ?? null}
                            wide
                            onChoose={() => setPicking('logo')}
                            onClear={() => {
                                form.set('logo', '');
                                setPreviews((c) => ({ ...c, 'logo': '' }));
                            }}
                        />
                        <MediaSlot
                            label="Collapsed mark"
                            help="The square badge shown when the sidebar is narrowed to icons."
                            url={previews['logo_mark'] ?? urls['logo_mark'] ?? null}
                            onChoose={() => setPicking('logo_mark')}
                            onClear={() => {
                                form.set('logo_mark', '');
                                setPreviews((c) => ({ ...c, 'logo_mark': '' }));
                            }}
                        />
                        <MediaSlot
                            label="Favicon"
                            help="The icon on the browser tab. Square, 32×32 or larger."
                            url={previews['favicon'] ?? urls['favicon'] ?? null}
                            favicon
                            onChoose={() => setPicking('favicon')}
                            onClear={() => {
                                form.set('favicon', '');
                                setPreviews((c) => ({ ...c, 'favicon': '' }));
                            }}
                        />
                    </div>
                </SettingsCard>

                <SettingsCard title="Sidebar">
                    <label className="flex cursor-pointer items-start gap-2.5 text-sm text-[var(--color-text-body)]">
                        <input
                            type="checkbox"
                            checked={form.data['show_logo']}
                            onChange={(e) => form.set('show_logo', e.target.checked)}
                            className="mt-0.5 size-4 rounded"
                            style={{ accentColor: 'var(--color-ink)' }}
                        />
                        <span>
                            Show the logo instead of the name
                            <span className="block text-xs text-[var(--color-text-muted)]">
                                Turn this off, or leave the logo unset, and the sidebar shows the name as text.
                            </span>
                        </span>
                    </label>
                </SettingsCard>

                {form.message && (
                    <p className="text-sm text-[var(--color-danger-text)]">{form.message}</p>
                )}

                <Button type="submit" busy={form.processing}>
                    <Icon name="check" size={15} weight="bold" />
                    Save appearance
                </Button>
            </form>

            {picking && (
                <PickerDialog
                    selectedId={String(form.data[picking] ?? '')}
                    onPick={choose}
                    onClose={() => setPicking(null)}
                />
            )}
        </>
    );
}

// ── Bits ─────────────────────────────────────────────────────────────────────

function MediaSlot({
    label,
    help,
    url,
    wide = false,
    favicon = false,
    onChoose,
    onClear,
}: {
    label: string;
    help: string;
    url: string | null;
    wide?: boolean;
    favicon?: boolean;
    onChoose: () => void;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <div
                className="grid flex-none place-items-center overflow-hidden rounded-lg border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)]"
                style={{ width: wide ? 96 : 56, height: 56 }}
            >
                {url ? (
                    <img src={url} alt="" className="max-h-12 max-w-full object-contain" />
                ) : (
                    <Icon name="images" size={20} className="text-[var(--color-text-subtle)]" />
                )}
            </div>

            {/* A favicon is drawn at sixteen pixels whatever it started as. A
                mark that reads beautifully at two hundred can be an
                unrecognisable smudge there, and the only way to know is to
                look at it that size. */}
            {favicon && url && (
                <div className="flex flex-none items-center gap-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] px-3 py-2">
                    <img src={url} alt="" width={16} height={16} style={{ objectFit: 'contain' }} />
                    <img src={url} alt="" width={32} height={32} style={{ objectFit: 'contain' }} />
                    <span className="text-[0.625rem] text-[var(--color-text-muted)]">16 · 32</span>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <p className="text-[0.8125rem] font-semibold text-[var(--color-text-main)]">{label}</p>
                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">{help}</p>

                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Button type="button" variant="secondary" size="sm" onClick={onChoose}>
                        <Icon name="images" size={14} weight="regular" />
                        Choose
                    </Button>
                    {url && (
                        <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                            Remove
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

function PickerDialog({
    selectedId,
    onPick,
    onClose,
}: {
    selectedId: string;
    onPick: (item: MediaItem) => void;
    onClose: () => void;
}) {
    // Escape closes it. A dialog that can only be dismissed by finding the
    // right button is a dialog people feel trapped by.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-[rgba(13,27,42,0.55)] p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-label="Choose an image"
        >
            <div
                className="card flex max-h-[80vh] w-full max-w-3xl flex-col p-5"
                onClick={(event) => event.stopPropagation()}
            >
                <h2 className="mb-1 text-lg font-bold">Choose an image</h2>
                <p className="mb-3 text-sm text-[var(--color-text-muted)]">
                    Anything here can be used anywhere in the tool.
                </p>

                <MediaPicker imagesOnly selectedId={selectedId} onPick={onPick} onClose={onClose} />
            </div>
        </div>
    );
}

function PanelSkeleton() {
    return (
        <div className="space-y-5">
            {[0, 1, 2].map((i) => (
                <div key={i} className="card p-5">
                    <div className="h-4 w-32 animate-pulse rounded bg-[var(--color-brand-subtle)]" />
                    <div className="mt-4 h-10 animate-pulse rounded bg-[var(--color-brand-subtle)]" />
                </div>
            ))}
        </div>
    );
}
