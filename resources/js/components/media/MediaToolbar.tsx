import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import type { MediaKind, MediaSort, MediaStats } from '@/types/settings';

const SORTS: { value: MediaSort; label: string }[] = [
    { value: 'newest', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name' },
    { value: 'largest', label: 'Largest first' },
    { value: 'smallest', label: 'Smallest first' },
];

/**
 * Search, filter, sort, and what a search alone cannot say: how much is here.
 *
 * The byte total is what turns a library from "a list of files" into
 * something a subscriber can manage — it is the number that answers "am I
 * about to need a bigger plan", and no single tile can say it.
 */
export function MediaToolbar({
    search,
    onSearch,
    kind,
    onKind,
    sort,
    onSort,
    stats,
    imagesOnly,
    uploading,
    onUpload,
    selectedCount,
    onClearSelection,
    onDeleteSelected,
}: {
    search: string;
    onSearch: (value: string) => void;
    kind: MediaKind | null;
    onKind: (kind: MediaKind | null) => void;
    sort: MediaSort;
    onSort: (sort: MediaSort) => void;
    stats?: MediaStats;
    imagesOnly: boolean;
    uploading: boolean;
    onUpload: () => void;
    selectedCount: number;
    onClearSelection: () => void;
    onDeleteSelected: () => void;
}) {
    // Selecting something replaces the ordinary toolbar with what you can do to
    // the selection — the same swap a file manager makes, so the actions that
    // matter right now are the only ones competing for attention.
    if (selectedCount > 0) {
        return (
            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-[var(--color-brand-subtle)] px-3 py-2">
                <button
                    type="button"
                    onClick={onClearSelection}
                    className="flex items-center gap-1.5 text-sm font-semibold text-[var(--color-ink-soft)]"
                >
                    <Icon name="x" size={14} weight="bold" />
                    {selectedCount} selected
                </button>
                <div className="ml-auto">
                    <Button type="button" variant="danger" size="sm" onClick={onDeleteSelected}>
                        <Icon name="trash" size={14} />
                        Remove {selectedCount === 1 ? 'file' : 'files'}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1" style={{ flexBasis: '14rem' }}>
                    <Icon
                        name="magnifying-glass"
                        size={15}
                        weight="regular"
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[var(--color-text-muted)]"
                    />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder="Search by name"
                        className="field pl-9"
                    />
                </div>

                {!imagesOnly && (
                    <div className="flex flex-none overflow-hidden rounded-lg border border-[var(--color-border-light)]">
                        {(
                            [
                                [null, 'All'],
                                ['image', 'Images'],
                                ['document', 'Documents'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={label}
                                type="button"
                                onClick={() => onKind(value)}
                                className={`px-2.5 py-1.5 text-[0.8125rem] font-medium transition-colors ${
                                    kind === value
                                        ? 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                                        : 'bg-[var(--color-card-bg)] text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)]'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}

                <select
                    value={sort}
                    onChange={(event) => onSort(event.target.value as MediaSort)}
                    className="field w-auto flex-none py-1.5 text-[0.8125rem]"
                    aria-label="Sort by"
                >
                    {SORTS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>

                <Button type="button" busy={uploading} onClick={onUpload} className="flex-none">
                    <Icon name="upload-simple" size={15} />
                    Upload
                </Button>
            </div>

            {stats && stats.total_count > 0 && (
                <p className="text-xs text-[var(--color-text-muted)]">
                    {stats.total_count.toLocaleString()} {stats.total_count === 1 ? 'file' : 'files'} ·{' '}
                    {formatBytes(stats.total_bytes)}
                    {stats.documents.count > 0 && !imagesOnly && (
                        <>
                            {' '}
                            · {stats.images.count} {stats.images.count === 1 ? 'image' : 'images'}, {stats.documents.count}{' '}
                            {stats.documents.count === 1 ? 'document' : 'documents'}
                        </>
                    )}
                </p>
            )}
        </div>
    );
}

function formatBytes(bytes: number): string {
    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / 1024 ** exponent;

    return `${exponent === 0 ? value : value.toFixed(value < 10 ? 1 : 0)} ${units[exponent]}`;
}
