/**
 * Formatting helpers for the library UI. Locale-aware where the browser supports it.
 */

export function formatDuration(ms) {
    if (!ms && ms !== 0) {
        return '';
    }

    const total = Math.round(ms / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');

    return hours ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
}

export function formatBytes(bytes, locale) {
    if (!bytes && bytes !== 0) {
        return '';
    }

    const units = ['byte', 'kilobyte', 'megabyte', 'gigabyte'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return new Intl.NumberFormat(locale, {
        style: 'unit',
        unit: units[unit],
        unitDisplay: 'short',
        maximumFractionDigits: unit < 2 ? 0 : 1,
    }).format(value);
}

export function formatDateTime(iso, locale) {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

/**
 * Megapixels of a width × height, rounded down to one decimal: 2048 × 1536 → 3.1. Rounded down so a work just
 * under a marketplace minimum never reads as meeting it.
 */
export function megapixels(width, height) {
    return width && height ? Math.floor((width * height) / 100_000) / 10 : null;
}
