import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MESSAGE } from '../../../extension/src/core/messages.js';
import { AUTO_RUN_INTERVAL_MS, AWAITING_RUN_INTERVAL_MS, awaitingReview, dueForAutoRun, rememberAwaiting, run } from '../../../extension/src/content/runner.js';
import { fakeChrome } from './helpers.js';

const listing = (id, accountId) => ({ id, account_id: accountId, remote_filename: `${id}.jpg`, metadata_hash: `h${id}` });

describe('run', () => {
    let messages;

    beforeEach(() => {
        messages = [];
        fakeChrome({
            onMessage: async (message) => {
                messages.push(message);

                if (message.type === MESSAGE.LISTINGS) {
                    return {
                        accounts: [{ id: 1, auto_submit: true }, { id: 2, auto_submit: false }],
                        listings: [listing(10, 1), listing(11, 2)],
                    };
                }

                return null;
            },
        });
    });

    it('lets the adapter submit only works of accounts with auto submit, then reports and records', async () => {
        const adapter = {
            code: 'shutterstock',
            isSignedIn: async () => true,
            sync: vi.fn(async (listings, { shouldSubmit }) => ({
                reports: listings.map((l) => ({ id: l.id, external_id: `U${l.id}` })),
                found: listings.length,
                submitted: listings.filter(shouldSubmit).length,
                errors: [],
            })),
        };

        await run(adapter, { name: 'Shutterstock' });

        await expect(adapter.sync.mock.results[0].value).resolves.toMatchObject({ submitted: 1 });
        expect(messages.find((m) => m.type === MESSAGE.REPORT).reports).toEqual([{ id: 10, external_id: 'U10' }, { id: 11, external_id: 'U11' }]);
        expect(messages.find((m) => m.type === MESSAGE.ACTION)).toEqual({
            type: MESSAGE.ACTION,
            marketplace: 'shutterstock',
            action: { found: 2, submitted: 1, errors: [] },
        });
    });

    it('asks to sign in to the marketplace instead of touching it', async () => {
        const adapter = { code: 'shutterstock', isSignedIn: async () => false, sync: vi.fn() };

        await run(adapter, { name: 'Shutterstock' });

        expect(adapter.sync).not.toHaveBeenCalled();
        expect(messages.at(-1).action).toEqual({ error: 'Sign in to Shutterstock in this browser.' });
    });

    it('records a failure, e.g. a changed site, instead of throwing', async () => {
        const adapter = { code: 'shutterstock', isSignedIn: async () => true, sync: async () => { throw new Error('The site changed: media/list has no list at data'); } };

        await run(adapter, { name: 'Shutterstock' });

        expect(messages.some((m) => m.type === MESSAGE.REPORT)).toBe(false);
        expect(messages.at(-1).action).toEqual({ error: 'The site changed: media/list has no list at data' });
    });
});

describe('run while another tab runs', () => {
    it('leaves the marketplace alone and records nothing', async () => {
        const messages = [];
        fakeChrome({
            onMessage: async (message) => {
                messages.push(message);

                return message.type === MESSAGE.LISTINGS ? { busy: true } : null;
            },
        });
        const adapter = { code: 'shutterstock', isSignedIn: async () => true, sync: vi.fn() };

        await run(adapter, { name: 'Shutterstock' });

        expect(adapter.sync).not.toHaveBeenCalled();
        expect(messages.map((m) => m.type)).toEqual([MESSAGE.LISTINGS]);
    });
});

describe('dueForAutoRun', () => {
    const storage = () => {
        const items = {};

        return { getItem: (k) => items[k] ?? null, setItem: (k, v) => { items[k] = v; } };
    };

    it('runs once per interval and marketplace, in a visible tab', () => {
        const store = storage();
        const now = 1_000_000;
        const at = (time, visible = true) => ({ storage: store, now: time, visible });

        expect(dueForAutoRun('shutterstock', at(now))).toBe(true);
        expect(dueForAutoRun('shutterstock', at(now + 1000))).toBe(false);
        expect(dueForAutoRun('123rf', at(now + 1000))).toBe(true);
        expect(dueForAutoRun('shutterstock', at(now + AUTO_RUN_INTERVAL_MS, false))).toBe(false);
        expect(dueForAutoRun('shutterstock', at(now + AUTO_RUN_INTERVAL_MS))).toBe(true);
    });

    it('runs every minute, in a background tab too, while works wait to go to review', () => {
        const store = storage();
        const now = 1_000_000;

        dueForAutoRun('123rf', { storage: store, now });
        rememberAwaiting('123rf', true, store);

        expect(dueForAutoRun('123rf', { storage: store, now: now + AWAITING_RUN_INTERVAL_MS - 1, visible: false })).toBe(false);
        expect(dueForAutoRun('123rf', { storage: store, now: now + AWAITING_RUN_INTERVAL_MS, visible: false })).toBe(true);

        rememberAwaiting('123rf', false, store);

        expect(dueForAutoRun('123rf', { storage: store, now: now + 2 * AWAITING_RUN_INTERVAL_MS })).toBe(false);
    });
});

describe('awaitingReview', () => {
    const now = Date.parse('2026-11-01T18:00:00Z');
    const sent = (id, attributes = {}) => ({ id, account_id: 1, sent_at: '2026-11-01T17:40:00Z', moderation_status: null, ...attributes });

    it('counts recent works of auto-submitting accounts with no review status yet', () => {
        expect(awaitingReview([sent(1)], new Set([1]), [], now)).toBe(true);
        expect(awaitingReview([sent(1)], new Set([1]), [{ id: 1, moderation_status: 'pending' }], now)).toBe(false);
        expect(awaitingReview([sent(1, { moderation_status: 'pending' })], new Set([1]), [], now)).toBe(false);
        expect(awaitingReview([sent(1)], new Set([2]), [], now)).toBe(false);
        expect(awaitingReview([sent(1, { sent_at: '2026-10-30T10:00:00Z' })], new Set([1]), [], now)).toBe(false);
    });
});
