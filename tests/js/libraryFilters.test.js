import { describe, expect, it } from 'vitest';
import {
    NOT_UPLOADED,
    activeCount,
    emptyFilters,
    filterCounts,
    matchesFilters,
    matchesMarketplaces,
    matchesTags,
    matchesTypes,
    toggle,
} from '../../resources/js/utils/libraryFilters';

const sent = (account) => ({ account_id: account, status: 'sent' });
const photo = { type: 'photo', listings: [sent(1), sent(2)], tags: ['iceland', 'sea'] };
const video = { type: 'video', listings: [{ account_id: 1, status: 'failed' }], tags: ['sea'] };
const vector = { type: 'vector', listings: [sent(2)] };

describe('library filters', () => {
    it('lets everything through when nothing is chosen', () => {
        expect([photo, video, vector].every((asset) => matchesFilters(asset, emptyFilters()))).toBe(true);
    });

    it('takes any of the chosen types', () => {
        expect(matchesTypes(photo, ['photo', 'vector'])).toBe(true);
        expect(matchesTypes(video, ['photo', 'vector'])).toBe(false);
    });

    it('takes a work uploaded to any of the chosen accounts, counting only sent listings', () => {
        expect(matchesMarketplaces(photo, [2])).toBe(true);
        expect(matchesMarketplaces(vector, [1, 2])).toBe(true);
        expect(matchesMarketplaces(video, [1])).toBe(false);
    });

    it('treats a work with nothing sent as not uploaded anywhere', () => {
        expect(matchesMarketplaces(video, [NOT_UPLOADED])).toBe(true);
        expect(matchesMarketplaces({ type: 'photo' }, [NOT_UPLOADED])).toBe(true);
        expect(matchesMarketplaces(photo, [NOT_UPLOADED])).toBe(false);
        expect(matchesMarketplaces(photo, [NOT_UPLOADED, 1])).toBe(true);
    });

    it('needs every chosen tag, and a work without tags matches only no tags', () => {
        expect(matchesTags(photo, ['iceland', 'sea'])).toBe(true);
        expect(matchesTags(video, ['iceland', 'sea'])).toBe(false);
        expect(matchesTags(vector, [])).toBe(true);
        expect(matchesTags(vector, ['sea'])).toBe(false);
    });

    it('combines the filters', () => {
        const filters = { types: ['photo', 'video'], marketplaces: [2], tags: ['sea'] };

        expect([photo, video, vector].filter((asset) => matchesFilters(asset, filters))).toEqual([photo]);
        expect(activeCount(filters)).toBe(4);
    });

    it('counts works per type and per account, once per work', () => {
        const twice = { type: 'photo', listings: [sent(1), sent(1)] };

        expect(filterCounts([photo, video, vector, twice])).toEqual({
            types: { photo: 2, video: 1, vector: 1 },
            marketplaces: { [NOT_UPLOADED]: 1, 1: 2, 2: 2 },
        });
    });

    it('toggles a value keeping the chosen order', () => {
        expect(toggle(['b'], 'a')).toEqual(['b', 'a']);
        expect(toggle(['b', 'a'], 'b')).toEqual(['a']);
    });
});
