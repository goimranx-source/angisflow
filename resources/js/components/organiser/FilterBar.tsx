import { useEffect, useRef, useState } from 'react';

import { ManageTagsModal } from '@/components/organiser/ManageTagsModal';
import { FlyoutBackdrop } from '@/components/ui/FlyoutBackdrop';
import { Icon } from '@/components/ui/Icon';
import { useOrganiser } from '@/hooks/useOrganiser';
import { cn } from '@/lib/utils';

export type Filters = {
    query: string;
    favouritesOnly: boolean;
    tags: string[];
};

export const NO_FILTERS: Filters = { query: '', favouritesOnly: false, tags: [] };

/**
 * Search, starred-only, and by tag — the three ways to narrow a list.
 *
 * They sit together in one card because they answer the same question from
 * different angles, and because a row of controls scattered across a screen
 * reads as three unrelated features rather than one filter.
 */
export function FilterBar({
    placeholder,
    filters,
    onChange,
}: {
    placeholder: string;
    filters: Filters;
    onChange: (filters: Filters) => void;
}) {
    const { data } = useOrganiser();
    const [tagsOpen, setTagsOpen] = useState(false);
    const [managing, setManaging] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    const tags = data?.data ?? [];

    useEffect(() => {
        if (!tagsOpen) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setTagsOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [tagsOpen]);

    const toggleTag = (id: string) =>
        onChange({
            ...filters,
            tags: filters.tags.includes(id)
                ? filters.tags.filter((tag) => tag !== id)
                : [...filters.tags, id],
        });

    return (
        <>
            <div ref={container} className="filter-bar">
                <div className="filter-search">
                    <Icon
                        name="magnifying-glass"
                        size={16}
                        weight="regular"
                        className="flex-none text-[var(--color-text-muted)]"
                    />
                    <input
                        value={filters.query}
                        onChange={(event) => onChange({ ...filters, query: event.target.value })}
                        placeholder={placeholder}
                        className="list-search-input"
                        aria-label={placeholder}
                    />
                    {filters.query && (
                        <button
                            type="button"
                            onClick={() => onChange({ ...filters, query: '' })}
                            className="palette-clear"
                            aria-label="Clear search"
                        >
                            <Icon name="x" size={13} weight="bold" />
                        </button>
                    )}
                </div>

                <button
                    type="button"
                    onClick={() => onChange({ ...filters, favouritesOnly: !filters.favouritesOnly })}
                    className={cn('filter-toggle', filters.favouritesOnly && 'is-active')}
                    title={filters.favouritesOnly ? 'Showing favourites only' : 'Show favourites only'}
                    aria-pressed={filters.favouritesOnly}
                    aria-label="Favourites only"
                >
                    <Icon name="star" size={16} weight={filters.favouritesOnly ? 'fill' : 'regular'} />
                </button>

                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setTagsOpen((was) => !was)}
                        className={cn('filter-toggle', filters.tags.length > 0 && 'is-active')}
                        title="Filter by tag"
                        aria-haspopup="menu"
                        aria-expanded={tagsOpen}
                        aria-label="Filter by tag"
                    >
                        <Icon name="tag" size={16} weight={filters.tags.length > 0 ? 'fill' : 'regular'} />
                        {filters.tags.length > 0 && (
                            <span className="filter-count">{filters.tags.length}</span>
                        )}
                    </button>

                    {tagsOpen && <FlyoutBackdrop onClose={() => setTagsOpen(false)} />}

                    {tagsOpen && (
                        <div className="filter-tags" role="menu">
                            {tags.length === 0 ? (
                                <p className="px-3 py-3 text-xs text-[var(--color-text-muted)]">
                                    You don’t have any tags yet
                                </p>
                            ) : (
                                <div className="max-h-56 overflow-y-auto py-1">
                                    {tags.map((tag) => (
                                        <label key={tag.id} className="row-tag-option">
                                            <input
                                                type="checkbox"
                                                checked={filters.tags.includes(tag.id)}
                                                onChange={() => toggleTag(tag.id)}
                                            />
                                            <span className="min-w-0 flex-1 truncate">{tag.name}</span>
                                        </label>
                                    ))}
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={() => {
                                    setManaging(true);
                                    setTagsOpen(false);
                                }}
                                className="row-submenu-manage"
                            >
                                Manage tags
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {managing && <ManageTagsModal onClose={() => setManaging(false)} />}
        </>
    );
}

/**
 * Does a thing survive the current filters?
 *
 * Kept beside the bar rather than repeated per screen: three screens applying
 * the same three filters from three copies of this logic would eventually
 * disagree about what "matches" means.
 */
export function matchesFilters(
    filters: Filters,
    input: { name: string; key: string },
    organiser: { favourites: string[]; assigned: Record<string, string[]> } | undefined,
): boolean {
    const term = filters.query.trim().toLowerCase();

    if (term && !input.name.toLowerCase().includes(term)) {
        return false;
    }

    if (filters.favouritesOnly && !(organiser?.favourites.includes(input.key) ?? false)) {
        return false;
    }

    if (filters.tags.length > 0) {
        const on = organiser?.assigned[input.key] ?? [];

        // Any of the chosen tags, not all — picking two tags means "show me
        // things in either", which is what a set of checkboxes reads as.
        if (!filters.tags.some((tag) => on.includes(tag))) {
            return false;
        }
    }

    return true;
}
