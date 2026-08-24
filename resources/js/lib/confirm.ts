import { useSyncExternalStore } from 'react';

/**
 * The question the app asks before it destroys something.
 *
 * ── Why a module-level store rather than component state ─────────────────────
 *
 * The same reasoning as toasts, and one more besides. A confirmation is raised
 * from wherever the action lives: a mutation handler, a row menu, a bulk-action
 * bar, a keyboard shortcut. Half of those are not components and have none of
 * them have anywhere sensible to keep a piece of dialog state.
 *
 * The extra reason is the call shape. `if (await confirm(...))` reads as one
 * thought and keeps the decision next to the thing it decides. Wiring a
 * <Confirm> component per call site means an `open` flag, a pending-item ref
 * and an onConfirm handler for every delete button in the app — which is why
 * two dozen of them reached for `window.confirm` instead.
 *
 * ── Why one at a time ────────────────────────────────────────────────────────
 *
 * A queue would let a second question stack behind the first. There is no
 * honest reading of that: the second was raised by code that has not been told
 * the answer to the first yet. A pending request is replaced, and the one it
 * replaced resolves false — nobody agreed to it.
 */

export type ConfirmTone = 'danger' | 'warning' | 'default';

export type ConfirmRequest = {
    id: number;
    title: string;
    description: string;
    confirmText: string;
    cancelText: string;
    tone: ConfirmTone;

    /** The glyph in the disc. Defaults to one that suits the tone. */
    icon?: string;

    /**
     * Made to write this out before the confirm button will do anything.
     *
     * "Are you sure?" is answered yes by reflex. Typing the name is the only
     * confirmation that requires reading which thing is about to go — which is
     * the actual question when the row above and the row below look alike.
     *
     * For the things that cannot be rebuilt from anywhere: a workspace, a set
     * of books. Not for a row somebody can re-enter in ten seconds.
     */
    requireText?: string;

    /** Shown above the input, when there is one. Defaults to a plain sentence. */
    requireLabel?: string;
};

export type ConfirmOptions = Partial<
    Pick<
        ConfirmRequest,
        'confirmText' | 'cancelText' | 'tone' | 'icon' | 'requireText' | 'requireLabel'
    >
>;

let current: ConfirmRequest | null = null;
let settle: ((agreed: boolean) => void) | null = null;
let nextId = 1;

const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** The question on screen, or null. Read by the one component that draws it. */
export function useConfirmRequest(): ConfirmRequest | null {
    return useSyncExternalStore(
        subscribe,
        () => current,
        () => current,
    );
}

/** Answer the open question. Anything but an explicit yes is a no. */
export function answerConfirm(agreed: boolean): void {
    const respond = settle;

    current = null;
    settle = null;
    emit();

    respond?.(agreed);
}

/**
 * Ask, and wait for the answer.
 *
 * @example
 * if (await confirm('Delete this order?', 'It cannot be brought back.')) {
 *     await remove();
 * }
 */
export function confirm(
    title: string,
    description = '',
    options: ConfirmOptions = {},
): Promise<boolean> {
    // Whatever was already being asked was asked by code that has not heard an
    // answer yet. It has not been agreed to, so it has been refused.
    settle?.(false);

    current = {
        id: nextId++,
        title,
        description,
        confirmText: options.confirmText ?? 'Yes, Delete',
        cancelText: options.cancelText ?? 'Cancel',
        tone: options.tone ?? 'danger',
        icon: options.icon,
        requireText: options.requireText,
        requireLabel: options.requireLabel,
    };

    emit();

    return new Promise<boolean>((resolve) => {
        settle = resolve;
    });
}
