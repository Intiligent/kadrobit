import { describe, expect, it } from 'vitest';
import {
    batchAccounts,
    canCancel,
    canRetry,
    defaultAccounts,
    firstWorks,
    hasPendingListings,
    listingFor,
    listingState,
    nextSteps,
    pairKey,
    pairState,
    publishTotals,
    readyCount,
    workRows,
} from '../../resources/js/utils/publishing';

const connection = (id, code, rules = {}) => ({ id, label: code, marketplace: { code, name: code, metadata_rules: rules } });

const accounts = [
    connection(1, 'shutterstock', { csv_upload: 'web', submit: 'web' }),
    connection(2, 'alamy'),
    connection(3, 'adobe_stock', { submit: 'web' }),
    connection(4, 'dreamstime'),
    connection(5, 'pond5'),
];

const warning = { key: 'categories_min', params: {} };
const error = { key: 'resolution_min', params: { mp: 3.1, min: 4 } };

const summary = {
    works: 3,
    accounts: [
        { account: 1, blocked: null, works: 2, unsupported: 1, warnings: [] },
        { account: 2, blocked: null, works: 3, unsupported: 0, warnings: [] },
        { account: 3, blocked: 'invalid_credentials', works: 0, unsupported: 0, warnings: [] },
        // Every file rejected: still picked, so the reasons show
        { account: 4, blocked: null, works: 0, unsupported: 1, warnings: [] },
        // Everything sent already
        { account: 5, blocked: null, works: 0, unsupported: 0, warnings: [] },
        // Gone from the page's accounts meanwhile
        { account: 9, blocked: null, works: 1, unsupported: 0, warnings: [] },
    ],
    items: [
        { asset: 'a', accounts: [{ account: 1, errors: [error], warnings: [warning] }, { account: 2, errors: [], warnings: [] }] },
        { asset: 'b', accounts: [{ account: 1, errors: [], warnings: [warning] }, { account: 2, errors: [], warnings: [] }] },
        { asset: 'c', accounts: [{ account: 1, errors: [], warnings: [] }, { account: 2, errors: [], warnings: [] }] },
        // Sent everywhere already
        { asset: 'd', accounts: [] },
    ],
};

describe('ready works and listings', () => {
    it('counts the ready works the batch still has a pair for', () => {
        const stock = (id, status = 'active') => ({
            id,
            status,
            marketplace: { code: `stock${id}`, supported_types: ['photo'], metadata_rules: {}, file_rules: { photo: { min_megapixels: 4 } } },
        });
        const work = (overrides = {}) => ({
            status: 'ready', type: 'photo', title: 'Lagoon', keywords: ['ice'], width: 3000, height: 2000, listings: [], ...overrides,
        });
        const works = [
            work(),
            work({ status: 'draft' }),
            work({ status: 'submitted' }),
            // Too small for every marketplace: counted, the popover says why it does not go out
            work({ width: 1000, height: 1000 }),
            // Not a type these marketplaces take: counted too, rejected in the popover
            work({ type: 'video' }),
            // Sent to one, queued on the other: nothing left
            work({ listings: [{ account_id: 1, status: 'sent' }, { account_id: 2, status: 'queued' }] }),
            // Sent to one, the other is still open
            work({ listings: [{ account_id: 1, status: 'sent' }] }),
            // A failed or cancelled upload goes out again
            work({ listings: [{ account_id: 1, status: 'failed' }, { account_id: 2, status: 'sent' }] }),
        ];

        expect(readyCount(works, [stock(1), stock(2)])).toBe(5);
        expect(readyCount(works, [stock(1), stock(2, 'disabled')])).toBe(4);
        expect(readyCount(works, [stock(1, 'invalid_credentials')])).toBe(0);
        expect(readyCount(works, [])).toBe(0);
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

describe('publish popover', () => {
    const rows = batchAccounts(summary, accounts);
    const all = new Set(['a', 'b', 'c', 'd']);

    it('lists the summary accounts still connected and picks every one the batch can go to', () => {
        expect(rows.map((row) => [row.account, row.selectable])).toEqual([[1, true], [2, true], [3, false], [4, true], [5, false]]);
        expect(rows[0].connection.marketplace.code).toBe('shutterstock');
        expect(defaultAccounts(rows)).toEqual([1, 2, 4]);
    });

    it('ticks the works the batch has something for, up to the limit of one send', () => {
        expect([...firstWorks({ ...summary, limit: 100 })]).toEqual(['a', 'b', 'c']);
        expect([...firstWorks({ ...summary, limit: 2 })]).toEqual(['a', 'b']);
    });

    it('never sends a rejected file, and sends the rest unless unticked', () => {
        expect(pairState({ errors: [error], warnings: [] }, false)).toBe('error');
        expect(pairState({ errors: [error], warnings: [] }, true)).toBe('error');
        expect(pairState({ errors: [], warnings: [warning] }, true)).toBe('skipped');
        expect(pairState({ errors: [], warnings: [warning] }, false)).toBe('warning');
        expect(pairState({ errors: [], warnings: [] }, false)).toBe('ok');
    });

    it('shows each work with its pairs on the picked accounts', () => {
        const works = workRows(summary, [1, 2], all, new Set());

        expect(works.map((w) => [w.item.asset, w.sending, w.total])).toEqual([['a', 1, 2], ['b', 2, 2], ['c', 2, 2], ['d', 0, 0]]);
        expect(works[0].pairs.map((p) => p.state)).toEqual(['error', 'ok']);
        expect(works[3].selectable).toBe(false);
        expect(workRows(summary, [2], all, new Set())[0].pairs.map((p) => p.account)).toEqual([2]);
    });

    it('adds up the footer over ticked works and leaves rejected and unticked pairs out', () => {
        const totals = publishTotals(workRows(summary, [1, 2], all, new Set()));

        expect([totals.ok, totals.warning, totals.error]).toEqual([4, 1, 1]);
        expect(totals.pairs).toHaveLength(5);
        expect(totals.pairs).not.toContain('a|1');

        const fewer = publishTotals(workRows(summary, [1, 2], new Set(['a', 'b']), new Set([pairKey('b', 1)])));

        expect([fewer.ok, fewer.warning, fewer.error]).toEqual([2, 0, 1]);
        expect(fewer.pairs).toEqual(['a|2', 'b|2']);
    });

    it('names what is left on each marketplace that got work', () => {
        expect(nextSteps(rows, { 1: 3, 2: 0 })).toEqual([{ account: 1, name: 'shutterstock', csv: true, submit: true }]);
        expect(nextSteps(rows, {})).toEqual([]);
    });
});
