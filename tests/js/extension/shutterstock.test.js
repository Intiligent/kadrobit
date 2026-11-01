import { afterEach, describe, expect, it, vi } from 'vitest';
import shutterstock, { matcher, publicUrl } from '../../../extension/src/adapters/shutterstock.js';
import { ShapeError } from '../../../extension/src/core/shape.js';
import { jsonResponse } from './helpers.js';

const listing = (id, name, attributes = {}) => ({
    id,
    account_id: 1,
    remote_filename: name,
    sent_at: '2026-10-27T10:00:00+00:00',
    outdated: false,
    metadata: { title: 'Glacier', description: 'Icebergs in a glacier lagoon', keywords: ['ice', 'lagoon'], categories: [{ id: 'Nature', name: 'Nature' }, { id: 'Arts', name: 'Arts' }] },
    metadata_hash: `hash-${id}`,
    ...attributes,
});

// A work in "Not submitted": no mediaId and uploadedDate there, only the prefixed id and `created`.
const item = (number, name, attributes = {}) => ({ id: `U${number}`, originalFilename: name, created: '2026-11-01T09:37:12-05:00', categories: [], ...attributes });

// A work under review or reviewed.
const reviewed = (number, name, status) => ({ id: `P${number}`, mediaId: number, originalFilename: name, uploadedDate: '2026-10-30', status, categories: ['12'] });

const CATEGORIES = [{ catId: 12, name: 'Nature', code: 'nature' }, { catId: 11, name: 'The Arts', code: 'arts' }, { catId: 9, name: 'Objects', code: 'objects' }];

/**
 * The site's API: lists by path, every POST recorded.
 */
function fakeSite({ ingested = [], pending = [], reviewed = [] } = {}) {
    const posts = [];
    const page = (data) => jsonResponse(200, { data, totalCount: data.length });

    vi.stubGlobal('fetch', vi.fn(async (url, { method = 'GET', body } = {}) => {
        if (method === 'POST') {
            posts.push({ url, body: JSON.parse(body) });

            return jsonResponse(200, {});
        }

        const photo = url.includes('mediaType=photo') || url.includes('media_type=photo');

        if (url.includes('/media/categories')) return jsonResponse(200, CATEGORIES);
        if (url.includes('workflowStatus=ingested')) return page(photo ? ingested : []);
        if (url.includes('status=pending')) return page(photo ? pending : []);
        if (url.includes('status=reviewed')) return page(photo ? reviewed : []);

        return jsonResponse(200, {});
    }));

    return posts;
}

afterEach(() => vi.unstubAllGlobals());

describe('matcher', () => {
    const find = matcher([listing(1, 'IMG_1.jpg')]);

    it('finds a work by file name uploaded after Kadrobit sent it', () => {
        expect(find(item(10, 'IMG_1.jpg', { created: '2026-10-27T09:00:00-05:00' }))?.id).toBe(1);
        expect(find(item(10, 'IMG_1.jpg', { created: '2026-10-26T20:00:00-05:00' }))?.id).toBe(1);
        expect(find(reviewed(10, 'IMG_1.jpg', 'pending'))?.id).toBe(1);
    });

    it('leaves alone a file of the same name the author uploaded earlier, and other files', () => {
        expect(find(item(10, 'IMG_1.jpg', { created: '2026-09-01T09:00:00-05:00' }))).toBeNull();
        expect(find({ ...reviewed(10, 'IMG_1.jpg', 'approved'), uploadedDate: '2026-09-01' })).toBeNull();
        expect(find(item(11, 'IMG_2.jpg'))).toBeNull();
    });
});

