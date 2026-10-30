import { describe, expect, it } from 'vitest';
import { sameTag, sortTags, tagValue, tagsFromValues, withTags } from '../../resources/js/utils/tags';

const batumi = { id: 1, name: 'Batumi' };
const sea = { id: 2, name: 'sea' };

describe('tags', () => {
    it('compares saved tags by id and new ones by name ignoring case', () => {
        expect(sameTag(batumi, { id: 1, name: 'renamed' })).toBe(true);
        expect(sameTag(batumi, { id: null, name: 'batumi' })).toBe(true);
        expect(sameTag(batumi, { id: 3, name: 'batumi' })).toBe(false);
        expect(sameTag({ id: null, name: 'Ёлка' }, { id: null, name: 'ёлка' })).toBe(true);
    });

    it('sorts alphabetically ignoring case, numbers in order', () => {
        expect(sortTags([sea, { id: 3, name: 'trip 10' }, batumi, { id: 4, name: 'trip 9' }]).map((t) => t.name))
            .toEqual(['Batumi', 'sea', 'trip 9', 'trip 10']);
    });

    it('turns picker values into tags', () => {
        expect(tagValue(batumi)).toBe(1);
        expect(tagValue({ id: null, name: 'night' })).toBe('night');

        // Ids of known tags, a typed name of a known tag, new names trimmed, empty names and repeats dropped.
        expect(tagsFromValues([2, ' BATUMI ', ' night ', '  ', 'Night', 1, 99], [batumi, sea])).toEqual([
            sea,
            batumi,
            { id: null, name: 'night' },
        ]);
    });

    it('adds saved tags to the list once, alphabetically', () => {
        const list = [sea];

        expect(withTags(list, [sea, { id: null, name: 'draft' }])).toBe(list);
        expect(withTags(list, [batumi, sea])).toEqual([batumi, sea]);
    });
});
