import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('laravel-vue-i18n', () => ({ trans: (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key) }));
vi.mock('@/core/api', () => ({
    default: { post: vi.fn(), get: vi.fn(), upload: vi.fn() },
}));

const { default: api } = await import('@/core/api');
const { useAssetUpload } = await import('@/composables/useAssetUpload');
const { ASSET_UPLOAD_CONCURRENCY, ASSET_UPLOAD_POLL_INTERVAL } = await import('@/config/constant');

const file = (name, size = 10, type = 'video/mp4') => ({ name, size, type });
const upload = (id, status = 'processing', extra = {}) => ({ id, filename: id, status, error: null, asset: null, ...extra });
const poll = (uploads, cursor = 'c1') => ({ data: { cursor, uploads } });

// Resolves pending promise callbacks between steps.
const flush = () => new Promise((resolve) => setImmediate(resolve));

describe('useAssetUpload', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        api.post.mockResolvedValue({ data: { id: 'batch-1' } });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.resetAllMocks();
    });

    it('opens one batch for the files and sends a few at a time into it', async () => {
        const sent = [];
        api.upload.mockImplementation((url, data) => new Promise((resolve) => sent.push({ url, data, resolve })));
        const uploader = useAssetUpload();

        await uploader.add([file('a'), file('b'), file('c'), file('d')], { folderId: 'f1' });

        expect(api.post).toHaveBeenCalledWith('/api/assets/uploads', { files: 4 }, { silent: true });
        expect(sent).toHaveLength(ASSET_UPLOAD_CONCURRENCY);
        expect(sent[0].url).toBe('/api/assets/uploads/batch-1/files');
        expect(sent[0].data).toMatchObject({ folder_id: 'f1' });

        sent[0].resolve({ data: upload('u1') });
        await flush();

        expect(sent).toHaveLength(4);
        expect(uploader.items[0]).toMatchObject({ status: 'processing', uploadId: 'u1' });
    });

    it('hands over each work as soon as its upload is done, polling with the cursor', async () => {
        api.upload
            .mockResolvedValueOnce({ data: upload('u1') })
            .mockResolvedValueOnce({ data: upload('u2') });
        api.get
            .mockResolvedValueOnce(poll([upload('u1', 'done', { asset: { id: 'a1' } })], 'c1'))
            .mockResolvedValueOnce(poll([
                // Polls overlap: an upload settled before may come again and is ignored.
                upload('u1', 'done', { asset: { id: 'a1' } }),
                upload('u2', 'done', { asset: { id: 'a2' } }),
            ], 'c2'));
        const onCompleted = vi.fn();
        const uploader = useAssetUpload({ onCompleted });

        await uploader.add([file('a'), file('b')]);
        await flush();
        expect(uploader.summary.value).toMatchObject({ total: 2, done: 0, percent: 50 });

        vi.advanceTimersByTime(ASSET_UPLOAD_POLL_INTERVAL);
        await flush();

        expect(api.get).toHaveBeenLastCalledWith('/api/assets/uploads/batch-1', { cursor: null }, { silent: true });
        expect(onCompleted).toHaveBeenCalledTimes(1);
        expect(uploader.summary.value).toMatchObject({ done: 1, percent: 75 });

        vi.advanceTimersByTime(ASSET_UPLOAD_POLL_INTERVAL);
        await flush();

        expect(api.get).toHaveBeenLastCalledWith('/api/assets/uploads/batch-1', { cursor: 'c1' }, { silent: true });
        expect(onCompleted.mock.calls.map(([asset]) => asset.id)).toEqual(['a1', 'a2']);
        expect(uploader.summary.value).toMatchObject({ total: 2, done: 2, active: 0, percent: 100 });

        // Nothing left on its way: polling stops.
        vi.advanceTimersByTime(ASSET_UPLOAD_POLL_INTERVAL * 3);
        await flush();
        expect(api.get).toHaveBeenCalledTimes(2);
    });

    it('shows why an upload failed in processing', async () => {
        api.upload.mockResolvedValue({ data: upload('u1') });
        api.get.mockResolvedValue(poll([upload('u1', 'failed', { error: 'Broken file' })]));
        const onCompleted = vi.fn();
        const uploader = useAssetUpload({ onCompleted });

        await uploader.add([file('a')]);
        await flush();
        vi.advanceTimersByTime(ASSET_UPLOAD_POLL_INTERVAL);
        await flush();

        expect(uploader.items[0]).toMatchObject({ status: 'error', error: 'Broken file', retryable: false });
        expect(onCompleted).not.toHaveBeenCalled();
    });

    it('rejects a file over the limit without sending it', async () => {
        const uploader = useAssetUpload({ maxSizeMb: 1 });

        await uploader.add([file('big', 2 * 1024 * 1024)]);

        expect(api.post).not.toHaveBeenCalled();
        expect(uploader.items[0]).toMatchObject({ status: 'error', retryable: false });
        expect(uploader.items[0].error).toContain('library.upload.too_large');
    });

    it('offers a retry after a network error but not after a duplicate', async () => {
        api.upload
            .mockRejectedValueOnce({ status: 0, message: 'Network error' })
            .mockRejectedValueOnce({ status: 409, message: 'a is already in your library.' });
        const uploader = useAssetUpload();

        await uploader.add([file('a'), file('b')]);
        await flush();

        expect(uploader.items.map((item) => [item.status, item.retryable])).toEqual([['error', true], ['error', false]]);

        api.upload.mockResolvedValue({ data: upload('u1') });
        uploader.retry(uploader.items[0]);
        uploader.retry(uploader.items[1]);
        await flush();

        expect(api.upload).toHaveBeenCalledTimes(3);
        expect(uploader.items[0].status).toBe('processing');
    });
});
