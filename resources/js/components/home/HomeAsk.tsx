import { useEffect, useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { usePovaLauncher } from '@/providers/PovaProvider';
import { useSession } from '@/providers/SessionProvider';

/**
 * Prompts worth pressing.
 *
 * Every one of these is a question about this tool, because that is what Pova
 * can answer. Each suggestion has a short display text and a detailed prompt
 * that gets sent to the AI for better, more comprehensive answers.
 */
const PROMPTS = [
    {
        display: 'What can I do with this tool?',
        prompt: 'Can you give me a comprehensive overview of what Prism ERP can do? I want to understand the main features, what business operations it helps me manage, and what makes it useful for running a business. Please explain the key capabilities in simple terms.'
    },
    {
        display: 'How do workspaces and businesses differ?',
        prompt: 'I need help understanding the difference between workspaces and businesses in Prism. What is a workspace used for? What is a business used for? How do they relate to each other? When would I create multiple workspaces versus multiple businesses? Please explain this clearly with examples.'
    },
    {
        display: 'How do I add another business?',
        prompt: 'I want to add a new business to my account. Can you guide me step-by-step on how to create and set up a new business? Where do I go to add it? What information will I need to provide? Also, explain how businesses are organized within workspaces.'
    },
    {
        display: 'Where do I change my base currency?',
        prompt: 'I need to change the base currency for my business. Can you guide me to where I can find the currency settings? What page or section should I go to? Also, can you explain what the base currency is used for and if changing it affects existing data?'
    },
    {
        display: 'How do I brand this as my own?',
        prompt: 'I want to customize and brand Prism with my own company branding. Can you guide me on how to do this? Where are the branding settings? What can I customize - like logos, colors, company name, etc.? Please explain the available branding options and where to find them.'
    },
    {
        display: 'What is on the roadmap next?',
        prompt: 'I would like to know what features and improvements are planned for Prism in the future. What is coming next on the roadmap? Are there any new modules or major features being developed? Please give me an overview of upcoming developments.'
    },
];

/** Milliseconds per character. Fast enough not to be a wait, slow enough to
 *  read as being written rather than pasted. */
const KEYSTROKE = 16;

/**
 * The ask box on Home.
 *
 * ── Why a prompt types itself out ────────────────────────────────────────────
 *
 * Pressing a suggestion could just send it. Writing it into the box first shows
 * where the words went and what the box is for — the next question gets typed
 * there rather than hunted for among the chips. The send button stays inert
 * until the line finishes, so nobody fires half a question.
 */
export function HomeAsk() {
    const { config } = useSession();
    const pova = usePovaLauncher();
    const [draft, setDraft] = useState('');
    const [writing, setWriting] = useState(false);
    const [glow, setGlow] = useState(false);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const frame = useRef<number>(0);

    // Any run in flight has to stop when this unmounts, or it keeps setting
    // state on something that is gone.
    useEffect(() => () => cancelAnimationFrame(frame.current), []);

    /**
     * How far through the line we should be is worked out from the clock, not
     * from how many times this has run. A timeout per character assumes every
     * timeout fires on time, and they do not: a background tab clamps timers to
     * once a second, which turns a half-second line into half a minute. Reading
     * the elapsed time instead means a late tick catches up, so the line always
     * takes the time it is supposed to however the browser is scheduling.
     */
    const write = (text: string) => {
        cancelAnimationFrame(frame.current);
        setWriting(true);
        setDraft('');
        inputRef.current?.focus();

        const startedAt = performance.now();

        const step = () => {
            const shown = Math.min(text.length, Math.ceil((performance.now() - startedAt) / KEYSTROKE));

            setDraft(text.slice(0, shown));

            if (shown < text.length) {
                frame.current = requestAnimationFrame(step);
            } else {
                setWriting(false);
            }
        };

        frame.current = requestAnimationFrame(step);
    };

    const send = () => {
        if (draft.trim().length < 2 || writing) {
            return;
        }

        pova.open(draft.trim());
        setDraft('');
    };

    const ready = draft.trim().length >= 2 && !writing && config.assistant_enabled;

    return (
        <>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    send();
                }}
                className={glow ? 'home-ask is-glowing' : 'home-ask'}
                // One pulse when the box is first entered, then never again —
                // an effect that fires on every keystroke is a distraction, and
                // one that loops is a decoration nobody asked for.
                onAnimationEnd={() => setGlow(false)}
            >
                <textarea
                    ref={inputRef}
                    value={draft}
                    onChange={(event) => {
                        // Typing over a line being written takes it back.
                        cancelAnimationFrame(frame.current);
                        setWriting(false);
                        setDraft(event.target.value);
                    }}
                    onFocus={() => setGlow(true)}
                    onKeyDown={(event) => {
                        // Submit on Enter (but allow Shift+Enter for new lines)
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            send();
                        }
                    }}
                    placeholder="Type what you're looking for or ask a question"
                    className="home-ask-input"
                    aria-label="Ask Pova"
                    rows={3}
                />
                <button
                    type="submit"
                    className="home-ask-send"
                    disabled={!ready}
                    aria-label="Ask"
                >
                    <Icon name="paper-plane-right" size={17} weight="duotone" />
                </button>
            </form>

            <div className="mt-4 flex flex-wrap justify-center gap-2">
                {PROMPTS.map((item) => (
                    <button
                        key={item.display}
                        type="button"
                        onClick={() => {
                            // Write the detailed prompt (not the short display text)
                            write(item.prompt);
                        }}
                        className="starter-chip"
                    >
                        <Icon name="sparkle" size={13} weight="fill" />
                        {item.display}
                    </button>
                ))}
            </div>
        </>
    );
}
