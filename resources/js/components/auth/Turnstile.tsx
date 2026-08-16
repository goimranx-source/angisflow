import { useEffect, useRef } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (element: HTMLElement, options: Record<string, unknown>) => string;
            remove: (id: string) => void;
        };
    }
}

const SCRIPT_ID = 'cf-turnstile-script';

/**
 * Cloudflare's bot check.
 *
 * Renders nothing at all when no site key is configured — and, importantly,
 * does not fetch the script either. A third-party script tag on the sign-in
 * page of an installation that has never enabled bot protection is a request to
 * somebody else's server on the critical path of the most important screen in
 * the product.
 */
type TurnstileProps = {
    siteKey?: string | null;
    /**
     * The solved token, handed straight to the form field the server reads.
     *
     * Passed up rather than read from a hidden input on submit: the widget can
     * re-solve itself at any moment — on expiry, or after a failed attempt —
     * and a token captured once goes stale without anything noticing.
     */
    onToken?: (token: string) => void;
};

export function Turnstile({ siteKey, onToken }: TurnstileProps) {
    const container = useRef<HTMLDivElement>(null);

    // Held in a ref so re-rendering the form does not tear down and re-render
    // the widget, which would make Cloudflare issue a fresh challenge on every
    // keystroke.
    const report = useRef(onToken);
    report.current = onToken;

    useEffect(() => {
        if (!siteKey || !container.current) {
            return;
        }

        let widgetId: string | undefined;
        let cancelled = false;

        const render = () => {
            if (cancelled || !container.current || !window.turnstile) {
                return;
            }

            widgetId = window.turnstile.render(container.current, {
                sitekey: siteKey,
                theme: 'light',
                callback: (token: string) => report.current?.(token),
                'error-callback': () => report.current?.(''),
                'expired-callback': () => report.current?.(''),
            });
        };

        if (window.turnstile) {
            render();
        } else if (!document.getElementById(SCRIPT_ID)) {
            const script = document.createElement('script');
            script.id = SCRIPT_ID;
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.onload = render;
            document.head.appendChild(script);
        } else {
            document.getElementById(SCRIPT_ID)?.addEventListener('load', render);
        }

        return () => {
            cancelled = true;

            if (widgetId && window.turnstile) {
                window.turnstile.remove(widgetId);
            }
        };
    }, [siteKey]);

    if (!siteKey) {
        return null;
    }

    return <div ref={container} className="flex justify-center" />;
}
