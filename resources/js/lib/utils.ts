import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Class names, with later ones winning.
 *
 * Plain concatenation loses to CSS specificity in a way that is maddening to
 * debug: `"p-2" + "p-4"` produces both, and which one applies depends on the
 * order Tailwind happened to emit them in the stylesheet, not the order they
 * were written. twMerge resolves the conflict the way the author meant.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}

/**
 * Whether a URL belongs to a navigation item.
 *
 * Prefix matching, with a guard against the obvious trap: '/catalogue' must not
 * light up for '/catalogue-archive', so a match either is the whole path or is
 * followed by a separator.
 */
export function pathMatches(current: string, prefixes: readonly string[]): boolean {
    const path = current.split('?')[0]?.replace(/\/+$/, '') || '/';

    return prefixes.some((prefix) => {
        const clean = prefix.split('?')[0]?.replace(/\/+$/, '') || '/';

        if (clean === '/') {
            return path === '/';
        }

        return path === clean || path.startsWith(`${clean}/`);
    });
}

/** Initials for an avatar, from however many names somebody has. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return (parts[0] ?? '').slice(0, 2).toUpperCase();
    }

    return ((parts[0]?.[0] ?? '') + (parts[parts.length - 1]?.[0] ?? '')).toUpperCase();
}

/** A cookie that survives a restart, for preferences the server does not need. */
export function setCookie(name: string, value: string, days = 365): void {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();

    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
}

export function getCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}
