import { MESSAGE, send } from '../core/messages.js';

// A sent work waits to be submitted to review this long at most, as on the server (config/extension.php,
// await_review_hours): a file the marketplace never shows does not keep the passes frequent.
const AWAIT_REVIEW_MS = 24 * 3600_000;

/**
 * Works that should still go to review by themselves: of an account with auto submit, sent lately, no review
 * status yet, even after this pass's reports.
 */
export function awaitingReview(listings, autoSubmit, reports = [], now = Date.now()) {
    const reviewed = new Set(reports.filter((report) => report.moderation_status).map((report) => report.id));

    return listings.some((listing) => autoSubmit.has(listing.account_id)
        && !listing.moderation_status
        && !reviewed.has(listing.id)
        && now - Date.parse(listing.sent_at ?? 0) < AWAIT_REVIEW_MS);
}

/**
 * One pass of an adapter on its marketplace's site: take the sent works from Kadrobit, let the adapter find and
 * finish them, report back, and record what was done for the popup. Any failure is recorded too, never thrown.
 * Answers whether works still wait to go to review (the next automatic pass comes sooner), or null when the pass
 * did not run.
 *
 * @param {import('../adapters/index.js').Adapter} adapter
 * @param {{name: string}} marketplace
 * @returns {Promise<{awaiting: boolean}|null>}
 */
export async function run(adapter, marketplace) {
    const record = (action) => send(MESSAGE.ACTION, { marketplace: adapter.code, action });

    try {
        if (!(await adapter.isSignedIn())) {
            await record({ error: `Sign in to ${marketplace.name} in this browser.` });

            return null;
        }

        const { busy, accounts, listings } = await send(MESSAGE.LISTINGS, { marketplace: adapter.code });

        // Another tab is on it: its action will be the one the popup shows.
        if (busy) {
            return null;
        }

        const autoSubmit = new Set(accounts.filter((account) => account.auto_submit).map((account) => account.id));

        const result = await adapter.sync(listings, { shouldSubmit: (listing) => autoSubmit.has(listing.account_id) });
        await send(MESSAGE.REPORT, { reports: result.reports });
        await record({ found: result.found, submitted: result.submitted, errors: result.errors });

        return { awaiting: awaitingReview(listings, autoSubmit, result.reports) };
    } catch (error) {
        await record({ error: error.message }).catch(() => null);

        return null;
    }
}

// An automatic pass at most this often per marketplace in a tab, and only while the tab is visible; "Check now" in
// the popup runs at once.
export const AUTO_RUN_INTERVAL_MS = 5 * 60_000;

// While works wait to go to review: every minute, in a background tab too (Chrome wakes its timers once a minute).
export const AWAITING_RUN_INTERVAL_MS = 60_000;

const stateKey = (code) => `kadrobit:auto-run:${code}`;

function readState(code, storage) {
    try {
        return JSON.parse(storage.getItem(stateKey(code))) ?? {};
    } catch {
        return {};
    }
}

/**
 * Whether an automatic pass is due in this tab; when it is, the pass counts as started. The tab's sessionStorage
 * keeps the time over reloads, so reloading the page does not run the pass again.
 */
export function dueForAutoRun(code, { visible = true, storage = sessionStorage, now = Date.now() } = {}) {
    const state = readState(code, storage);
    const interval = state.awaiting ? AWAITING_RUN_INTERVAL_MS : AUTO_RUN_INTERVAL_MS;

    if ((!visible && !state.awaiting) || now - (state.last ?? 0) < interval) {
        return false;
    }

    storage.setItem(stateKey(code), JSON.stringify({ ...state, last: now }));

    return true;
}

/** Remembers whether works wait to go to review after a pass: it sets how soon the next one comes. */
export function rememberAwaiting(code, awaiting, storage = sessionStorage) {
    storage.setItem(stateKey(code), JSON.stringify({ ...readState(code, storage), awaiting }));
}
