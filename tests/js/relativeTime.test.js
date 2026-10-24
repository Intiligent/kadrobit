import { describe, expect, it } from 'vitest';
import { formatRelative } from '../../resources/js/utils/relativeTime';

const now = Date.parse('2026-10-24T12:00:00Z');
const ago = (seconds) => new Date(now - seconds * 1000).toISOString();

describe('formatRelative', () => {
    it('uses relative words for the last week', () => {
        expect(formatRelative(ago(10), 'en', now)).toBe('now');
        expect(formatRelative(ago(5 * 60), 'en', now)).toBe('5 minutes ago');
        expect(formatRelative(ago(2 * 3600), 'en', now)).toBe('2 hours ago');
        expect(formatRelative(ago(86400), 'en', now)).toBe('yesterday');
    });

    it('falls back to a date for older times', () => {
        expect(formatRelative('2026-10-01T12:00:00Z', 'en', now)).toBe('Oct 1');
    });

    it('is empty without a time', () => {
        expect(formatRelative(null, 'en', now)).toBe('');
    });
});
