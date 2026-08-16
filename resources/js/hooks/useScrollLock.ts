import { useEffect } from 'react';

let lockCount = 0;

function lock() {
    lockCount += 1;

    if (lockCount === 1) {
        document.documentElement.style.overflow = 'hidden';
    }
}

function unlock() {
    lockCount = Math.max(0, lockCount - 1);

    if (lockCount === 0) {
        document.documentElement.style.overflow = '';
    }
}

/**
 * Freezes page scroll while a full-width overlay is open.
 *
 * Reference-counted rather than a single on/off, because more than one of
 * these can be true at once — Search closing into Ask AI opening, say — and
 * whichever closes last has to be the one that actually restores scrolling.
 * A plain boolean would let the second overlay's cleanup turn scrolling back
 * on while the first is still covering the screen.
 *
 * The sidebar's own drawer deliberately does not use this: it never covers
 * the full width, so the page behind it staying scrollable is the page
 * staying usable, not a bug.
 *
 * `narrowerThan` gates the lock to the width where the thing being opened is
 * actually a full-screen sheet rather than a small anchored card — locking
 * scroll for a 280px dropdown on a desktop monitor would just be annoying.
 */
export function useScrollLock(active: boolean, narrowerThan?: number) {
    useEffect(() => {
        if (!active) return undefined;
        if (narrowerThan !== undefined && window.innerWidth >= narrowerThan) return undefined;

        lock();

        return unlock;
    }, [active, narrowerThan]);
}
