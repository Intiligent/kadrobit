/**
 * Texts of the popup (English only).
 */
export function formatAction(action) {
    if (!action) {
        return null;
    }

    if (action.error) {
        return action.error;
    }

    const parts = [`Found ${plural(action.found, 'file')}`, `submitted ${action.submitted}`];

    if (action.errors.length) {
        parts.push(plural(action.errors.length, 'error'));
    }

    return parts.join(', ');
}

export function plural(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

export function formatTime(iso, now = Date.now()) {
    if (!iso) {
        return 'never';
    }

    const minutes = Math.round((now - new Date(iso).getTime()) / 60_000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${plural(minutes, 'minute')} ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${plural(hours, 'hour')} ago`;
    }

    return `${plural(Math.round(hours / 24), 'day')} ago`;
}

/**
 * Counts of several accounts on one marketplace, added up; checked_at is the latest.
 */
export function sumStats(accounts) {
    return accounts.reduce((sum, { stats }) => ({
        sent: sum.sent + stats.sent,
        pending: sum.pending + stats.pending,
        accepted: sum.accepted + stats.accepted,
        rejected: sum.rejected + stats.rejected,
        checked_at: [sum.checked_at, stats.checked_at].filter(Boolean).sort().pop() ?? null,
    }), { sent: 0, pending: 0, accepted: 0, rejected: 0, checked_at: null });
}
