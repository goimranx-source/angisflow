import { useEffect, useState } from 'react';

/**
 * Ticks once a second so a header clock reads as alive rather than a
 * timestamp frozen at whatever moment the page happened to load.
 */
export function useClock(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 1000);
        return () => window.clearInterval(id);
    }, []);

    return now;
}
