import { Icon } from '@/components/ui/Icon';
import type { IconName } from '@/components/ui/Icon';
import { useClock } from '@/hooks/useClock';
import { useWeather } from '@/hooks/useWeather';

/**
 * Date, time, and local weather — the header's counterpart to the sidebar's
 * always-something-there density. Styled after the search stub next to it
 * (same border-and-glass chip) so the two read as one family of controls
 * rather than a button next to a label.
 */
export function HeaderInfo() {
    const now = useClock();
    const weather = useWeather();

    const dateLabel = now.toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });

    const timeLabel = now.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });

    return (
        <div className="hidden items-center gap-2.5 rounded-xl border border-white/15 bg-white/5 px-3 py-1.5 text-sm text-[var(--color-text-subtle)] lg:flex">
            {weather && (
                <>
                    <span className="flex items-center gap-1.5" title={weatherLabel(weather.code)}>
                        <Icon name={weatherIcon(weather.code)} size={15} weight="fill" />
                        {Math.round(weather.temperatureC)}°C
                    </span>
                    <span className="h-3 w-px bg-white/15" aria-hidden />
                </>
            )}

            <span className="font-[family-name:var(--font-mono)] text-[0.8125rem] tabular-nums">
                {dateLabel} · {timeLabel}
            </span>
        </div>
    );
}

/** WMO weather codes (the set Open-Meteo reports) collapsed to one icon each. */
function weatherIcon(code: number): IconName {
    if (code === 0) return 'sun';
    if (code === 1 || code === 2 || code === 3) return 'cloud-sun';
    if (code === 45 || code === 48) return 'cloud-fog';
    if ([51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return 'cloud-rain';
    if ([71, 73, 75, 77, 85, 86].includes(code)) return 'cloud-snow';
    if (code === 95 || code === 96 || code === 99) return 'cloud-lightning';
    return 'cloud';
}

function weatherLabel(code: number): string {
    if (code === 0) return 'Clear sky';
    if (code === 1 || code === 2 || code === 3) return 'Partly cloudy';
    if (code === 45 || code === 48) return 'Fog';
    if ([51, 53, 55, 56, 57].includes(code)) return 'Drizzle';
    if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return 'Rain';
    if ([71, 73, 75, 77, 85, 86].includes(code)) return 'Snow';
    if (code === 95 || code === 96 || code === 99) return 'Thunderstorm';
    return 'Cloudy';
}
