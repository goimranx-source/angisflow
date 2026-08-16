import { useSyncExternalStore } from 'react';

/**
 * Messages the shell shows.
 *
 * ── Why a module-level store rather than context ─────────────────────────────
 *
 * Toasts are raised from anywhere — a mutation handler, an error boundary, an
 * event listener — including places that are not React components and have no
 * access to a hook. A module store can be written to from any of them, and
 * `useSyncExternalStore` lets the one component that renders them subscribe
 * without every other component re-rendering when a message appears.
 *
 * That last part matters: a toast provider high in the tree re-renders its
 * whole subtree on every message, which on a page with a large table is a
 * visible stutter for the sake of a notification in the corner.
 */

export type ToastTone = 'success' | 'error' | 'warning' | 'info';

export type ToastAction = {
    label: string;
    onClick: () => void;
};

export type Toast = {
    id: number;
    tone: ToastTone;
    message: string;
    action?: ToastAction;
    persistent?: boolean;
};

let toasts: Toast[] = [];
let nextId = 1;

const listeners = new Set<() => void>();

function emit(): void {
    // A new array each time, so useSyncExternalStore sees a changed reference.
    // Mutating in place would leave subscribers convinced nothing happened.
    toasts = [...toasts];
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function useToasts(): Toast[] {
    return useSyncExternalStore(
        subscribe,
        () => toasts,
        () => toasts,
    );
}

export function dismissToast(id: number): void {
    toasts = toasts.filter((toast) => toast.id !== id);
    emit();
}

function push(tone: ToastTone, message: string, options: { action?: ToastAction; persistent?: boolean } = {}): number {
    const id = nextId++;

    toasts = [...toasts, { id, tone, message, action: options.action, persistent: options.persistent }];
    emit();

    // Don't auto-dismiss if persistent
    if (!options.persistent) {
        // Errors stay longer: somebody who has to act on a message needs more time
        // to read it than somebody being told a save worked.
        window.setTimeout(() => dismissToast(id), tone === 'error' ? 8000 : 4000);
    }

    return id;
}

export const toast = {
    success: (message: string, options?: { action?: ToastAction; persistent?: boolean }) => 
        push('success', message, options ?? {}),
    error: (message: string, options?: { action?: ToastAction; persistent?: boolean }) => 
        push('error', message, options ?? {}),
    warning: (message: string, options?: { action?: ToastAction; persistent?: boolean }) => 
        push('warning', message, options ?? {}),
    info: (message: string, options?: { action?: ToastAction; persistent?: boolean }) => 
        push('info', message, options ?? {}),
};
