import { type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { Tooltip } from '@/components/ui/Tooltip';
import { cn } from '@/lib/utils';

type InfoHintProps = {
    /** The explanation. Kept short — this is a hint, not documentation. */
    children: ReactNode;
    /**
     * Where the bubble sits. Below by default.
     *
     * These icons sit beside section headings, which are by definition at the
     * top of their card — and often near the top of the page. Opening upward
     * put the bubble above the viewport, so the hint existed and could not be
     * read. There is always room underneath a heading.
     */
    position?: 'top' | 'bottom' | 'left' | 'right';
    className?: string;
    /** Screen-reader wording, when "More information" is too vague. */
    label?: string;
};

/**
 * The explanation for the thing beside it, on demand.
 *
 * ── Why this beats a line of grey text under every heading ───────────────────
 *
 * A settings page is a long column of unrelated decisions, and explaining each
 * one inline doubles its height. The reader then scrolls past three paragraphs
 * to reach a dropdown they already understood, and the one control they were
 * actually unsure about is no easier to find than the rest — everything is
 * equally annotated, so nothing is signposted.
 *
 * Behind an icon, the page is a list of controls again and the prose is there
 * for whoever wants it. The cost is that the text is no longer scannable, which
 * is the right trade for "what does automatic mean" and the wrong one for
 * anything a person must read before acting — a warning about overwriting data
 * stays on the page.
 *
 * The icon is a real button so it is reachable by keyboard and announced by a
 * screen reader; Tooltip shows on focus as well as hover.
 */
export function InfoHint({
    children,
    position = 'bottom',
    className,
    label = 'More information',
}: InfoHintProps) {
    return (
        <Tooltip content={children} position={position}>
            <button
                type="button"
                aria-label={label}
                // Nothing happens on click. The tooltip opens on hover and on
                // focus, and a button that navigates nowhere should not also
                // scroll the page or submit the form it sits inside.
                onClick={(event) => event.preventDefault()}
                className={cn(
                    'grid size-4 flex-none place-items-center rounded-full text-[var(--color-text-subtle)] transition-colors hover:text-[var(--color-brand-text)] focus-visible:text-[var(--color-brand-text)]',
                    className,
                )}
            >
                <Icon name="info" size={14} weight="regular" />
            </button>
        </Tooltip>
    );
}
