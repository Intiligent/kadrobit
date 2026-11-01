import { expectShape } from '../core/shape.js';

/**
 * Shutterstock (docs/marketplaces/shutterstock/README.md): the contributor site submit.shutterstock.com works
 * through its internal API /api/next/…, authorized by the author's session cookies.
 *
 * - "Not submitted" (media/list, workflowStatus=ingested): Kadrobit's files get their categories and, for edited
 *   works, the current metadata (bulk/update); then, with the account's auto submit, they go to review (submit).
 * - "Pending" and "Recently reviewed" (media/review/list): the review status of each work.
 *
 * A work is found by the name Kadrobit gave its file (originalFilename) and must have been uploaded no earlier than
 * Kadrobit sent it: authors upload files with the same names by hand too.
 */
const API = '/api/next';
const PER_PAGE = 100;
const MEDIA_TYPES = ['photo', 'video'];

// Works in "Not submitted" have only `id` ("U4224158505") and `created`; reviewed ones also `mediaId` (the same
// number) and `uploadedDate`.
const ITEM = {
    id: 'string',
    'mediaId?': 'number',
    originalFilename: 'string',
    'uploadedDate?': 'string',
    'created?': 'string',
    'categories?': ['string'],
    'status?': 'string',
};

/** The work's number on Shutterstock: the same in every list, the ID Kadrobit keeps. */
export const mediaId = (item) => String(item.mediaId ?? item.id.replace(/^\D+/, ''));

/** The day the file reached Shutterstock, as YYYY-MM-DD. */
const uploadedDay = (item) => item.uploadedDate ?? item.created?.slice(0, 10) ?? null;

const PAGE = { data: [ITEM], totalCount: 'number' };

const REVIEW_STATUS = { pending: 'pending', approved: 'accepted', rejected: 'rejected' };

export class ShutterstockError extends Error {}

async function call(path, { method = 'GET', body } = {}) {
    const response = await fetch(`${API}${path}`, {
        method,
        credentials: 'include',
        redirect: 'manual',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) {
        throw new ShutterstockError('Sign in to Shutterstock in this browser.');
    }

    if (!response.ok) {
        throw new ShutterstockError(`Shutterstock answered ${response.status} to ${path.split('?')[0]}`);
    }

    return response.json();
}

async function allPages(path, source) {
    const items = [];

    for (let page = 1; ; page++) {
        const json = expectShape(await call(`${path}&page=${page}&perPage=${PER_PAGE}&normalize=true`), PAGE, source);
        items.push(...json.data);

        if (!json.data.length || items.length >= json.totalCount) {
            return items;
        }
    }
}

/**
 * Category IDs by the names Kadrobit keeps (marketplace_categories); the code catches names that differ
 * ("Arts" here, "The Arts" on the site).
 */
async function categoryIds(mediaType) {
    const categories = expectShape(await call(`/media/categories?media_type=${mediaType}`), [{ catId: 'number', name: 'string', code: 'string' }], 'media/categories');
    const ids = new Map();

    for (const { catId, name, code } of categories) {
        ids.set(name.toLowerCase(), String(catId));
        ids.set(code, String(catId));
    }

    return (name) => ids.get(name.toLowerCase()) ?? ids.get(slug(name)) ?? null;
}

const slug = (name) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/**
 * The listing a site item belongs to: same file name, uploaded the day Kadrobit sent it or later.
 */
export function matcher(listings) {
    const byName = new Map(listings.map((listing) => [listing.remote_filename, listing]));

    return (item) => {
        const listing = byName.get(item.originalFilename);

        if (!listing) {
            return null;
        }

        // Dates on the site are the author's calendar day; a day of slack covers the time zone.
        const sent = listing.sent_at ? new Date(Date.parse(listing.sent_at) - 86_400_000).toISOString().slice(0, 10) : null;

        const uploaded = uploadedDay(item);

        return !sent || !uploaded || uploaded >= sent ? listing : null;
    };
}

export function publicUrl(mediaId) {
    return `https://www.shutterstock.com/image-photo/-${mediaId}`;
}

/**
 * The fields bulk/update takes for one work: Shutterstock has one text field, the description.
 */
export function updateBody(item, listing, categories) {
    return {
        id: item.id,
        description: listing.metadata.description || listing.metadata.title,
        keywords: listing.metadata.keywords,
        categories,
        releases: item.releases ?? [],
        is_adult: item.isAdult ?? false,
        submitter_note: item.submitterNote ?? '',
    };
}

/** @type {import('./index.js').Adapter} */
export default {
    code: 'shutterstock',

    async isSignedIn() {
        try {
            await call('/users/current/account_settings');

            return true;
        } catch {
            return false;
        }
    },

    async sync(listings, { shouldSubmit }) {
        const find = matcher(listings);
        const reports = new Map();
        const errors = [];
        let submitted = 0;

        const report = (listing, fields) => reports.set(listing.id, { ...reports.get(listing.id), id: listing.id, ...fields });

        for (const mediaType of MEDIA_TYPES) {
            // Not submitted: categories and metadata first, then review.
            const ingested = (await allPages(`/media/list?mediaType=${mediaType}&workflowStatus=ingested&order=newest`, 'media/list'))
                .map((item) => ({ item, listing: find(item) }))
                .filter(({ listing }) => listing);

            if (ingested.length) {
                const category = await categoryIds(mediaType);
                const updates = [];
                const ready = [];

                for (const { item, listing } of ingested) {
                    report(listing, { external_id: mediaId(item) });

                    const names = listing.metadata.categories.map((c) => c.name);
                    const ids = names.map(category);
                    const unknown = names.filter((name, index) => !ids[index]);

                    if (unknown.length || !ids.length) {
                        errors.push(`${listing.remote_filename}: ${unknown.length ? `unknown category ${unknown.join(', ')}` : 'no category in Kadrobit'}`);
                        continue;
                    }

                    const sameCategories = [...(item.categories ?? [])].sort().join() === [...ids].sort().join();

                    if (listing.outdated || !sameCategories) {
                        updates.push({ item, listing, body: updateBody(item, listing, ids) });
                    }

                    ready.push({ item, listing });
                }

                if (updates.length) {
                    await call('/media/bulk/update', {
                        method: 'POST',
                        body: { ids: updates.map((u) => u.item.id), updateBody: updates.map((u) => u.body) },
                    });
                }

                // The metadata on the site is now the work's.
                ready.forEach(({ listing }) => report(listing, { metadata_hash: listing.metadata_hash }));

                const toSubmit = ready.filter(({ listing }) => shouldSubmit(listing));

                if (toSubmit.length) {
                    const media = toSubmit.map(({ item }) => ({ mediaType, mediaId: item.id }));
                    await call('/media/submit', { method: 'POST', body: { media, skipSpellcheck: false, preview: 'false' } });
                    toSubmit.forEach(({ listing }) => report(listing, { moderation_status: 'pending' }));
                    submitted += toSubmit.length;
                }
            }

            // Review: what is pending, what was accepted or rejected in the last weeks.
            for (const status of ['pending', 'reviewed']) {
                const items = await allPages(`/media/review/list?mediaType=${mediaType}&status=${status}`, 'media/review/list');

                for (const item of items) {
                    const listing = find(item);
                    const moderation = REVIEW_STATUS[item.status];

                    if (listing && moderation) {
                        report(listing, {
                            external_id: mediaId(item),
                            moderation_status: moderation,
                            ...(moderation === 'accepted' ? { external_url: publicUrl(mediaId(item)) } : {}),
                        });
                    }
                }
            }
        }

        return { reports: [...reports.values()], found: reports.size, submitted, errors };
    },
};
