import { afterEach, describe, expect, it, vi } from 'vitest';
import rf123, { PROCESSING, matcher } from '../../../extension/src/adapters/rf123.js';
import { ShapeError } from '../../../extension/src/core/shape.js';
import { jsonResponse } from './helpers.js';

const listing = (id, name, attributes = {}) => ({
    id,
    account_id: 1,
    remote_filename: name,
    sent_at: '2026-10-31T10:00:00+00:00',
    outdated: false,
    metadata: { title: 'Glacier', description: 'Icebergs in a glacier lagoon', keywords: ['ice', 'lagoon'], categories: [] },
    metadata_hash: `hash-${id}`,
    ...attributes,
});

const KEYWORDS = ['ice', 'lagoon', 'glacier', 'iceland', 'blue', 'cold', 'water'];

const draft = (id, name, attributes = {}) => ({ id, oldFilename: name, createdAt: '2026-11-01 00:00:00', kwDesc: { description: 'Icebergs', keywords: KEYWORDS }, ...attributes });

const reviewed = (filename, name) => ({ filename, oldFilename: name, createdAt: '2026-11-01 00:00:00' });

const page = (data) => jsonResponse(200, { data, meta: { pagination: { total: data.length, total_pages: 1 } }, status: true });

/**
 * The site's API: lists by path (photos only), every POST recorded.
 */
function fakeSite({ ftp = [], drafts = [], review = {}, proceeded = [], processing = 0 } = {}) {
    const posts = [];
    // Checks of the drafts before the proceeded files show there.
    let left = 0;

    vi.stubGlobal('fetch', vi.fn(async (url, { method = 'GET', body } = {}) => {
        if (method === 'POST') {
            posts.push({ url, body: body ? JSON.parse(body) : null });

            // Proceed: the FTP files turn into drafts a few checks later.
            if (url.includes('/prequeue')) {
                ftp = [];
                left = processing;
            }

            return jsonResponse(200, { data: {} });
        }

        const photo = !url.includes('content_type=vector');

        if (url.startsWith('/apicore-contributors/ftp')) return jsonResponse(200, { status: true, data: ftp });
        if (url.includes('/draft_items')) {
            if (posts.some((p) => p.url.includes('/prequeue')) && photo && left-- <= 0) {
                drafts = [...drafts, ...proceeded.splice(0)];
            }

            return page(photo ? drafts : []);
        }

        const status = url.match(/status=(\w+)/)?.[1];

        if (url.includes('/review_images')) return page(photo ? review[status] ?? [] : []);

        return jsonResponse(200, {});
    }));

    return posts;
}

// No pauses; a file that never turns into a draft stops the wait at once.
PROCESSING.intervalMs = 0;
PROCESSING.timeoutMs = 20;

afterEach(() => vi.unstubAllGlobals());

describe('matcher', () => {
    const find = matcher([listing(1, 'IMG_1.jpg'), listing(2, 'VECTOR.eps')]);

    it('finds a work by its file name without the extension', () => {
        expect(find('IMG_1')?.id).toBe(1);
        expect(find('IMG_1.jpg')?.id).toBe(1);
        expect(find('VECTOR.jpg')?.id).toBe(2);
    });

    it('leaves alone a file of the same name uploaded before Kadrobit sent it, and other files', () => {
        expect(find('IMG_1', '2026-10-30 00:00:00')?.id).toBe(1);
        expect(find('IMG_1', '2026-09-01 00:00:00')).toBeNull();
        expect(find('IMG_2')).toBeNull();
    });
});

