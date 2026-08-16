import { useCallback, useEffect, useState } from 'react';

export type PovaLayout = 'floating' | 'sidebar' | 'full';

const LAYOUT_KEY = 'prism.pova.layout';

/**
 * Where Pova sits.
 *
 * ── Why the shell owns this and not the panel ────────────────────────────────
 *
 * Two of the three postures are not overlays. The side panel takes a column of
 * the page and the content narrows beside it; the full view takes the content
 * area outright. Neither can be arranged from inside a panel that floats over
 * everything — the layout has to know, so the choice lives where the layout is
 * and the panel is told.
 *
 * Which posture somebody wants is a habit rather than a per-question decision,
 * so it is remembered.
 */
export function usePovaLayout() {
    const [layout, setLayout] = useState<PovaLayout>(read);

    useEffect(() => {
        try {
            localStorage.setItem(LAYOUT_KEY, layout);
        } catch {
            // Private mode. The choice simply is not remembered.
        }
    }, [layout]);

    return { layout, setLayout: useCallback((next: PovaLayout) => setLayout(next), []) };
}

function read(): PovaLayout {
    try {
        const stored = localStorage.getItem(LAYOUT_KEY);

        return stored === 'sidebar' || stored === 'full' ? stored : 'floating';
    } catch {
        return 'floating';
    }
}
