import { useCallback, useState } from 'react';

import { setCookie } from '@/lib/utils';

const COOKIE = 'prism_theme';
const DARK = 'dark';

/**
 * Light or dark, read from <html> rather than from storage.
 *
 * The same reasoning as useRail's collapsed state: an inline script in <head>
 * (see app.blade.php) reads the cookie and sets data-theme before any CSS is
 * parsed, so the very first paint is already the right theme. Reading from
 * React instead would mean one frame of light, then a jump to dark on every
 * cold load for everyone who chose it.
 */
export function useTheme() {
    const [dark, setDark] = useState<boolean>(() => {
        if (typeof document === 'undefined') {
            return false;
        }

        return document.documentElement.getAttribute('data-theme') === DARK;
    });

    const toggle = useCallback(() => {
        setDark((was) => {
            const next = !was;

            document.documentElement.setAttribute('data-theme', next ? DARK : 'light');
            setCookie(COOKIE, next ? DARK : 'light');

            return next;
        });
    }, []);

    return { dark, toggle };
}
