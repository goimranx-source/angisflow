import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { PovaThread } from '@/components/pova/PovaThread';
import { Icon } from '@/components/ui/Icon';
import { POVA_SUGGESTIONS, usePova } from '@/hooks/usePova';
import { usePovaHistory } from '@/hooks/usePovaHistory';
import type { PovaLayout } from '@/hooks/usePovaLayout';
import { useScrollLock } from '@/hooks/useScrollLock';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

const LAYOUTS: { value: PovaLayout; icon: string; label: string }[] = [
    { value: 'floating', icon: 'arrow-square-out', label: 'Floating' },
    { value: 'sidebar', icon: 'sidebar', label: 'Side panel' },
    { value: 'full', icon: 'arrows-out-simple', label: 'Full page' },
];

/**
 * Pova.
 *
 * ── Three postures, only one of which floats ─────────────────────────────────
 *
 * Floating hangs under the button that opened it, over the page you were
 * reading. The other two are not overlays at all: the side panel takes a column
 * of the layout and the content narrows beside it, and the full view takes the
 * content area outright. That is why the shell owns the choice and this is told
 * — a panel cannot rearrange the page it is floating above.
 *
 * ── Chat and History, not a title ────────────────────────────────────────────
 *
 * The header used to introduce Pova by name on every open, which is a thing you
 * need to read once. The room it took now goes to the only two things there are
 * to do here: carry on, or go back to something already asked.
 */