describe('sync', () => {
    it('sets categories, then submits the works of accounts with auto submit', async () => {
        const posts = fakeSite({ ingested: [item(100, 'IMG_1.jpg'), item(101, 'IMG_2.jpg'), item(102, 'MANUAL.jpg')] });
        const result = await shutterstock.sync(
            [listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg', { account_id: 2 })],
            { shouldSubmit: (l) => l.account_id === 1 },
        );

        const [update, submit] = posts;
        expect(update.url).toBe('/api/next/media/bulk/update');
        expect(update.body.ids).toEqual(['U100', 'U101']);
        expect(update.body.updateBody[0]).toEqual({
            id: 'U100', description: 'Icebergs in a glacier lagoon', keywords: ['ice', 'lagoon'], categories: ['12', '11'], releases: [], is_adult: false, submitter_note: '',
        });

        expect(submit.url).toBe('/api/next/media/submit');
        expect(submit.body).toEqual({ media: [{ mediaType: 'photo', mediaId: 'U100' }], skipSpellcheck: false, preview: 'false' });

        expect(result).toMatchObject({ found: 2, submitted: 1, errors: [] });
        expect(result.reports).toEqual([
            { id: 1, external_id: '100', metadata_hash: 'hash-1', moderation_status: 'pending' },
            { id: 2, external_id: '101', metadata_hash: 'hash-2' },
        ]);
    });

    it('does not rewrite works whose categories and metadata are already on the site', async () => {
        const posts = fakeSite({ ingested: [item(100, 'IMG_1.jpg', { categories: ['11', '12'] })] });

        await shutterstock.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => false });

        expect(posts).toEqual([]);
    });

    it('rewrites the metadata of a work edited after sending', async () => {
        const posts = fakeSite({ ingested: [item(100, 'IMG_1.jpg', { categories: ['11', '12'] })] });

        await shutterstock.sync([listing(1, 'IMG_1.jpg', { outdated: true })], { shouldSubmit: () => false });

        expect(posts.map((p) => p.url)).toEqual(['/api/next/media/bulk/update']);
    });

    it('neither updates nor submits a work without a known category', async () => {
        const posts = fakeSite({ ingested: [item(100, 'IMG_1.jpg'), item(101, 'IMG_2.jpg')] });
        const noCategory = listing(1, 'IMG_1.jpg', { metadata: { ...listing(1).metadata, categories: [] } });
        const unknown = listing(2, 'IMG_2.jpg', { metadata: { ...listing(2).metadata, categories: [{ id: 'Space', name: 'Space' }] } });

        const result = await shutterstock.sync([noCategory, unknown], { shouldSubmit: () => true });

        expect(posts).toEqual([]);
        expect(result.errors).toEqual(['IMG_1.jpg: no category in Kadrobit', 'IMG_2.jpg: unknown category Space']);
        expect(result.reports).toEqual([{ id: 1, external_id: '100' }, { id: 2, external_id: '101' }]);
    });

    it('reads the review status and links accepted works', async () => {
        fakeSite({
            pending: [reviewed(200, 'IMG_1.jpg', 'pending')],
            reviewed: [reviewed(201, 'IMG_2.jpg', 'approved'), reviewed(202, 'IMG_3.jpg', 'rejected')],
        });

        const result = await shutterstock.sync([listing(1, 'IMG_1.jpg'), listing(2, 'IMG_2.jpg'), listing(3, 'IMG_3.jpg')], { shouldSubmit: () => true });

        expect(result.reports).toEqual([
            { id: 1, external_id: '200', moderation_status: 'pending' },
            { id: 2, external_id: '201', moderation_status: 'accepted', external_url: publicUrl(201) },
            { id: 3, external_id: '202', moderation_status: 'rejected' },
        ]);
        expect(result.submitted).toBe(0);
    });

    it('takes the number of a work in "Not submitted" from its id', async () => {
        fakeSite({ ingested: [item(4224158505, 'IMG_1.jpg', { categories: ['11', '12'] })] });

        const result = await shutterstock.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => false });

        expect(result.reports[0].external_id).toBe('4224158505');
    });

    it('stops on a changed answer of the site', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { items: [] })));

        await expect(shutterstock.sync([listing(1, 'IMG_1.jpg')], { shouldSubmit: () => true })).rejects.toBeInstanceOf(ShapeError);
    });

    it('asks to sign in when the site sends to its login', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ type: 'opaqueredirect', status: 0, ok: false })));

        expect(await shutterstock.isSignedIn()).toBe(false);
        await expect(shutterstock.sync([], { shouldSubmit: () => true })).rejects.toThrow('Sign in to Shutterstock in this browser.');
    });
});
