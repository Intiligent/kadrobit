import { describe, expect, it } from 'vitest';
import {
    batchRows,
    canCancel,
    canRetry,
    defaultSelection,
    hasPendingListings,
    listingFor,
    listingState,
    nextSteps,
    readyCount,
    selectionTotals,
} from '../../resources/js/utils/publishing';

const connection = (id, code, rules = {}) => ({ id, label: code, marketplace: { code, name: code, metadata_rules: rules } });

const accounts = [
    connection(1, 'shutterstock', { csv_upload: 'web', submit: 'web' }),
    connection(2, 'alamy'),
    connection(3, 'adobe_stock', { submit: 'web' }),
];

const summary = {
    works: 3,
    accounts: [
        { account: 1, blocked: null, works: 3, unsupported: 0, warnings: [{ asset: 'a', warnings: [{ key: 'keywords_min', params: {} }] }] },
        { account: 2, blocked: null, works: 2, unsupported: 1, warnings: [{ asset: 'a', warnings: [] }, { asset: 'b', warnings: [] }] },
        { account: 3, blocked: 'invalid_credentials', works: 0, unsupported: 0, warnings: [] },
        // Gone from the page's accounts meanwhile
        { account: 9, blocked: null, works: 1, unsupported: 0, warnings: [] },
    ],
};

describe('ready works and listings', () => {
    it('counts ready works only', () => {
        expect(readyCount([{ status: 'ready' }, { status: 'draft' }, { status: 'submitted' }, { status: 'ready' }])).toBe(2);
    });

    it('tells works with uploads in the queue', () => {
        expect(hasPendingListings({ listings: [{ status: 'sent' }, { status: 'sending' }] })).toBe(true);
        expect(hasPendingListings({ listings: [{ status: 'failed' }] })).toBe(false);
        expect(hasPendingListings({})).toBe(false);
    });

    it('shows a sent work edited since as outdated', () => {
        expect(listingState({ status: 'sent', outdated: true })).toBe('outdated');
        expect(listingState({ status: 'sent', outdated: false })).toBe('sent');
        expect(listingState({ status: 'failed', outdated: false })).toBe('failed');
    });

    it('cancels queued listings and retries failed ones of ready works', () => {
        expect(canCancel({ status: 'queued' })).toBe(true);
        expect(canCancel({ status: 'sending' })).toBe(false);
        expect(canRetry({ status: 'failed' }, { status: 'ready' })).toBe(true);
        expect(canRetry({ status: 'cancelled' }, { status: 'ready' })).toBe(true);
        expect(canRetry({ status: 'failed' }, { status: 'archived' })).toBe(false);
        expect(canRetry({ status: 'sent' }, { status: 'ready' })).toBe(false);
    });

    it('finds the listing of a work on an account', () => {
        const asset = { listings: [{ id: 5, account_id: 1 }] };

        expect(listingFor(asset, 1)).toEqual({ id: 5, account_id: 1 });
        expect(listingFor(asset, 2)).toBeNull();
    });
});

describe('publish dialog', () => {
    const rows = batchRows(summary, accounts);

    it('lists the summary accounts still connected and tells which can be picked', () => {
        expect(rows.map((row) => [row.account, row.selectable])).toEqual([[1, true], [2, true], [3, false]]);
        expect(rows[0].connection.marketplace.code).toBe('shutterstock');
    });

    it('picks every account the batch can go to', () => {
        expect(defaultSelection(rows)).toEqual([1, 2]);
    });

    it('adds up uploads and counts each work with warnings once', () => {
        expect(selectionTotals(rows, [1, 2])).toEqual({ listings: 5, warned: 2 });
        expect(selectionTotals(rows, [1])).toEqual({ listings: 3, warned: 1 });
        expect(selectionTotals(rows, [])).toEqual({ listings: 0, warned: 0 });
    });

    it('names what is left on each marketplace that got work', () => {
        expect(nextSteps(rows, { 1: 3, 2: 0 })).toEqual([{ account: 1, name: 'shutterstock', csv: true, submit: true }]);
        expect(nextSteps(rows, {})).toEqual([]);
    });
});
