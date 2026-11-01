import { MESSAGE, send } from '../core/messages.js';

/**
 * One pass of an adapter on its marketplace's site: take the sent works from Kadrobit, let the adapter find and
 * finish them, report back, and record what was done for the popup. Any failure is recorded too, never thrown.
 *
 * @param {import('../adapters/index.js').Adapter} adapter
 * @param {{name: string}} marketplace
 */
export async function run(adapter, marketplace) {
    const record = (action) => send(MESSAGE.ACTION, { marketplace: adapter.code, action });

    try {
        if (!(await adapter.isSignedIn())) {
            return record({ error: `Sign in to ${marketplace.name} in this browser.` });
        }

        const { busy, accounts, listings } = await send(MESSAGE.LISTINGS, { marketplace: adapter.code });

        // Another tab is on it: its action will be the one the popup shows.
        if (busy) {
            return null;
        }

        const autoSubmit = new Set(accounts.filter((account) => account.auto_submit).map((account) => account.id));

        const result = await adapter.sync(listings, { shouldSubmit: (listing) => autoSubmit.has(listing.account_id) });
        await send(MESSAGE.REPORT, { reports: result.reports });

        return record({ found: result.found, submitted: result.submitted, errors: result.errors });
    } catch (error) {
        return record({ error: error.message }).catch(() => null);
    }
}

// An automatic pass at most this often per marketplace in a tab; "Check now" in the popup runs at once.
export const AUTO_RUN_INTERVAL_MS = 5 * 60_000;

export function dueForAutoRun(code, storage = sessionStorage, now = Date.now()) {
    const key = `kadrobit:last-run:${code}`;
    const last = Number(storage.getItem(key) ?? 0);

    if (now - last < AUTO_RUN_INTERVAL_MS) {
        return false;
    }

    storage.setItem(key, String(now));

    return true;
}
