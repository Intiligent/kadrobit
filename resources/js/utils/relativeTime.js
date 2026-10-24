/**
 * "2 h ago", "yesterday" for recent times, a date for older ones.
 */
export function formatRelative(iso, locale, now = Date.now()) {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const abs = Math.abs(seconds);
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    if (abs < 60) {
        return rtf.format(0, 'second');
    }

    if (abs < 3600) {
        return rtf.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return rtf.format(Math.round(seconds / 3600), 'hour');
    }

    if (abs < 86400 * 7) {
        return rtf.format(Math.round(seconds / 86400), 'day');
    }

    return new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' }).format(new Date(iso));
}
