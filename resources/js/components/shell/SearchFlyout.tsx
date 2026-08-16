import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { useScrollLock } from '@/hooks/useScrollLock';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';
import type { NavItem } from '@/types';

type Entry = NavItem & { section: string | null };

const RECENT_KEY = 'prism.search.recent.v1';
const RECENT_MAX = 4;

/**
 * Find a page, or hand the question to Pova.
 *
 * ── Why a flyout and not a centred modal ─────────────────────────────────────
 *
 * It was a dialog in the middle of the screen, which is right for a command
 * palette you live in and wrong for a box you glance at. Anchored under the
 * button that opens it, the page stays visible behind and the thing keeps its
 * place in the bar rather than taking the whole window each time.
 *
 * ── Why the filters are departments ──────────────────────────────────────────
 *
 * There are forty-odd pages across ten departments. "Money" narrows that to
 * five in one click, which is faster than typing for somebody who knows roughly
 * where a thing lives but not what it is called.
 */
export function SearchFlyout({
    open,
    exiting,
    onClose,
    onAskPova,
}: {
    open: boolean;
    exiting?: boolean;
    onClose: () => void;
    onAskPova: (question: string) => void;
}) {
    const { nav, config } = useSession();
    const navigate = useNavigate();
    const [query, setQuery] = useState('');
    const [section, setSection] = useState<string | null>(null);
    const [cursor, setCursor] = useState(0);
    const [recent, setRecent] = useState<string[]>(readRecents);
    const panelRef = useRef<HTMLDivElement>(null);

    useScrollLock(open, 880);

    const entries = useMemo<Entry[]>(
        () =>
            nav.flatMap((group) =>
                group.items.map((item) => ({ ...item, section: group.flat ? null : group.label })),
            ),
        [nav],
    );

    const sections = useMemo(
        () => nav.filter((group) => !group.flat && group.label).map((group) => group.label as string),
        [nav],
    );

    const results = useMemo(() => {
        const term = query.trim().toLowerCase();
        const scoped = section ? entries.filter((entry) => entry.section === section) : entries;

        if (!term) {
            // Picking a department is asking to browse it, so show what is in
            // it — built or not. Filtering to built-only left departments that
            // have not shipped yet looking empty, which reads as the filter
            // being broken rather than as the work being ahead.
            if (section) {
                return [...scoped].sort((a, b) => Number(b.built) - Number(a.built)).slice(0, 8);
            }

            // Otherwise: where you have been, then what actually works. Not the
            // whole catalogue — most of it is not built, and a wall of pages
            // that do not exist is the opposite of help.
            const byKey = new Map(scoped.map((entry) => [entry.key, entry]));
            const seen = recent.map((key) => byKey.get(key)).filter((e): e is Entry => Boolean(e));
            const rest = scoped.filter((entry) => entry.built && !seen.includes(entry));

            return [...seen, ...rest].slice(0, 8);
        }

        return scoped
            .map((entry) => {
                const label = entry.label.toLowerCase();
                let score = 0;

                if (label === term) score = 100;
                else if (label.startsWith(term)) score = 80;
                else if (label.includes(term)) score = 60;
                else if (entry.summary?.toLowerCase().includes(term)) score = 20;

                return { entry, score: score > 0 && entry.built ? score + 5 : score };
            })
            .filter((row) => row.score > 0)
            .sort((a, b) => b.score - a.score)
            .slice(0, 8)
            .map((row) => row.entry);
    }, [entries, query, section, recent]);

    useEffect(() => setCursor(0), [query, section]);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setSection(null);
        } else {
            setRecent(readRecents());
        }
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as HTMLElement;

            if (!panelRef.current?.contains(target) && !target.closest('[data-search-trigger]')) {
                onClose();
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const go = (entry: Entry) => {
        const next = [entry.key, ...readRecents().filter((key) => key !== entry.key)].slice(
            0,
            RECENT_MAX,
        );

        try {
            localStorage.setItem(RECENT_KEY, JSON.stringify(next));
        } catch {
            // Nothing worth failing a navigation over.
        }

        setRecent(next);
        navigate(entry.href);
        onClose();
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setCursor((c) => (results.length ? (c + 1) % results.length : 0));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setCursor((c) => (results.length ? (c - 1 + results.length) % results.length : 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const chosen = results[cursor];

            if (chosen) {
                go(chosen);
            } else if (query.trim().length >= 3) {
                onAskPova(query.trim());
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        }
    };

    return createPortal(
        <div ref={panelRef} className={`search-flyout ${exiting ? 'is-exiting' : ''}`} role="dialog" aria-label="Search">
            <div className="search-field">
                <Icon
                    name="magnifying-glass"
                    size={17}
                    weight="duotone"
                    className="flex-none text-[var(--color-text-muted)]"
                />
                <input
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="Search"
                    className="search-input"
                    aria-label="Search pages"
                />
                <kbd className="palette-hint flex-none">Ctrl K</kbd>
            </div>

            <div className="search-tabs no-scrollbar">
                <button
                    type="button"
                    onClick={() => setSection(null)}
                    className={cn('search-tab', section === null && 'is-active')}
                >
                    Overview
                </button>
                {sections.map((label) => (
                    <button
                        key={label}
                        type="button"
                        onClick={() => setSection(label)}
                        className={cn('search-tab', section === label && 'is-active')}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="search-results">
                <p className="palette-group-label">
                    {query.trim()
                        ? 'Results'
                        : section
                          ? section
                          : recent.length
                            ? 'Most used'
                            : 'Ready to use'}
                </p>

                {results.length === 0 ? (
                    <p className="px-2 py-6 text-center text-sm text-[var(--color-text-muted)]">
                        {query.trim() ? `Nothing matches “${query.trim()}”.` : 'Nothing here yet.'}
                    </p>
                ) : (
                    results.map((entry, index) => (
                        <button
                            key={entry.key}
                            type="button"
                            onMouseMove={() => setCursor(index)}
                            onClick={() => go(entry)}
                            className={cn('search-row', index === cursor && 'is-active')}
                        >
                            <span className="search-row-icon">
                                <Icon name={entry.icon} size={17} weight="duotone" />
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span className="flex items-center gap-1.5">
                                    <span className="truncate text-[0.875rem] font-medium">
                                        {entry.label}
                                    </span>
                                    {!entry.built && <span className="palette-soon">Soon</span>}
                                </span>
                                {entry.section && (
                                    <span className="block truncate text-xs text-[var(--color-text-muted)]">
                                        {entry.section}
                                    </span>
                                )}
                            </span>
                            <Icon
                                name="caret-right"
                                size={13}
                                weight="duotone"
                                className="flex-none text-[var(--color-text-muted)]"
                            />
                        </button>
                    ))
                )}
            </div>

            {config.assistant_enabled && (
                <div className="search-foot">
                    <span className="text-[0.8125rem] text-[var(--color-text-muted)]">
                        Need help finding something?
                    </span>
                    <button
                        type="button"
                        onClick={() => onAskPova(query.trim())}
                        className="search-ask-pova"
                    >
                        <Icon name="sparkle" size={14} weight="fill" />
                        Ask Pova
                    </button>
                </div>
            )}
        </div>,
        document.body,
    );
}

function readRecents(): string[] {
    try {
        const raw = localStorage.getItem(RECENT_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed.filter((key) => typeof key === 'string') : [];
    } catch {
        return [];
    }
}
