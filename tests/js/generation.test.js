import { describe, expect, it } from 'vitest';
import { editedFields, isGenerated, markEdited, overLimit } from '../../resources/js/utils/generation';

describe('generated metadata in the panel', () => {
    const asset = { metadata_sources: { title: 'ai', description: 'user', keywords: 'file' } };

    it('tells the fields filled by generation', () => {
        expect(isGenerated(asset, 'title')).toBe(true);
        expect(isGenerated(asset, 'description')).toBe(false);
        expect(isGenerated({}, 'title')).toBe(false);
    });

    it('gives an edited field to the user at once, and leaves other changes alone', () => {
        expect(markEdited(asset.metadata_sources, { title: 'Lagoon', folder_id: 'f1' })).toEqual({ title: 'user', description: 'user', keywords: 'file' });
        expect(markEdited(asset.metadata_sources, { folder_id: 'f1' })).toBe(asset.metadata_sources);
    });

    it('names the fields a regeneration would replace', () => {
        expect(editedFields({ metadata_sources: { title: 'user', keywords: 'user', description: 'ai' } })).toEqual(['title', 'keywords']);
        expect(editedFields({})).toEqual([]);
    });

    it('tells a text over its soft limit', () => {
        expect(overLimit('x'.repeat(71), 70)).toBe(true);
        expect(overLimit('x'.repeat(70), 70)).toBe(false);
        expect(overLimit(null, 70)).toBe(false);
        expect(overLimit('x'.repeat(500), undefined)).toBe(false);
    });
});
