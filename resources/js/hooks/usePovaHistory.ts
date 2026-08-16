import { useCallback, useEffect, useState } from 'react';

import type { PovaTurn } from '@/hooks/usePova';

export type PovaConversation = {
    id: string;
    startedAt: number;
    turns: PovaTurn[];
};

const HISTORY_KEY = 'prism.pova.history.v1';

/** Enough to find last week's answer, few enough not to fill the browser's
 *  storage with transcripts nobody will read again. */
const MAX_CONVERSATIONS = 20;

/**
 * Past conversations.
 *
 * ── Where these live, and what that means ────────────────────────────────────
 *
 * In this browser, not on the server. That is a real limitation and worth
 * knowing: a conversation had on a laptop is not in the history on a phone, and
 * clearing site data clears it. The alternative — a table, endpoints, retention
 * rules, and a copy of every question somebody typed sitting in the database —
 * is a bigger decision than a history tab, and one to make deliberately rather
 * than by implication.
 */
export function usePovaHistory() {
    const [conversations, setConversations] = useState<PovaConversation[]>(read);

    // Another tab of the same tool is the same person. Without this, two
    // windows quietly overwrite each other's history.
    useEffect(() => {
        const onStorage = (event: StorageEvent) => {
            if (event.key === HISTORY_KEY) {
                setConversations(read());
            }
        };

        window.addEventListener('storage', onStorage);

        return () => window.removeEventListener('storage', onStorage);
    }, []);

    const save = useCallback((id: string, turns: PovaTurn[]) => {
        if (turns.length === 0) {
            return;
        }

        setConversations((prior) => {
            const rest = prior.filter((conversation) => conversation.id !== id);
            const existing = prior.find((conversation) => conversation.id === id);

            const next = [
                { id, startedAt: existing?.startedAt ?? Date.now(), turns },
                ...rest,
            ].slice(0, MAX_CONVERSATIONS);

            write(next);

            return next;
        });
    }, []);

    const remove = useCallback((id: string) => {
        setConversations((prior) => {
            const next = prior.filter((conversation) => conversation.id !== id);
            write(next);

            return next;
        });
    }, []);

    const clear = useCallback(() => {
        setConversations([]);
        write([]);
    }, []);

    return { conversations, save, remove, clear };
}

function read(): PovaConversation[] {
    try {
        const raw = localStorage.getItem(HISTORY_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function write(conversations: PovaConversation[]): void {
    try {
        localStorage.setItem(HISTORY_KEY, JSON.stringify(conversations));
    } catch {
        // Quota or private mode. Losing history is not worth failing a chat over.
    }
}
