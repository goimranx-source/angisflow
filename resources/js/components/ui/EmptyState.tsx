import type { ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';

/**
 * Nothing here — said properly.
 *
 * An empty list rendered as an empty box tells somebody the screen is broken.
 * It has to say what would be here, why there is none yet, and what to do about
 * it — which is three sentences, not a shrug.
 */
export function EmptyState({
    icon,
    title,
    body,
    action,
    compact = false,
}: {
    icon: string;
    title: string;
    body: string;
    action?: ReactNode;
    /** Inside a card that already has its own padding. */
    compact?: boolean;
}) {
    return (
        <div className={compact ? 'empty-state is-compact' : 'empty-state'}>
            <span className="empty-state-icon">
                <Icon name={icon} size={compact ? 20 : 24} weight="duotone" />
            </span>
            <p className="mt-3 text-sm font-semibold text-[var(--color-text-main)]">{title}</p>
            <p className="mt-1 max-w-sm text-[0.8125rem] text-[var(--color-text-muted)]">{body}</p>
            {action && <div className="mt-3">{action}</div>}
        </div>
    );
}
