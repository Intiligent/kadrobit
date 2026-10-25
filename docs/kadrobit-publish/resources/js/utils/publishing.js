/**
 * Publishing to marketplaces (docs/publishing.md): the batch of ready works and the deliveries of a work
 * (asset.listings, App\Http\Resources\AssetListingResource). Pure functions for the toolbar button, the publish
 * popover, the cards and the Marketplace tab.
 */

/** In the queue or on its way: the work is refetched until it is over. */
export const PENDING_STATUSES = ['queued', 'sending'];

export const isPending = (listing) => PENDING_STATUSES.includes(listing.status);

/** Works that go out with the next batch: the publish button counts them. */
export const readyCount = (assets) => assets.filter((asset) => asset.status === 'ready').length;

export const hasPendingListings = (asset) => (asset.listings ?? []).some(isPending);

/**
 * How a listing is shown: `state` names the translation (library.publishing.status.*) and the colour.
 * A sent work edited afterwards is "outdated": the marketplace still has the old metadata.
 */
export function listingState(listing) {
    if (listing.status === 'sent' && listing.outdated) {
        return 'outdated';
    }

    return listing.status;
}

export const canCancel = (listing) => listing.status === 'queued';

/** Only a ready work is sent again: one moved out of the batch would be cancelled at once. */
export const canRetry = (listing, asset) => ['failed', 'cancelled'].includes(listing.status) && asset.status === 'ready';

/**
 * The listing of a work on one account, or null when the work was never queued there.
 */
export const listingFor = (asset, accountId) => (asset.listings ?? []).find((listing) => listing.account_id === accountId) ?? null;

/* Publish popover. Its data is the batch summary (GET /api/listings/batch): `accounts` per connected account,
   `items` per ready work with the checks of every pair the batch can still send. */

export const pairKey = (asset, account) => `${asset}|${account}`;

/**
 * Accounts of the summary still connected on the page. One can be picked when it is not blocked and the batch
 * still has something for it.
 */
export function batchAccounts(summary, accounts) {
    return summary.accounts
        .map((row) => ({ ...row, connection: accounts.find((account) => account.id === row.account) }))
        .filter((row) => row.connection)
        .map((row) => ({ ...row, selectable: !row.blocked && row.works > 0 }));
}

/** Picked by default: every account the batch can go to. */
export const defaultAccounts = (rows) => rows.filter((row) => row.selectable).map((row) => row.account);

/**
 * How one pair is shown and whether it goes out: a file the marketplace rejects never does ('error'); one the user
 * unticked does not ('skipped'); the rest go out, with or without warnings.
 */
export function pairState(pair, skipped) {
    if (pair.errors.length) {
        return 'error';
    }

    if (skipped) {
        return 'skipped';
    }

    return pair.warnings.length ? 'warning' : 'ok';
}

/**
 * Rows of the work list: each ready work with its pairs on the picked accounts.
 * `sending` of `total` is what the row's summary shows.
 *
 * @param {object} summary
 * @param {number[]} accounts Picked account ids
 * @param {Set<string>} works Ulids of the ticked works
 * @param {Set<string>} skipped pairKey()s the user unticked
 */
export function workRows(summary, accounts, works, skipped) {
    return summary.items.map((item) => {
        const pairs = item.accounts
            .filter((pair) => accounts.includes(pair.account))
            .map((pair) => ({ ...pair, state: pairState(pair, skipped.has(pairKey(item.asset, pair.account))) }));

        return {
            item,
            pairs,
            chosen: works.has(item.asset),
            // A work the batch has nothing left for (all sent) cannot be ticked.
            selectable: item.accounts.length > 0,
            sending: pairs.filter((pair) => pair.state === 'ok' || pair.state === 'warning').length,
            total: pairs.length,
        };
    });
}

/**
 * Totals of the footer over the ticked works, and the pairs to send (POST /api/listings/batch, `pairs`).
 */
export function publishTotals(rows) {
    const totals = { ok: 0, warning: 0, error: 0, pairs: [] };

    for (const row of rows.filter((r) => r.chosen)) {
        for (const pair of row.pairs) {
            if (pair.state === 'skipped') {
                continue;
            }

            totals[pair.state]++;

            if (pair.state !== 'error') {
                totals.pairs.push({ asset: row.item.asset, account: pair.account });
            }
        }
    }

    return totals;
}

/**
 * What is left to do on each marketplace after sending: submit for review in its portal, and for Shutterstock
 * upload a CSV there too (its FTPS server does not take one). From marketplaces.metadata_rules.
 */
export function nextSteps(rows, queued) {
    return rows
        .filter((row) => queued[row.account])
        .map((row) => {
            const { name, metadata_rules: rules = {} } = row.connection.marketplace;

            return { account: row.account, name, csv: rules.csv_upload === 'web', submit: rules.submit === 'web' };
        });
}
