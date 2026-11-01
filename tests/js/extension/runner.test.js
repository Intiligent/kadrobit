import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MESSAGE } from '../../../extension/src/core/messages.js';
import { AUTO_RUN_INTERVAL_MS, dueForAutoRun, run } from '../../../extension/src/content/runner.js';
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

describe('dueForAutoRun', () => {
    const storage = () => {
        const items = {};

        return { getItem: (k) => items[k] ?? null, setItem: (k, v) => { items[k] = v; } };
    };

    it('runs once per interval and marketplace', () => {
        const store = storage();
        const now = 1_000_000;

        expect(dueForAutoRun('shutterstock', store, now)).toBe(true);
        expect(dueForAutoRun('shutterstock', store, now + 1000)).toBe(false);
        expect(dueForAutoRun('123rf', store, now + 1000)).toBe(true);
        expect(dueForAutoRun('shutterstock', store, now + AUTO_RUN_INTERVAL_MS)).toBe(true);
    });
});