describe('sync', () => {
    it('proceeds the FTP files and submits the drafts of accounts with auto submit', async () => {
        const posts = fakeSite({
            ftp: [{ filename: 'IMG_3.jpg' }],
            drafts: [draft(501, 'IMG_1'), draft(502, 'IMG_2'), draft(503, 'MANUAL')],
        });

        const result = await rf123.sync(
            [listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg', { account_id: 2 }), listing(3, 'IMG_3.jpg')],
            { shouldSubmit: (l) => l.account_id === 1 },
        );

        expect(posts.map((p) => p.url)).toEqual([
            '/apicore-contributors/process_images/prequeue?upload_method=ftp&allow_conversion=0&license_type=commercial&media_type=image',
            '/apicore-contributors/draft_items/to_pending',
        ]);
        expect(posts[1].body).toEqual({ data: { type: 'draft_item', attributes: { draft_ids: [501] } } });

        expect(result).toMatchObject({ found: 3, submitted: 1, errors: [] });
        expect(result.reports).toEqual([{ id: 1, moderation_status: 'pending' }]);
    });

    it('waits for the files Proceed took and submits their drafts in the same pass', async () => {
        const posts = fakeSite({ ftp: [{ filename: 'IMG_1.jpg' }], proceeded: [draft(501, 'IMG_1')], processing: 2 });

        const result = await rf123.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => true });

        expect(posts.map((p) => p.url.split('?')[0])).toEqual(['/apicore-contributors/process_images/prequeue', '/apicore-contributors/draft_items/to_pending']);
        expect(result).toMatchObject({ found: 1, submitted: 1 });
    });

    it('neither proceeds nor submits without auto submit', async () => {
        const posts = fakeSite({ ftp: [{ filename: 'IMG_1.jpg' }], drafts: [draft(501, 'IMG_2')] });

        const result = await rf123.sync([listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg')], { shouldSubmit: () => false });

        expect(posts).toEqual([]);
        expect(result).toMatchObject({ found: 2, submitted: 0 });
    });

    it('does not proceed when only files of others wait on the FTP', async () => {
        const posts = fakeSite({ ftp: [{ filename: 'MANUAL.jpg' }] });

        await rf123.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => true });

        expect(posts).toEqual([]);
    });

    it('keeps back a draft the site would not submit', async () => {
        const posts = fakeSite({
            drafts: [
                draft(501, 'IMG_1', { kwDesc: { description: '', keywords: KEYWORDS } }),
                draft(502, 'IMG_2', { kwDesc: { description: 'Icebergs', keywords: ['ice'] } }),
            ],
        });

        const result = await rf123.sync([listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg')], { shouldSubmit: () => true });

        expect(posts).toEqual([]);
        expect(result.errors).toEqual(['IMG_1.jpg: no description in the file', 'IMG_2.jpg: fewer than 7 keywords']);
    });

    it('reads the review status with the name 123RF gave the work', async () => {
        fakeSite({
            review: {
                pending: [reviewed('author261000011', 'IMG_1')],
                accepted: [reviewed('author261000012', 'IMG_2')],
                rejected: [reviewed('author261000013', 'IMG_3')],
            },
        });

        const result = await rf123.sync([listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg'), listing(3, 'IMG_3.jpg')], { shouldSubmit: () => true });

        expect(result.reports).toEqual([
            { id: 1, external_id: 'author261000011', moderation_status: 'pending' },
            { id: 2, external_id: 'author261000012', moderation_status: 'accepted' },
            { id: 3, external_id: 'author261000013', moderation_status: 'rejected' },
        ]);
    });

    it('takes an empty list answered with 204', async () => {
        fakeSite();
        const fetch = globalThis.fetch;
        vi.stubGlobal('fetch', vi.fn(async (url, options) => (url.includes('/draft_items') ? { ok: true, status: 204 } : fetch(url, options))));

        expect(await rf123.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => true })).toMatchObject({ found: 0, errors: [] });
    });

    it('stops on a changed answer of the site', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { status: true, data: [{ name: 'IMG_1.jpg' }] })));

        await expect(rf123.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => true })).rejects.toBeInstanceOf(ShapeError);
    });

    it('asks to sign in when the site does not know the author', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(401, 'Unauthorized.')));

        expect(await rf123.isSignedIn()).toBe(false);
        await expect(rf123.sync([], { shouldSubmit: () => true })).rejects.toThrow('Sign in to 123RF in this browser.');
    });
});
