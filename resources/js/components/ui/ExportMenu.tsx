import { useEffect, useRef, useState } from 'react';

import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { Icon } from '@/components/ui/Icon';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ExportFormat = 'pdf' | 'excel';

type ExportMenuProps = {
    /** API path, without the /api/v1 prefix — '/dashboard-export'. */
    endpoint: string;
    /** Extra query parameters: the date range, filters, the open business. */
    params?: Record<string, string | number | undefined>;
    className?: string;
};

const FORMATS: Array<{ key: ExportFormat; label: string; icon: string }> = [
    { key: 'pdf', label: 'Export as PDF', icon: 'file-dashed' },
    { key: 'excel', label: 'Export as Excel', icon: 'columns' },
];

/**
 * Download this screen as a file.
 *
 * ── Why this fetches rather than linking ─────────────────────────────────────
 *
 * An <a download> pointed at the endpoint would be simpler and is wrong here in
 * three ways: a failure renders the error JSON into the tab the user was
 * reading, there is nowhere to show that a large report is still being built,
 * and the request would carry no XSRF token. Fetching means a failure is a
 * toast, the button can say it is working, and the file is only handed over
 * once the server has actually produced one.
 *
 * The blob URL is revoked on the next tick rather than immediately — Safari
 * abandons the download if the object URL disappears in the same frame the
 * click was dispatched.
 */
export function ExportMenu({ endpoint, params = {}, className }: ExportMenuProps) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState<ExportFormat | null>(null);
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (event: MouseEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const download = async (format: ExportFormat) => {
        setBusy(format);

        try {
            const url = new URL(`/api/v1${endpoint}`, window.location.origin);
            url.searchParams.set('format', format);

            for (const [key, value] of Object.entries(params)) {
                if (value !== undefined && value !== '') {
                    url.searchParams.set(key, String(value));
                }
            }

            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            // The server names the file — it knows the business, the period and
            // the extension. Falling back to a generic name only if it did not.
            const disposition = response.headers.get('Content-Disposition') ?? '';
            const match = /filename="?([^"]+)"?/.exec(disposition);
            const filename = match?.[1] ?? `export.${format === 'pdf' ? 'pdf' : 'xls'}`;

            const blob = await response.blob();
            const href = URL.createObjectURL(blob);
            const anchor = document.createElement('a');

            anchor.href = href;
            anchor.download = filename;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();

            setTimeout(() => URL.revokeObjectURL(href), 0);

            toast.success(`${filename} downloaded.`);
            setOpen(false);
        } catch {
            toast.error('That export could not be produced.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <div ref={container} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                disabled={busy !== null}
                aria-expanded={open}
                className={cn(
                    'flex h-9 items-center gap-2 rounded-[var(--shell-radius)] border px-3 text-xs font-semibold transition-colors',
                    open
                        ? 'border-[var(--color-brand)] bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                        : 'border-[var(--shell-border)] bg-[var(--shell-bg)] text-[var(--shell-text-strong)] hover:bg-[var(--shell-hover)]',
                    busy !== null && 'opacity-60',
                )}
            >
                <Icon
                    name={busy ? 'circle-notch' : 'download-simple'}
                    size={15}
                    className={cn(busy && 'animate-spin')}
                />
                <span>{busy ? 'Preparing…' : 'Export'}</span>
                <Icon name="caret-down" size={12} />
            </button>

            {open && <FlyoutBackdrop onClose={() => setOpen(false)} />}

            {open && (
                <div className="absolute right-0 z-[var(--z-flyout-panel)] mt-1.5 w-48 overflow-hidden rounded-[var(--shell-radius)] border border-[var(--shell-border)] bg-[var(--shell-bg)] p-1 shadow-[var(--shadow-lg)]">
                    {FORMATS.map((format) => (
                        <button
                            key={format.key}
                            type="button"
                            onClick={() => void download(format.key)}
                            disabled={busy !== null}
                            className="flex w-full items-center gap-2.5 rounded-[var(--shell-radius-sm)] px-2.5 py-2 text-left text-xs font-medium text-[var(--shell-text-strong)] transition-colors hover:bg-[var(--shell-hover)] disabled:opacity-50"
                        >
                            <Icon name={format.icon} size={15} className="text-[var(--color-text-muted)]" />
                            {format.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
