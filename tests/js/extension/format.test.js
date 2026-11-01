import { describe, expect, it } from 'vitest';
import { formatAction, formatTime, sumStats } from '../../../extension/src/popup/format.js';

describe('formatAction', () => {
    it('sums up a pass', () => {
        expect(formatAction({ found: 12, submitted: 10, errors: ['a', 'b'], error: null })).toBe('Found 12 files, submitted 10, 2 errors');
        expect(formatAction({ found: 1, submitted: 0, errors: [], error: null })).toBe('Found 1 file, submitted 0');
    });

    it('shows a failed pass by its reason', () => {
        expect(formatAction({ found: 0, submitted: 0, errors: [], error: 'Sign in to Shutterstock in this browser.' }))
            .toBe('Sign in to Shutterstock in this browser.');
        expect(formatAction(undefined)).toBeNull();
    });
});

describe('formatTime', () => {
    const now = Date.parse('2026-11-01T12:00:00Z');

    it('tells how long ago', () => {
        expect(formatTime('2026-11-01T11:59:40Z', now)).toBe('just now');
        expect(formatTime('2026-11-01T11:59:00Z', now)).toBe('1 minute ago');
        expect(formatTime('2026-11-01T09:00:00Z', now)).toBe('3 hours ago');
        expect(formatTime('2026-10-30T12:00:00Z', now)).toBe('2 days ago');
        expect(formatTime(null, now)).toBe('never');
    });
});

describe('sumStats', () => {
    it('adds up accounts and keeps the latest check', () => {
        const stats = (sent, checked_at) => ({ stats: { sent, pending: 1, accepted: 0, rejected: 2, checked_at } });

        expect(sumStats([stats(3, '2026-11-01T10:00:00+00:00'), stats(4, null), stats(1, '2026-11-01T11:00:00+00:00')]))
            .toEqual({ sent: 8, pending: 3, accepted: 0, rejected: 6, checked_at: '2026-11-01T11:00:00+00:00' });
        expect(sumStats([])).toEqual({ sent: 0, pending: 0, accepted: 0, rejected: 0, checked_at: null });
    });
});
