import { describe, expect, it } from 'vitest';
import { formatTotalSize } from '@/utils/format';

describe('formatTotalSize', () => {
    it('shows megabytes below a gigabyte and gigabytes from there', () => {
        expect(formatTotalSize(0, 'en')).toBe('0 MB');
        expect(formatTotalSize(512 * 1024, 'en')).toBe('0.5 MB');
        expect(formatTotalSize(740.3 * 1024 ** 2, 'en')).toBe('740.3 MB');
        expect(formatTotalSize(2.44 * 1024 ** 3, 'en')).toBe('2.4 GB');
    });

    it('speaks the language of the page', () => {
        expect(formatTotalSize(2.44 * 1024 ** 3, 'ru')).toBe('2,4 ГБ');
    });
});
