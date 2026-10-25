import { describe, expect, it } from 'vitest';
import { mergeAsset } from '@/utils/mergeAsset';

describe('mergeAsset', () => {
    it('takes the server version except fields that are local', () => {
        const asset = { id: 'a', title: 'Typing…', keywords: [], updated_at: '2026-10-25T10:00:00+00:00' };

        expect(mergeAsset(asset, { id: 'a', title: 'Old', keywords: ['sea'], updated_at: '2026-10-25T10:00:05+00:00' }, new Set(['title']))).toBe(true);
        expect(asset).toEqual({ id: 'a', title: 'Typing…', keywords: ['sea'], updated_at: '2026-10-25T10:00:05+00:00' });
    });

    it('skips a version older than the shown one', () => {
        const asset = { id: 'a', metadata_pending: false, updated_at: '2026-10-25T10:00:05+00:00' };

        expect(mergeAsset(asset, { id: 'a', metadata_pending: true, updated_at: '2026-10-25T10:00:01+00:00' })).toBe(false);
        expect(asset.metadata_pending).toBe(false);
    });

    it('applies a version of the same moment', () => {
        const asset = { id: 'a', title: '', updated_at: '2026-10-25T10:00:05+00:00' };

        mergeAsset(asset, { id: 'a', title: 'Sunset', updated_at: '2026-10-25T10:00:05+00:00' });
        expect(asset.title).toBe('Sunset');
    });
});
