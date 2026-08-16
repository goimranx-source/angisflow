import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import type { MediaItem } from '@/types/settings';

/**
 * One file, in full — the panel a click on a tile opens.
 *
 * A grid answers "which file". It cannot answer "how big is it", "what is its
 * alt text", or "where does it actually live" without either crowding every
 * tile with text or making somebody guess. Every serious media library — WordPress,
 * Google Drive, a DAM — puts that behind a details panel rather than on the
 * tile itself, and this follows the same shape.
 */
export function MediaDetail({
    item,
    onClose,
    onUpdated,
    onDeleted,
}: {
    item: MediaItem;
    onClose: () => void;
    onUpdated: (item: MediaItem) => void;
    onDeleted: (id: string) => void;
}) {
    const [title, setTitle] = useState(item.name);
    const [alt, setAlt] = useState(item.alt_text ?? '');
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [copied, setCopied] = useState(false);

    // Reset the fields when a different tile is opened, rather than carrying
    // over what was typed into the last one.
    useEffect(() => {
        setTitle(item.name);
        setAlt(item.alt_text ?? '');
    }, [item.id, item.name, item.alt_text]);

    const dirty = title !== item.name || alt !== (item.alt_text ?? '');

    const save = async () => {
        setSaving(true);

        try {
            const result = await api.patch<{ data: MediaItem }>(`/media/${item.id}`, {
                title,
                alt_text: alt || null,
            });

            onUpdated(result.data);
            toast.success('Saved.');
        } catch {
            toast.error('That could not be saved.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        if (!window.confirm(`Remove "${item.name}" from the library? This cannot be undone.`)) {
            return;
        }

        setDeleting(true);

        try {
            await api.delete(`/media/${item.id}`);
            onDeleted(item.id);
            toast.success('Removed.');
        } catch {
            toast.error('That could not be removed.');
        } finally {
            setDeleting(false);
        }
    };

    const copyUrl = () => {
        void navigator.clipboard.writeText(new URL(item.url, window.location.origin).toString());
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between border-b border-[var(--color-border-light)] px-4 py-3">
                <h3 className="truncate text-sm font-semibold text-[var(--color-text-main)]">
                    File details
                </h3>
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)]"
                    aria-label="Close details"
                >
                    <Icon name="x" size={16} />
                </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-4">
                {/* The preview, shown at its real proportions rather than
                    cropped to a square — a details panel is where you check
                    whether an image is actually the right shape. */}
                <div className="grid max-h-56 place-items-center overflow-hidden rounded-xl border border-[var(--color-border-light)] bg-[var(--color-brand-subtle)] bg-[linear-gradient(45deg,rgba(0,0,0,0.03)_25%,transparent_25%,transparent_75%,rgba(0,0,0,0.03)_75%),linear-gradient(45deg,rgba(0,0,0,0.03)_25%,transparent_25%,transparent_75%,rgba(0,0,0,0.03)_75%)] bg-[length:16px_16px] bg-[position:0_0,8px_8px] p-3">
                    {item.is_image ? (
                        <img src={item.url} alt={item.alt_text ?? ''} className="max-h-48 max-w-full object-contain" />
                    ) : (
                        <div className="flex flex-col items-center gap-2 py-6">
                            <Icon name="file-magnifying-glass" size={36} className="text-[var(--color-text-subtle)]" />
                            <span className="text-xs font-semibold text-[var(--color-text-muted)]">
                                {item.extension}
                            </span>
                        </div>
                    )}
                </div>

                <dl className="mt-4 space-y-2 text-xs">
                    <Row label="Original name" value={item.original_name} mono />
                    <Row label="Type" value={item.mime_type} mono />
                    <Row label="Size" value={item.readable_size} />
                    {item.width && item.height && (
                        <Row label="Dimensions" value={`${item.width} × ${item.height}px`} />
                    )}
                    {item.uploaded_at && (
                        <Row label="Uploaded" value={new Date(item.uploaded_at).toLocaleString()} />
                    )}
                </dl>

                <div className="mt-5 space-y-3">
                    <label className="block">
                        <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                            Title
                        </span>
                        <input
                            type="text"
                            className="field"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            maxLength={120}
                        />
                    </label>

                    {item.is_image && (
                        <label className="block">
                            <span className="mb-1.5 block text-[0.8125rem] font-semibold text-[var(--color-text-main)]">
                                Alt text
                            </span>
                            <textarea
                                className="field resize-none"
                                rows={2}
                                value={alt}
                                onChange={(event) => setAlt(event.target.value)}
                                maxLength={200}
                                placeholder="Describes the image for screen readers and search"
                            />
                        </label>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="button" size="sm" busy={saving} disabled={!dirty} onClick={() => void save()}>
                            <Icon name="check" size={14} weight="bold" />
                            Save
                        </Button>
                        <Button type="button" variant="secondary" size="sm" onClick={copyUrl}>
                            <Icon name={copied ? 'check' : 'copy'} size={14} weight="regular" />
                            {copied ? 'Copied' : 'Copy URL'}
                        </Button>
                    </div>
                </div>
            </div>

            <div className="border-t border-[var(--color-border-light)] p-4">
                <Button
                    type="button"
                    variant="danger"
                    size="sm"
                    busy={deleting}
                    onClick={() => void remove()}
                    className="w-full"
                >
                    <Icon name="trash" size={14} />
                    Remove from library
                </Button>
            </div>
        </div>
    );
}

function Row({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="flex-none text-[var(--color-text-muted)]">{label}</dt>
            <dd
                className={`min-w-0 flex-1 truncate text-right text-[var(--color-text-body)] ${mono ? 'font-[family-name:var(--font-mono)]' : ''}`}
                title={value}
            >
                {value}
            </dd>
        </div>
    );
}
