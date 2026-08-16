import { useCallback, useState } from 'react';

import { api } from '@/lib/api';

export type PovaPage = { key: string; label: string; href: string; built: boolean };

export type PovaTurn = {
    role: 'user' | 'assistant';
    content: string;
    pages?: PovaPage[];
};

/** How many earlier turns travel with a question. The whole history is re-sent
 *  and re-billed each turn, so this is a cost ceiling as much as a context one. */
const HISTORY_LIMIT = 8;

/**
 * What people actually open this for.
 *
 * Written against what the tool does — workspaces, businesses, the modules that
 * exist — rather than generic assistant filler. A suggestion nobody would type
 * teaches nothing about what the thing is good for.
 * 
 * Each suggestion has a short display text and a detailed prompt that gets sent to the AI.
 */
export const POVA_SUGGESTIONS = [
    {
        display: 'What can I do with this tool?',
        prompt: 'Can you give me a comprehensive overview of what Prism ERP can do? I want to understand the main features, what business operations it helps me manage, and what makes it useful for running a business. Please explain the key capabilities in simple terms.'
    },
    {
        display: 'How do workspaces and businesses differ?',
        prompt: 'I need help understanding the difference between workspaces and businesses in Prism. What is a workspace used for? What is a business used for? How do they relate to each other? When would I create multiple workspaces versus multiple businesses? Please explain this clearly with examples.'
    },
    {
        display: 'Where do I change my base currency?',
        prompt: 'I need to change the base currency for my business. Can you guide me to where I can find the currency settings? What page or section should I go to? Also, can you explain what the base currency is used for and if changing it affects existing data?'
    },
    {
        display: 'How do I add another business?',
        prompt: 'I want to add a new business to my account. Can you guide me step-by-step on how to create and set up a new business? Where do I go to add it? What information will I need to provide? Also, explain how businesses are organized within workspaces.'
    },
    {
        display: 'What is on the roadmap next?',
        prompt: 'I would like to know what features and improvements are planned for Prism in the future. What is coming next on the roadmap? Are there any new modules or major features being developed? Please give me an overview of upcoming developments.'
    },
];

/**
 * The conversation with Pova.
 *
 * Lifted out of the components that show it because there are now three: the
 * box on Home, the panel in the header, and whatever comes next. Two copies of
 * this would eventually disagree about how much history to send — which is a
 * cost difference, not just a behavioural one.
 */
export function usePova() {
    const [turns, setTurns] = useState<PovaTurn[]>([]);
    const [busy, setBusy] = useState(false);
    // Identifies this conversation for the history store. Regenerated on reset
    // so a new conversation is a new entry rather than overwriting the last.
    const [conversationId, setConversationId] = useState(newId);

    const ask = useCallback(
        async (question: string) => {
            const trimmed = question.trim();

            if (trimmed.length < 2 || busy) {
                return;
            }

            let history: { role: string; content: string }[] = [];

            setTurns((prior) => {
                history = prior.slice(-HISTORY_LIMIT).map(({ role, content }) => ({ role, content }));

                return [...prior, { role: 'user', content: trimmed }];
            });

            setBusy(true);

            try {
                const result = await api.post<{ data: { answer: string; pages: PovaPage[] } }>(
                    '/assistant/ask',
                    { question: trimmed, history },
                );

                setTurns((prior) => [
                    ...prior,
                    { role: 'assistant', content: result.data.answer, pages: result.data.pages },
                ]);
            } catch (problem) {
                const message =
                    (problem as { message?: string })?.message ??
                    'That could not be answered just now.';

                setTurns((prior) => [...prior, { role: 'assistant', content: message }]);
            } finally {
                setBusy(false);
            }
        },
        [busy],
    );

    const reset = useCallback(() => {
        setTurns([]);
        setConversationId(newId());
    }, []);

    /** Reopen a stored conversation and carry on from where it left off. */
    const resume = useCallback((id: string, priorTurns: PovaTurn[]) => {
        setConversationId(id);
        setTurns(priorTurns);
    }, []);

    return { turns, busy, ask, reset, resume, conversationId };
}

function newId(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}
