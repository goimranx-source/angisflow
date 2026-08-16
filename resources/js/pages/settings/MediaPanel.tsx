import { MediaPicker } from '@/components/media/MediaPicker';

/**
 * The library itself, not a second copy of it.
 *
 * This tab and the picker that opens over other screens are the same component,
 * so the upload path, the search and the paging are fixed once rather than
 * twice — and cannot drift apart, which is exactly what happened in the first
 * version of Prism.
 */
export default function MediaPanel() {
    return (
        <div className="card p-5">
            <p className="mb-4 text-sm text-[var(--color-text-muted)]">
                Everything here can be picked from anywhere in the tool — a logo, a product picture, a
                document to link to. Upload once; use it wherever it is needed.
            </p>

            <MediaPicker />
        </div>
    );
}
