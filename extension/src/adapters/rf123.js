import { expectShape } from '../core/shape.js';

/**
 * 123RF (docs/marketplaces/123rf/README.md): the contributor area www.123rf.com/contributor works through its
 * internal API /apicore-contributors/…, authorized by the author's session cookies.
 *
 * - "Upload Via FTP" (ftp): files Kadrobit delivered wait there until Proceed (process_images/prequeue) takes them
 *   in; it takes every file on the FTP, as the button on the site does, and accepts 123RF's terms, so it runs only
 *   with the account's auto submit.
 * - "Draft" (draft_items): the works after processing, with the description and keywords 123RF read from the file;
 *   with auto submit they go to review (draft_items/to_pending).
 * - "Pending", "Approved", "Rejected" (review_images): the review status of each work.
 *
 * 123RF keeps the name of the uploaded file without its extension (oldFilename), so a work is found by the name
 * Kadrobit gave its file without the extension: a vector's EPS and its JPEG share it. It must have been uploaded no
 * earlier than Kadrobit sent it: authors upload files with the same names by hand too.
 */
const API = '/apicore-contributors';
const PER_PAGE = 100;

// content_type of the draft and review lists: photos with illustrations, and vectors apart. Without other filters:
// `type=image_rf` the site adds on some pages shows only part of the works.
const CONTENT_TYPES = ['photo', 'vector'];
const REVIEW_STATUSES = ['pending', 'accepted', 'rejected'];

// After Proceed the files become drafts within seconds: the pass waits for them that long at most.
export const PROCESSING = { timeoutMs: 60_000, intervalMs: 3_000 };

// The site submits a draft only with a description and at least this many keywords.
export const MIN_KEYWORDS = 7;

const PAGE = { data: ['object'], meta: { pagination: { total_pages: 'number' } } };

const FTP_FILE = { filename: 'string' };

const DRAFT = { id: 'any', oldFilename: 'string', 'createdAt?': 'string', kwDesc: { 'description?': 'string', keywords: ['string'] } };

const REVIEWED = { filename: 'string', oldFilename: 'string', 'createdAt?': 'string' };

export class Rf123Error extends Error {}

async function call(path, { method = 'GET', body } = {}) {
    const response = await fetch(`${API}${path}`, {
        method,
        credentials: 'include',
        redirect: 'manual',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) {
        throw new Rf123Error('Sign in to 123RF in this browser.');
    }

    if (!response.ok) {
        throw new Rf123Error(`123RF answered ${response.status} to ${path.split('?')[0]}`);
    }

    // Empty lists come as 204 No Content.
    return response.status === 204 ? null : response.json();
}

async function allPages(path, item, source) {
    const items = [];

    for (let page = 1; ; page++) {
        const json = await call(`${path}&page=${page}&limit=${PER_PAGE}`);

        if (!json) {
            return items;
        }

        const { data, meta } = expectShape(json, PAGE, source);
        items.push(...data.map((entry) => expectShape(entry, item, source)));

        if (!data.length || page >= meta.pagination.total_pages) {
            return items;
        }
    }
}

/** The name of a file without its extension: how 123RF keeps it. */
export const baseName = (filename) => filename.replace(/\.[^.]+$/, '');

/**
 * The listing a site item belongs to: same file name without extension, uploaded the day Kadrobit sent it or later.
 */
export function matcher(listings) {
    const byName = new Map(listings.map((listing) => [baseName(listing.remote_filename), listing]));

    return (name, uploadedAt = null) => {
        const listing = byName.get(baseName(name));

        if (!listing) {
            return null;
        }

        // createdAt is the day only ("2026-10-31 00:00:00"); a day of slack covers the time zone.
        const sent = listing.sent_at ? new Date(Date.parse(listing.sent_at) - 86_400_000).toISOString().slice(0, 10) : null;
        const uploaded = uploadedAt?.slice(0, 10) ?? null;

        return !sent || !uploaded || uploaded >= sent ? listing : null;
    };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Waits until 123RF has processed the files Proceed took: nothing left in processing (an empty list comes as 204).
 */
async function processed() {
    for (const deadline = Date.now() + PROCESSING.timeoutMs; Date.now() < deadline;) {
        await sleep(PROCESSING.intervalMs);

        if (!(await call('/process_images?page=1&limit=1&filetype=all_image'))?.data?.length) {
            return;
        }
    }
}

/** Why the site would not submit a draft, or null. */
function draftProblem(draft) {
    if (!draft.kwDesc.description?.trim()) {
        return 'no description in the file';
    }

    return draft.kwDesc.keywords.length < MIN_KEYWORDS ? `fewer than ${MIN_KEYWORDS} keywords` : null;
}

/** @type {import('./index.js').Adapter} */
export default {
    code: '123rf',

    async isSignedIn() {
        try {
            await call('/profile');

            return true;
        } catch {
            return false;
        }
    },

    async sync(listings, { shouldSubmit }) {
        const find = matcher(listings);
        const reports = new Map();
        const found = new Set();
        const errors = [];
        let submitted = 0;

        const report = (listing, fields) => reports.set(listing.id, { ...reports.get(listing.id), id: listing.id, ...fields });
        const seen = (listing) => found.add(listing.id) && listing;

        // Upload Via FTP: Proceed when a file of an auto-submitting account waits there.
        const ftp = expectShape((await call('/ftp?license_type=commercial&media_type=image'))?.data ?? [], [FTP_FILE], 'ftp')
            .map((file) => find(file.filename))
            .filter(Boolean)
            .map(seen);

        if (ftp.some(shouldSubmit)) {
            await call('/process_images/prequeue?upload_method=ftp&allow_conversion=0&license_type=commercial&media_type=image', { method: 'POST' });
            await processed();
        }

        // Drafts: the description and keywords came from the file; those of auto-submitting accounts go to review.
        for (const contentType of CONTENT_TYPES) {
            const drafts = (await allPages(`/draft_items?content_type=${contentType}`, DRAFT, 'draft_items'))
                .map((draft) => ({ draft, listing: find(draft.oldFilename, draft.createdAt) }))
                .filter(({ listing }) => listing);

            const toSubmit = [];

            for (const { draft, listing } of drafts) {
                seen(listing);

                // A work edited after sending goes with the metadata of its file: the draft is not rewritten, and
                // Kadrobit keeps showing it as outdated.
                if (!shouldSubmit(listing)) {
                    continue;
                }

                const problem = draftProblem(draft);

                if (problem) {
                    errors.push(`${listing.remote_filename}: ${problem}`);
                } else {
                    toSubmit.push({ draft, listing });
                }
            }

            if (toSubmit.length) {
                await call('/draft_items/to_pending', {
                    method: 'POST',
                    body: { data: { type: 'draft_item', attributes: { draft_ids: toSubmit.map(({ draft }) => draft.id) } } },
                });
                toSubmit.forEach(({ listing }) => report(listing, { moderation_status: 'pending' }));
                submitted += toSubmit.length;
            }
        }

        // Review: 123RF names the work anew (filename), the ID Kadrobit keeps.
        for (const contentType of CONTENT_TYPES) {
            for (const status of REVIEW_STATUSES) {
                const items = await allPages(`/review_images?status=${status}&content_type=${contentType}`, REVIEWED, 'review_images');

                for (const item of items) {
                    const listing = find(item.oldFilename, item.createdAt);

                    if (listing) {
                        seen(listing);
                        report(listing, { external_id: item.filename, moderation_status: status });
                    }
                }
            }
        }

        return { reports: [...reports.values()], found: found.size, submitted, errors };
    },
};
