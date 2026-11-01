import rf123 from './rf123.js';
import shutterstock from './shutterstock.js';

/**
 * Adapters of stock sites. Each marketplace has its own way to find the files Kadrobit delivered, to finish them
 * (categories, Proceed, the upload itself) and to read the review status; the adapter hides it behind one shape.
 * They come with the marketplace stages: Shutterstock (KB-84), 123RF (KB-80), Adobe Stock (KB-81).
 *
 * An adapter runs in the content script on the marketplace's site, so its requests to the site's internal API
 * carry the author's session cookies. It never sees passwords and never signs in by itself.
 *
 * @typedef {object} Listing  A sent work, as GET /api/extension/listings gives it
 * @property {number} id
 * @property {number} account_id
 * @property {string} remote_filename  Name of the file Kadrobit delivered: how the adapter finds the work
 * @property {string|null} external_id
 * @property {string|null} external_url
 * @property {'pending'|'accepted'|'rejected'|null} moderation_status
 * @property {boolean} outdated  The marketplace has older metadata
 * @property {{title: string, description: string, keywords: string[], categories: {id: string, name: string}[], is_editorial: boolean, is_ai_generated: boolean}} metadata
 * @property {string} metadata_hash
 *
 * @typedef {object} Report  What the adapter found out about one work; fields it does not know are left out
 * @property {number} id
 * @property {string} [external_id]
 * @property {string} [external_url]
 * @property {'pending'|'accepted'|'rejected'} [moderation_status]
 * @property {string} [metadata_hash]  The listing's metadata_hash, once its metadata is on the marketplace
 *
 * @typedef {object} SyncResult
 * @property {Report[]} reports
 * @property {number} found  Works of Kadrobit found on the site
 * @property {number} submitted  Sent to review this time
 * @property {string[]} errors  Per-work problems, e.g. "IMG_1.jpg: no category"
 *
 * @typedef {object} Adapter
 * @property {string} code  marketplaces.code, as in src/marketplaces.js
 * @property {() => Promise<boolean>} isSignedIn  The author is signed in to the marketplace in this browser
 * @property {(listings: Listing[], options: {shouldSubmit: (listing: Listing) => boolean}) => Promise<SyncResult>} sync
 *   Finds the works, fills in what the files do not carry, submits those shouldSubmit() allows (the account's auto
 *   submit) and reads the review status. Answers of the site's API are checked with core/shape.js.
 */

/** @type {Adapter[]} */
export const ADAPTERS = [shutterstock, rf123];

export function adapterFor(code) {
    return ADAPTERS.find((adapter) => adapter.code === code) ?? null;
}
