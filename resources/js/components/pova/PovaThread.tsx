import { useEffect, useRef } from 'react';
import { useNavigate } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import type { PovaPage, PovaTurn } from '@/hooks/usePova';

/** The conversation itself — the same in every layout Pova can wear. */
export function PovaThread({
    turns,
    busy,
    onClose,
}: {
    turns: PovaTurn[];
    busy: boolean;
    onClose?: () => void;
}) {
    const endRef = useRef<HTMLDivElement>(null);

    // Follow the conversation down as it grows, rather than leaving somebody
    // reading the top of an answer that continues off-screen.
    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end', behavior: 'smooth' });
    }, [turns, busy]);

    return (
        <>
            {turns.map((turn, index) => (
                <div key={index} className={`ask-turn ask-turn-${turn.role}`}>
                    <p>{turn.content}</p>

                    {turn.pages && turn.pages.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {turn.pages.map((page) => (
                                <PageChip key={page.key} page={page} onGo={onClose} />
                            ))}
                        </div>
                    )}
                </div>
            ))}

            {busy && (
                <div className="ask-turn ask-turn-assistant flex items-center gap-2">
                    <Icon name="spinner" size={14} className="animate-spin" />
                    Thinking…
                </div>
            )}

            <div ref={endRef} />
        </>
    );
}

function PageChip({ page, onGo }: { page: PovaPage; onGo?: () => void }) {
    const navigate = useNavigate();

    return (
        <button
            type="button"
            onClick={() => {
                navigate(page.href);
                onGo?.();
            }}
            className="ask-page-chip"
        >
            {page.label}
            {!page.built && <span className="palette-soon">Soon</span>}
        </button>
    );
}
