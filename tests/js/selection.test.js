import { describe, expect, it } from 'vitest';
import { selectWorks } from '../../resources/js/utils/selection';

const order = ['a', 'b', 'c', 'd', 'e'];

describe('selecting works in the grid', () => {
    it('picks one work on a plain click', () => {
        expect(selectWorks(['a', 'b'], order, 'c')).toEqual({ ids: ['c'], anchor: 'c' });
    });

    it('adds and removes a work with Cmd/Ctrl, keeping the grid order', () => {
        expect(selectWorks(['d'], order, 'b', { toggle: true })).toEqual({ ids: ['b', 'd'], anchor: 'b' });
        expect(selectWorks(['b', 'd'], order, 'd', { toggle: true })).toEqual({ ids: ['b'], anchor: 'd' });
    });

    it('adds the range from the anchor with Shift, either way, and keeps the anchor', () => {
        expect(selectWorks(['b'], order, 'd', { range: true, anchor: 'b' })).toEqual({ ids: ['b', 'c', 'd'], anchor: 'b' });
        expect(selectWorks(['d'], order, 'b', { range: true, anchor: 'd' })).toEqual({ ids: ['b', 'c', 'd'], anchor: 'd' });
        expect(selectWorks(['a'], order, 'e', { range: true, anchor: 'd' })).toEqual({ ids: ['a', 'd', 'e'], anchor: 'd' });
    });

    it('picks just the work when Shift has no anchor in the grid', () => {
        expect(selectWorks([], order, 'c', { range: true, anchor: null })).toEqual({ ids: ['c'], anchor: 'c' });
        expect(selectWorks([], order, 'c', { range: true, anchor: 'gone' })).toEqual({ ids: ['c'], anchor: 'c' });
    });
});
