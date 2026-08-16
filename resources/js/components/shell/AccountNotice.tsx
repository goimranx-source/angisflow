import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { useSession } from '@/providers/SessionProvider';

/**
 * Trial and payment notices.
 *
 * ── Why this is inside the page and not under the bar ────────────────────────
 *
 * It began as a full-width strip between the top bar and the page, which put a
 * pale band across the one seam the shell is built around: the rail and the bar
 * form a dark frame, and the page is a panel resting inside it with its corner
 * cut away. A strip spanning that seam squares the corner off and leaves the
 * frame looking broken — on exactly the accounts most likely to be looked at,
 * since only trialing and past-due subscribers ever see it.
 *
 * So it sits in the content column, where every other tool of this kind puts
 * it: aligned with the page, scrolling with the page, and unable to interfere
 * with the frame around it.
 */
export function AccountNotice() {
    const { tenant } = useSession();
    const account = tenant?.account;

    if (!account) {
        return null;
    }

    if (account.status === 'past_due') {
        return (
            <Notice
                tone="warning"
                icon="warning"
                title="A payment did not go through"
                action={{ to: '/billing', label: 'Update payment details' }}
            >
                Everything keeps working in the meantime — nothing has been paused.
            </Notice>
        );
    }

    if (account.status === 'trialing' && account.trial_ends_at) {
        const daysLeft = Math.max(
            0,
            Math.ceil((Date.parse(account.trial_ends_at) - Date.now()) / 86_400_000),
        );

        return (
            <Notice
                tone="brand"
                icon="calendar-check"
                title={
                    daysLeft > 0
                        ? `${daysLeft} ${daysLeft === 1 ? 'day' : 'days'} left on your trial`
                        : 'Your trial has ended'
                }
                action={{ to: '/billing', label: 'Choose a plan' }}
            >
                {daysLeft > 0
                    ? 'Pick a plan whenever you are ready — nothing is lost when the trial ends.'
                    : 'Choose a plan to carry on where you left off.'}
            </Notice>
        );
    }

    return null;
}

function Notice({
    tone,
    icon,
    title,
    children,
    action,
}: {
    tone: 'brand' | 'warning';
    icon: string;
    title: string;
    children: React.ReactNode;
    action: { to: string; label: string };
}) {
    return (
        <div className={`notice notice-${tone}`}>
            <span className="notice-icon">
                <Icon name={icon} size={16} weight="duotone" />
            </span>

            <div className="min-w-0 flex-1">
                <p className="notice-title">{title}</p>
                <p className="notice-body">{children}</p>
            </div>

            <Link to={action.to} className="notice-action">
                {action.label}
                <Icon name="caret-right" size={13} weight="duotone" />
            </Link>
        </div>
    );
}
