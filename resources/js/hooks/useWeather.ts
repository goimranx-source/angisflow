import { useEffect, useState } from 'react';

export type WeatherReading = {
    temperatureC: number;
    code: number;
};

type Cached = WeatherReading & { fetchedAt: number };

const CACHE_KEY = 'prism.weather.v1';
const REFRESH_MS = 30 * 60 * 1000;

/**
 * Local weather for the header.
 *
 * One geolocation prompt per browser session, then a plain fetch to
 * Open-Meteo — no API key, no account, no per-request billing, which matters
 * for a widget this small. Denial, an unsupported browser, or a failed fetch
 * all resolve to the same thing: no reading. Nothing here is essential to
 * using the app, so nothing about its absence should ask twice or show an
 * error — the header just quietly carries one less thing.
 */
export function useWeather(): WeatherReading | null {
    const [reading, setReading] = useState<WeatherReading | null>(() => readCache());

    useEffect(() => {
        const cached = readCache();
        if (cached && Date.now() - cached.fetchedAt < REFRESH_MS) {
            return;
        }

        if (!('geolocation' in navigator)) {
            return;
        }

        let cancelled = false;

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const { latitude, longitude } = position.coords;
                const url = `https://api.open-meteo.com/v1/forecast?latitude=${latitude}&longitude=${longitude}&current=temperature_2m,weather_code&timezone=auto`;

                fetch(url)
                    .then((response) => (response.ok ? response.json() : null))
                    .then((data) => {
                        if (cancelled || !data?.current) {
                            return;
                        }

                        const next: Cached = {
                            temperatureC: data.current.temperature_2m,
                            code: data.current.weather_code,
                            fetchedAt: Date.now(),
                        };

                        sessionStorage.setItem(CACHE_KEY, JSON.stringify(next));
                        setReading(next);
                    })
                    .catch(() => {});
            },
            () => {
                // Denied or unavailable. Silence is the correct response —
                // see the comment above.
            },
            { maximumAge: REFRESH_MS, timeout: 8000 },
        );

        return () => {
            cancelled = true;
        };
    }, []);

    return reading;
}

function readCache(): Cached | null {
    try {
        const raw = sessionStorage.getItem(CACHE_KEY);
        return raw ? (JSON.parse(raw) as Cached) : null;
    } catch {
        return null;
    }
}