export function PovaPanel({
    open,
    exiting,
    layout,
    onLayout,
    question,
    onQuestionSent,
    onClose,
}: {
    open: boolean;
    exiting?: boolean;
    layout: PovaLayout;
    onLayout: (layout: PovaLayout) => void;
    /** A question handed over by another screen — Home's box, or search. */
    question?: string | null;
    onQuestionSent?: () => void;
    onClose: () => void;
}) {
    const { config, auth } = useSession();
    const { turns, busy, ask, reset, resume, conversationId } = usePova();
    const { conversations, save, remove } = usePovaHistory();
    const [tab, setTab] = useState<'chat' | 'history'>('chat');
    const [draft, setDraft] = useState('');
    const panelRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    // Every posture becomes a full-screen sheet below 880px (see the mobile
    // rules for .pova-floating/.pova-sidebar/.pova-full), so the lock only
    // applies there — the docked postures on a wide screen are part of the
    // page, not an overlay covering it.
    useScrollLock(open, 880);

    // A question handed over by another screen starts a fresh conversation
    // here, so the answer is not appended to whatever was open before it.
    useEffect(() => {
        if (!open || !question) {
            return;
        }

        reset();
        void ask(question);
        setTab('chat');
        onQuestionSent?.();
        // Deliberately keyed on the question alone: re-running this when `ask`
        // or `reset` are re-created would send it twice.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, question]);

    // Kept as it goes rather than on close: a browser tab closed mid-answer
    // should not lose the conversation that prompted it.
    useEffect(() => {
        if (!busy && turns.length > 0) {
            save(conversationId, turns);
        }
    }, [busy, turns, conversationId, save]);

    // Only the floating posture dismisses on an outside click. The other two are
    // part of the page — closing them because somebody clicked the thing Pova
    // just described would throw the conversation away at the worst moment.
    useEffect(() => {
        if (!open || layout !== 'floating') {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as HTMLElement;

            if (!panelRef.current?.contains(target) && !target.closest('.ai-pill')) {
                onClose();
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [open, layout, onClose]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const send = () => {
        void ask(draft);
        setDraft('');
    };

    const panel = (
        <section
            ref={panelRef}
            className={cn('pova', `pova-${layout}`, exiting && 'is-exiting')}
            role="dialog"
            aria-label="Pova"
        >
            <header className="pova-head">
                <div className="pova-tabs">
                    <button
                        type="button"
                        onClick={() => setTab('chat')}
                        className={cn('pova-tab', tab === 'chat' && 'is-active')}
                    >
                        Chat
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('history')}
                        className={cn('pova-tab', tab === 'history' && 'is-active')}
                    >
                        History
                    </button>
                </div>

                <div className="ml-auto flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => {
                            reset();
                            setTab('chat');
                        }}
                        className="pova-icon"
                        title="New chat"
                        aria-label="New chat"
                    >
                        <Icon name="plus" size={15} weight="duotone" />
                    </button>

                    <div className="pova-layouts">
                        {LAYOUTS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => onLayout(option.value)}
                                className={cn('pova-layout', layout === option.value && 'is-active')}
                                title={option.label}
                                aria-label={option.label}
                                aria-pressed={layout === option.value}
                            >
                                <Icon name={option.icon} size={14} weight="duotone" />
                            </button>
                        ))}
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="pova-icon"
                        title="Hide"
                        aria-label="Hide Pova"
                    >
                        <Icon name="caret-right" size={15} weight="duotone" />
                    </button>
                </div>
            </header>

            {tab === 'history' ? (
                <div className="pova-body">
                    {conversations.length === 0 ? (
                        <p className="m-auto max-w-xs text-center text-sm text-[var(--color-text-muted)]">
                            Nothing here yet. Conversations you have with Pova are kept in this
                            browser.
                        </p>
                    ) : (
                        conversations.map((conversation) => (
                            <div key={conversation.id} className="pova-history-row">
                                <button
                                    type="button"
                                    onClick={() => {
                                        resume(conversation.id, conversation.turns);
                                        setTab('chat');
                                    }}
                                    className="min-w-0 flex-1 text-left"
                                >
                                    <span className="block truncate text-[0.8125rem] font-medium text-[var(--color-text-main)]">
                                        {conversation.turns[0]?.content ?? 'Conversation'}
                                    </span>
                                    <span className="block text-[0.6875rem] text-[var(--color-text-muted)]">
                                        {when(conversation.startedAt)} ·{' '}
                                        {conversation.turns.filter((t) => t.role === 'user').length}{' '}
                                        {conversation.turns.filter((t) => t.role === 'user').length === 1
                                            ? 'question'
                                            : 'questions'}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => remove(conversation.id)}
                                    className="pova-icon flex-none"
                                    title="Remove"
                                    aria-label="Remove conversation"
                                >
                                    <Icon name="trash" size={14} />
                                </button>
                            </div>
                        ))
                    )}
                </div>
            ) : (
                <div className="pova-body">
                    {turns.length === 0 ? (
                        <div className="pova-empty">
                            <p className="text-sm text-[var(--color-text-body)]">
                                Ask me how anything here works — where a thing lives, what a screen
                                is for, what is coming next.
                            </p>

                            <div className="mt-3 flex flex-col gap-1.5">
                                {POVA_SUGGESTIONS.map((item) => (
                                    <button
                                        key={item.display}
                                        type="button"
                                        onClick={() => {
                                            // Write the detailed prompt in the input, don't auto-send
                                            setDraft(item.prompt);
                                            // Switch to chat tab since user is starting a new question
                                            if (tab !== 'chat') {
                                                setTab('chat');
                                            }
                                            // Focus the input after a brief delay to ensure tab switch completes
                                            setTimeout(() => inputRef.current?.focus(), 50);
                                        }}
                                        disabled={!config.assistant_enabled}
                                        className="pova-suggestion"
                                    >
                                        <Icon
                                            name="arrow-u-down-left"
                                            size={13}
                                            className="flex-none text-[var(--color-brand-active)]"
                                        />
                                        {item.display}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <PovaThread turns={turns} busy={busy} onClose={onClose} />
                    )}
                </div>
            )}

            <footer className="pova-foot">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        send();
                    }}
                    className="ask-form"
                >
                    <input
                        ref={inputRef}
                        value={draft}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            // Switch to chat tab when user starts typing (not just focusing)
                            if (tab === 'history' && event.target.value.trim().length > 0) {
                                setTab('chat');
                            }
                        }}
                        placeholder={
                            config.assistant_enabled
                                ? 'Continue the conversation…'
                                : 'Pova is not switched on yet'
                        }
                        disabled={!config.assistant_enabled || busy}
                        className="ask-input"
                        aria-label="Message Pova"
                    />
                    <button
                        type="submit"
                        className="ask-send"
                        disabled={!config.assistant_enabled || busy || draft.trim().length < 2}
                        aria-label="Send"
                    >
                        <Icon name="paper-plane-right" size={16} weight="duotone" />
                    </button>
                </form>

                <p className="mt-1.5 text-center text-[0.6875rem] text-[var(--color-text-muted)]">
                    {config.assistant_enabled
                        ? 'Pova can be wrong. Check anything that matters.'
                        : auth?.user.is_owner
                          ? 'Add an ANTHROPIC_API_KEY to switch Pova on.'
                          : 'Your account owner can switch Pova on.'}
                </p>
            </footer>
        </section>
    );

    // Floating is the only posture that leaves the page alone, so it is the only
    // one that goes over the top of it. The others are rendered in place by the
    // layout that called this.
    return layout === 'floating' ? createPortal(panel, document.body) : panel;
}

function when(timestamp: number): string {
    const minutes = Math.round((Date.now() - timestamp) / 60_000);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h ago`;

    return new Date(timestamp).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
