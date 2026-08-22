import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { api } from '@/lib/api';

type Active = {
    data:
        | { active: false }
        | { active: true; batches: number; total: number; done: number; failed: number; progress: number };
};

/**
 * What is still being sent to connected shops, anywhere in the application.
 *
 * ── Why this lives in the layout and not on the Orders page ──────────────────
 *
 * Because the work outlives the screen that started it. A bulk change on two
 * hundred orders finishes locally in a moment and takes minutes to reach the
 * shop, and in those minutes somebody will reload, open a customer, or go and
 * look at stock. Progress owned by the Orders page disappears the instant they
 * do — while the work carries on, invisibly.
 *
 * ── Why the server is asked, rather than a batch id being kept ───────────────
 *
 * A batch id held in the browser is lost by exactly the thing people do when
 * they are unsure: refresh. The server already knows what is running, so the
 * screen asks it instead, and the answer is the same in a second tab or on a
 * phone.
 *
 * The cost of that is a poll on every page. It is one small read, slowed to
 * every twelve seconds while nothing is happening and only quickened once
 * there is something to report.
 */
export function PushProgress() {
    const { data } = useQuery<Active>({
        queryKey: ['pushes', 'active'],
        queryFn: ({ signal }) => api.get('/orders/pushes/active', { signal }),
        refetchInterval: (query) => (query.state.data?.data.active ? 1500 : 12000),
        refetchOnWindowFocus: true,
        // Failing to reach this is not worth a red box in the corner of an
        // unrelated screen; the next poll will say the same thing.
        retry: false,
    });

    const state = data?.data;
    const active = state?.active === true;

    /*
     * Held for a moment after the last job, so the end is something you can
     * read rather than something you might catch.
     */
    const [justDone, setJustDone] = useState<{ total: number; failed: number } | null>(null);
    const last = useRef<{ total: number; failed: number } | null>(null);

    useEffect(() => {
        if (state?.active === true) {
            last.current = { total: state.total, failed: state.failed };

            return;
        }

        if (state?.active === false && last.current !== null) {
            setJustDone(last.current);
            last.current = null;
        }
    }, [state]);

    useEffect(() => {
        if (justDone === null) {
            return;
        }

        const timer = window.setTimeout(() => setJustDone(null), justDone.failed > 0 ? 8000 : 3500);

        return () => window.clearTimeout(timer);
    }, [justDone]);

    if (!active && justDone === null) {
        return null;
    }

    const total = active && state.active ? state.total : (justDone?.total ?? 0);
    const done = active && state.active ? state.done : total;
    const failed = active && state.active ? state.failed : (justDone?.failed ?? 0);
    const progress = active && state.active ? state.progress : 100;

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed bottom-6 left-6 z-[var(--z-toast)] w-72 rounded-[var(--shell-radius)] border border-[var(--color-border-light)] bg-[var(--color-card-bg)] p-3 shadow-lg"
        >
            <div className="flex items-start gap-2.5">
                <span className="mt-0.5 shrink-0">
                    {active ? (
                        <Icon name="circle-notch" size={16} className="animate-spin text-[var(--color-brand)]" />
                    ) : (
                        <Icon
                            name={failed > 0 ? 'warning-circle' : 'check-circle'}
                            size={16}
                            className={failed > 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]'}
                        />
                    )}
                </span>

                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-[var(--color-text-main)]">
                        {active ? 'Sending to connected shops' : 'Sent to connected shops'}
                    </p>

                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                        {active
                            ? `${done} of ${total}`
                            : failed > 0
                              ? `${total - failed} of ${total} sent · ${failed} failed`
                              : `${total} order${total === 1 ? '' : 's'} updated`}
                    </p>

                    {active && (
                        <div className="mt-2 h-1 overflow-hidden rounded-full bg-[var(--shell-muted)]">
                            <div
                                className="h-full rounded-full bg-[var(--color-brand)] transition-[width] duration-500"
                                style={{ width: `${Math.max(3, progress)}%` }}
                            />
                        </div>
                    )}
                </div>

                {/*
                  Hides the notice, never the work.

                  There is no cancel here on purpose: half-sent is a worse state
                  than sent, and it is not undoable from this corner.
                */}
                <button
                    type="button"
                    onClick={() => setJustDone(null)}
                    aria-label="Hide"
                    disabled={active}
                    className="shrink-0 text-[var(--color-text-muted)] transition-colors hover:text-[var(--color-text-main)] disabled:opacity-30"
                >
                    <Icon name="x" size={14} />
                </button>
            </div>
        </div>
    );
}
