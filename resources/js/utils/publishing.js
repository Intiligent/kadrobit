/**
 * Publishing to marketplaces (docs/publishing.md): the batch of ready works and the deliveries of a work
 * (asset.listings, App\Http\Resources\AssetListingResource). Pure functions for the toolbar button, the publish
 * dialog, the cards and the Marketplace tab.
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

/**
 * Rows of the publish dialog: the accounts of the batch summary (GET /api/listings/batch) with their connection.
 * An account can be picked when it is not blocked and gets at least one work.
 */
export function batchRows(summary, accounts) {
    return summary.accounts
        .map((row) => ({ ...row, connection: accounts.find((account) => account.id === row.account) }))
        .filter((row) => row.connection)
        .map((row) => ({ ...row, selectable: !row.blocked && row.works > 0 }));
}

/** Picked by default: every account the batch can go to. */
export const defaultSelection = (rows) => rows.filter((row) => row.selectable).map((row) => row.account);

/**
 * Works that go out with the chosen accounts, each counted once; and how many of them have warnings somewhere.
 */
export function selectionTotals(rows, selected) {
    const chosen = rows.filter((row) => selected.includes(row.account));
    const warned = new Set(chosen.flatMap((row) => row.warnings.map((item) => item.asset)));

    return {
        listings: chosen.reduce((sum, row) => sum + row.works, 0),
        warned: warned.size,
    };
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
