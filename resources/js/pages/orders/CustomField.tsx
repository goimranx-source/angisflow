import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';

import { controlFor } from './fieldTypes';

export type CustomFieldDef = {
    key: string;
    label: string;
    type: string;
    type_label?: string;
};

/**
 * One of a business's own fields, drawn according to its type.
 *
 * ── Why one component and not a form built per business ──────────────────────
 *
 * Because the fields are data, not code. Somebody adds "Gift message" as rich
 * text and "Proof of delivery" as an image, and both have to appear, correctly,
 * without this file learning their names. Everything here branches on the type
 * alone.
 */
export function CustomField({
    field,
    value,
    onChange,
}: {
    field: CustomFieldDef;
    value: unknown;
    onChange: (next: unknown) => void;
}) {
    const control = controlFor(field.type);
    const [uploading, setUploading] = useState(false);

    const text = value === null || value === undefined ? '' : String(value);

    /*
     * Media goes through the library, like every other file in the application:
     * it gets a checksum, a thumbnail and a URL that survives a change of disk.
     * A field holding a pasted URL would have none of that.
     */
    const upload = async (file: File): Promise<void> => {
        setUploading(true);

        try {
            const body = new FormData();
            body.append('file', file);

            const result = (await api.post('/media', body)) as { data: { url: string } };

            if (control === 'gallery') {
                const existing = Array.isArray(value) ? (value as string[]) : [];

                onChange([...existing, result.data.url]);
            } else {
                onChange(result.data.url);
            }
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'That file could not be uploaded.');
        } finally {
            setUploading(false);
        }
    };

    const id = `custom-${field.key}`;

    const label = (
        <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
            {field.label}
        </label>
    );

    // ── Media ───────────────────────────────────────────────────────────────

    if (control === 'image' || control === 'file') {
        const isImage = control === 'image';

        return (
            <div>
                {label}

                <div className="rounded-[var(--shell-radius)] border border-dashed border-[var(--shell-border)] p-3">
                    {text ? (
                        isImage ? (
                            <img
                                src={text}
                                alt=""
                                className="mb-2 max-h-40 w-full rounded-[var(--shell-radius-sm)] object-contain"
                            />
                        ) : (
                            <a
                                href={text}
                                target="_blank"
                                rel="noreferrer"
                                className="mb-2 flex items-center gap-2 text-sm text-[var(--color-brand)] hover:underline"
                            >
                                <Icon name="paperclip" size={15} />
                                <span className="truncate">{text.split('/').pop()}</span>
                            </a>
                        )
                    ) : (
                        <p className="mb-2 text-center text-xs text-[var(--color-text-subtle)]">
                            Nothing attached
                        </p>
                    )}

                    <div className="flex items-center gap-2">
                        <label className="btn btn-secondary cursor-pointer text-sm">
                            {uploading ? 'Uploading…' : text ? 'Replace' : 'Upload'}
                            <input
                                type="file"
                                accept={isImage ? 'image/*' : undefined}
                                className="hidden"
                                disabled={uploading}
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    event.target.value = '';

                                    if (file) {
                                        void upload(file);
                                    }
                                }}
                            />
                        </label>

                        {text && (
                            <button
                                type="button"
                                className="text-sm text-[var(--color-text-muted)] hover:text-[var(--color-danger)]"
                                onClick={() => onChange(null)}
                            >
                                Remove
                            </button>
                        )}
                    </div>
                </div>
            </div>
        );
    }

    if (control === 'gallery') {
        const items = Array.isArray(value) ? (value as string[]) : [];

        return (
            <div>
                {label}

                <div className="rounded-[var(--shell-radius)] border border-dashed border-[var(--shell-border)] p-3">
                    {items.length > 0 ? (
                        <div className="mb-2 grid grid-cols-3 gap-2">
                            {items.map((src, index) => (
                                <div key={`${src}-${index}`} className="group relative">
                                    <img
                                        src={src}
                                        alt=""
                                        className="h-20 w-full rounded-[var(--shell-radius-sm)] border border-[var(--shell-border)] object-cover"
                                    />
                                    <button
                                        type="button"
                                        aria-label="Remove"
                                        className="absolute right-1 top-1 rounded-full bg-white/90 p-0.5 text-[var(--color-text-muted)] shadow hover:text-[var(--color-danger)]"
                                        onClick={() => onChange(items.filter((_, i) => i !== index))}
                                    >
                                        <Icon name="x" size={11} />
                                    </button>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="mb-2 text-center text-xs text-[var(--color-text-subtle)]">No images</p>
                    )}

                    <label className="btn btn-secondary cursor-pointer text-sm">
                        {uploading ? 'Uploading…' : 'Add image'}
                        <input
                            type="file"
                            accept="image/*"
                            className="hidden"
                            disabled={uploading}
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                event.target.value = '';

                                if (file) {
                                    void upload(file);
                                }
                            }}
                        />
                    </label>
                </div>
            </div>
        );
    }

    if (control === 'video') {
        return (
            <div>
                {label}
                <input
                    id={id}
                    type="url"
                    className="field w-full"
                    placeholder="https://…"
                    value={text}
                    onChange={(event) => onChange(event.target.value || null)}
                />
                {text && (
                    <a
                        href={text}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-1.5 inline-flex items-center gap-1.5 text-xs text-[var(--color-brand)] hover:underline"
                    >
                        <Icon name="play-circle" size={13} />
                        Open video
                    </a>
                )}
            </div>
        );
    }

    // ── Everything else ─────────────────────────────────────────────────────

    if (control === 'switch') {
        return (
            <label className="flex items-center gap-2.5 pt-6">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={value === true || value === 'true' || value === 1}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className="text-sm font-medium text-[var(--color-text-main)]">{field.label}</span>
            </label>
        );
    }

    if (control === 'textarea' || control === 'code' || control === 'lines') {
        return (
            <div>
                {label}
                <textarea
                    id={id}
                    className={`field w-full ${control === 'code' ? 'font-mono text-xs' : ''}`}
                    rows={control === 'code' ? 6 : 4}
                    value={
                        control === 'lines' && Array.isArray(value) ? (value as string[]).join('\n') : text
                    }
                    placeholder={control === 'lines' ? 'One per line' : undefined}
                    onChange={(event) => {
                        const raw = event.target.value;

                        if (control === 'lines') {
                            // Split on save rather than on every keystroke, or a
                            // trailing newline would vanish as it is typed.
                            onChange(raw === '' ? [] : raw.split('\n'));

                            return;
                        }

                        onChange(raw || null);
                    }}
                />
            </div>
        );
    }

    const inputType =
        control === 'number' || control === 'money'
            ? 'number'
            : control === 'colour'
              ? 'color'
              : control === 'datetime'
                ? 'datetime-local'
                : control;

    return (
        <div>
            {label}
            <input
                id={id}
                type={inputType}
                step={control === 'money' || control === 'number' ? 'any' : undefined}
                className="field w-full"
                value={text}
                onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
            />
        </div>
    );
}
