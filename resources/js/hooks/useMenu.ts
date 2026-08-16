import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { NavItem, NavSection } from '@/types';

const OPEN_KEY = 'prism.menu.open';
const PINS_KEY = 'prism.menu.pins';

/**
 * A pinned key resolved back to the thing it names.
 *
 * Both shapes are a heading and a list of pages, because that is the only way
 * the pinned list can be read correctly. A loose page rendered on its own,
 * directly beneath a pinned department's rows, sits at the same indent with
 * nothing between them — so "Production Orders" pinned out of Production
 * appears to be the last item of Sales. Filing every page under the department
 * it actually belongs to is what stops the list telling that lie.
 */
export type PinnedEntry =
    /** A whole department, pinned as one. Its items come with it. */
    | ({ kind: 'section' } & NavSection)
    /** Pages pinned one at a time, gathered under the department they live in.
     *  `label` is null for a page out of a flat section — the dashboard is not
     *  underneath anything, so it gets no heading. */
    | { kind: 'pages'; key: string; label: string | null; items: NavItem[] };

/**
 * The sidebar's behaviour.
 *
 * Two pieces of state:
 *
 *   open      which department is folded out. One at a time — ten open at once
 *             is the flat list again with extra steps. Pinned holds the same
 *             slot as a department rather than one of its own: it looks like a
 *             department, it opens like one, and letting it be the exception
 *             meant two panels could be unfolded at once and the rule stopped
 *             being a rule.
 *
 *   pins      which departments and pages somebody put at the top. Per browser
 *             rather than per account — a preference about this screen, not a
 *             fact about the business.
 *
 * There is deliberately no state here for the narrow rail. A collapsed rail
 * used to float a panel beside itself, which meant the menu existed twice: two
 * sets of components, two sets of styles, its own positioning maths, its own
 * hover grace, and its own rules about what counted as open. It now widens
 * under the pointer instead (see useRail), so there is one menu drawn one way
 * and this hook does not need to know how wide the rail is.
 */
export function useMenu(
    nav: NavSection[],
    activeSectionKey: string | null,
    isActive: (item: NavItem) => boolean,
) {
    // ── Which department is folded out ───────────────────────────────────
    //
    // The one holding the current page wins over whatever was last opened, so
    // arriving by a link never leaves you unable to see where you landed.
    // 'pinned' is a legal value here — see the note at the top.
    const [open, setOpen] = useState<string | null>(() => {
        if (activeSectionKey) {
            return activeSectionKey;
        }

        try {
            // Pinned by default on a first visit: it is empty until somebody
            // puts something in it, so an open empty section costs nothing and
            // a closed full one hides the shortcuts they made.
            return window.localStorage.getItem(OPEN_KEY) ?? 'pinned';
        } catch {
            return 'pinned';
        }
    });

    /**
     * Fold a department open.
     *
     * Assigning rather than adding to a set is what makes this an accordion —
     * opening one closes whatever was open before, with no bookkeeping to get
     * wrong. There is no longer a narrow-rail branch: a rail too narrow to
     * unfold into is a rail the pointer has not reached yet, and reaching it
     * widens it.
     */
    const toggle = useCallback((openKey: string) => {
        setOpen((current) => {
            const next = current === openKey ? null : openKey;

            try {
                if (next) {
                    window.localStorage.setItem(OPEN_KEY, next);
                } else {
                    window.localStorage.removeItem(OPEN_KEY);
                }
            } catch {
                // Private browsing. The menu still works for this visit.
            }

            return next;
        });
    }, []);

    // ── Pins ─────────────────────────────────────────────────────────────
    //
    // Per browser rather than per account: it is a preference about this
    // screen, not a fact about the business, and round-tripping it would mean
    // writing to the users table every time somebody pinned something.

    const [pins, setPins] = useState<string[]>(() => {
        try {
            const raw = window.localStorage.getItem(PINS_KEY);
            const parsed: unknown = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.filter((v): v is string => typeof v === 'string') : [];
        } catch {
            return [];
        }
    });

    const togglePin = useCallback((key: string) => {
        setPins((current) => {
            const next = current.includes(key)
                ? current.filter((k) => k !== key)
                : [...current, key];

            try {
                window.localStorage.setItem(PINS_KEY, JSON.stringify(next));
            } catch {
                // Storage full or unavailable. Pinning silently not persisting
                // is a far better outcome than the sidebar throwing.
            }

            return next;
        });
    }, []);

    const isPinned = useCallback((key: string) => pins.includes(key), [pins]);

    /**
     * Every pinned key resolved, and filed under whatever it belongs to.
     *
     * Order follows the order things were pinned: a department takes the slot
     * where it was pinned, and a loose page takes the slot where the *first*
     * page from its department was pinned, so a second pin out of Production
     * joins the first rather than starting a second Production heading further
     * down.
     */
    const pinnedEntries = useMemo<PinnedEntry[]>(() => {
        const out: PinnedEntry[] = [];
        const groups = new Map<string, Extract<PinnedEntry, { kind: 'pages' }>>();

        for (const key of pins) {
            const section = nav.find((s) => s.key === key && !s.flat);

            if (section) {
                out.push({ kind: 'section', ...section });
                continue;
            }

            for (const owner of nav) {
                const item = owner.items.find((m) => m.key === key);

                if (!item) {
                    continue;
                }

                // The whole department is already pinned above, so this page is
                // in the list twice over. Pinning a department and then a page
                // inside it should not print that page a second time.
                if (!owner.flat && pins.includes(owner.key)) {
                    break;
                }

                let group = groups.get(owner.key);

                if (group === undefined) {
                    group = {
                        kind: 'pages',
                        key: owner.key,
                        label: owner.flat ? null : owner.label,
                        items: [],
                    };

                    groups.set(owner.key, group);
                    out.push(group);
                }

                group.items.push(item);
                break;
            }
        }

        return out;
    }, [pins, nav]);

    /** Whether the page being looked at is reachable from the pinned list. */
    const activeIsPinned = useMemo(
        () => pinnedEntries.some((entry) => entry.items.some(isActive)),
        [pinnedEntries, isActive],
    );

    /**
     * The open panel follows where you go.
     *
     * Navigating out of a department folds it shut — sitting on the dashboard
     * with Sales still hanging open is the menu describing where you were
     * rather than where you are, and it is how a sidebar ends up with four
     * things unfolded and no way to tell which one matters.
     *
     * The exception is arriving from Pinned. Somebody who clicked a shortcut
     * wants the shortcuts to still be there when the page lands; swapping their
     * list out for the department the page happens to live in takes away the
     * thing they were using to navigate.
     *
     * Deliberately skipped on mount. On the first render `open` is already the
     * right answer — the department holding the page, or whatever was last left
     * open — and running this over the top of it would close a remembered panel
     * on every cold load.
     */
    const mounted = useRef(false);

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;

            return;
        }

        setOpen((current) => {
            if (current === 'pinned' && activeIsPinned) {
                return current;
            }

            // null when the page belongs to no department — the dashboard, the
            // profile — which is exactly when everything should be shut.
            return activeSectionKey;
        });
    }, [activeSectionKey, activeIsPinned]);

    return {
        open,
        isOpen: (key: string) => open === key,
        toggle,

        pins,
        isPinned,
        togglePin,
        pinnedEntries,
    };
}
