import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { Icon } from '@/components/ui/Icon';
import { answerConfirm, useConfirmRequest, type ConfirmTone } from '@/lib/confirm';

const GLYPH: Record<ConfirmTone, string> = {
    danger: 'trash',
    warning: 'warning',
    default: 'question',
};

/**
 * The one dialog that asks before anything is destroyed.
 *
 * Mounted once, in the shell. Everything else calls `confirm()` and waits.
 *
 * ── Why it looks the way it does ─────────────────────────────────────────────
 *
 * Narrow, centred, and stacked: mark, question, the answer you probably do not
 * want, then the way out. Nothing is off to one side to be clicked past.
 *
 * The destructive button is the wide red one and it is on top, which is worth
 * saying because the usual advice is the opposite. Hiding it does not stop
 * anybody — it makes them hunt, and hunting is done by reflex too. What stops
 * the wrong deletion is knowing which thing is about to go, which is the
 * sentence's job, and the typing when the thing cannot be rebuilt.
 *
 * ── Why the whole page is inert while it is up ───────────────────────────────
 *
 * A confirmation is the one thing that must not be answered by a click aimed
 * somewhere else. Unlike a flyout's sheet, this one swallows the press and
 * hands nothing on: dismissing by clicking away is deliberate here, and
 * dismissing *and* pressing something else in one go is not.
 */
export function ConfirmHost() {
    const request = useConfirmRequest();
    const [typed, setTyped] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const cancel = useRef<HTMLButtonElement>(null);

    // A fresh question starts with an empty box, and the cursor where the
    // answer goes. Keyed on the id so asking the same question twice still
    // clears — the text is identical, the request is not.
    useEffect(() => {
        if (!request) {
            return;
        }

        setTyped('');

        const focus = window.setTimeout(
            () => (request.requireText ? input.current : cancel.current)?.focus(),
            30,
        );

        return () => window.clearTimeout(focus);
    }, [request?.id, request]);

    useEffect(() => {
        if (!request) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                answerConfirm(false);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [request]);

    if (!request) {
        return null;
    }

    // The typed name has to match, but not the stray space picked up from a
    // double-click that selected the word and the gap after it.
    const unlocked =
        !request.requireText || typed.trim() === request.requireText.trim();

    const agree = () => {
        if (unlocked) {
            answerConfirm(true);
        }
    };

    return createPortal(
        <div className="confirm-backdrop" role="dialog" aria-modal="true">
            <div
                className="absolute inset-0"
                onClick={() => answerConfirm(false)}
                aria-hidden
            />

            <div
                className="confirm-card"
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && unlocked) {
                        event.preventDefault();
                        agree();
                    }
                }}
            >
                <span className={`confirm-mark is-${request.tone}`}>
                    <Icon name={request.icon ?? GLYPH[request.tone]} size={20} />
                </span>

                <h2 className="confirm-title">{request.title}</h2>

                {request.description && (
                    <p className="confirm-text">{request.description}</p>
                )}

                {request.requireText && (
                    <label className="confirm-field">
                        <span>
                            {request.requireLabel ?? (
                                <>
                                    Type <strong>{request.requireText}</strong> to confirm
                                </>
                            )}
                        </span>
                        <input
                            ref={input}
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            className="field"
                            placeholder={request.requireText}
                            aria-label={`Type ${request.requireText} to confirm`}
                            autoComplete="off"
                            spellCheck={false}
                        />
                    </label>
                )}

                <button
                    type="button"
                    onClick={agree}
                    disabled={!unlocked}
                    className={`confirm-go is-${request.tone}`}
                >
                    {request.confirmText}
                </button>

                <button
                    ref={cancel}
                    type="button"
                    onClick={() => answerConfirm(false)}
                    className="confirm-back"
                >
                    {request.cancelText}
                </button>
            </div>
        </div>,
        document.body,
    );
}
