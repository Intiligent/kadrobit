import { computed, reactive } from 'vue';
import { trans } from 'laravel-vue-i18n';
import api from '@/core/api';
import { ASSET_UPLOAD_CONCURRENCY, ASSET_UPLOAD_POLL_INTERVAL, ASSET_UPLOADS_API_URL } from '@/config/constant';

/**
 * Upload queue of the library.
 *
 * Every add() opens a batch on the server (POST /api/assets/uploads), then sends the files one per
 * request, a few at a time (POST /api/assets/uploads/{batch}/files). Each file is processed by its own
 * queued job; the work exists only once that job has succeeded. The batch is polled with a cursor
 * while any of its files is processing: each poll returns only the uploads that changed, and every
 * finished work is handed over at once, so a large batch fills the grid as it goes.
 *
 * `items`: { key, name, file, previewUrl, folderId, batchId, uploadId, progress, processing, status, error, retryable }
 *   status: 'queued' | 'uploading' | 'processing' | 'done' | 'error'
 *   progress: transfer to the server, 0–100, measured by the browser
 *   processing: steps done by the queued job, 0–100, reported by the batch poll
 * `onCompleted(asset)`: a new work, as soon as its upload is done
 */
export function useAssetUpload({ maxSizeMb, onCompleted } = {}) {
    const items = reactive([]);
    // batch id => { timer, cursor }
    const polls = new Map();
    let active = 0;

    const settled = (item) => item.status === 'done' || item.status === 'error';

    const summary = computed(() => {
        const done = items.filter(settled).length;

        return {
            total: items.length,
            done,
            failed: items.filter((item) => item.status === 'error').length,
            active: items.length - done,
            percent: items.length
                ? Math.round(items.reduce((sum, item) => sum + (settled(item) ? 100 : percentOf(item)), 0) / items.length)
                : 0,
        };
    });

    // `folderId`: the folder the new works go into, null for none.
    async function add(files, { folderId = null } = {}) {
        const entries = Array.from(files).map((file) => createItem(file, folderId));

        if (!entries.length) {
            return;
        }

        items.push(...entries);
        const sendable = entries.filter((item) => item.status === 'queued');

        if (!sendable.length) {
            return;
        }

        try {
            const { data } = await api.post(ASSET_UPLOADS_API_URL, { files: sendable.length }, { silent: true });
            sendable.forEach((item) => { item.batchId = data.id; });
        } catch (error) {
            sendable.forEach((item) => fail(item, error.message));

            return;
        }

        pump();
    }

    function createItem(file, folderId) {
        const tooLarge = maxSizeMb && file.size > maxSizeMb * 1024 * 1024;

        return reactive({
            key: crypto.randomUUID(),
            name: file.name,
            file,
            previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            folderId,
            batchId: null,
            uploadId: null,
            progress: 0,
            processing: 0,
            status: tooLarge ? 'error' : 'queued',
            error: tooLarge ? trans('library.upload.too_large', { max: maxSizeMb }) : null,
            retryable: false,
        });
    }

    function pump() {
        while (active < ASSET_UPLOAD_CONCURRENCY) {
            const next = items.find((item) => item.status === 'queued' && item.batchId);

            if (!next) {
                return;
            }

            send(next);
        }
    }

    async function send(item) {
        item.status = 'uploading';
        item.progress = 0;
        active += 1;

        try {
            const { data: upload } = await api.upload(`${ASSET_UPLOADS_API_URL}/${item.batchId}/files`, { file: item.file, folder_id: item.folderId }, {
                onProgress: (percent) => {
                    item.progress = percent;
                },
                // Shown in the upload list instead of a global notification.
                silent: true,
            });

            item.uploadId = upload.id;
            item.status = 'processing';
            settle(upload);
            poll(item.batchId);
        } catch (error) {
            // A rejected file (wrong type, too large, already in the library) fails the same way again.
            fail(item, error.fieldError?.('file') ?? error.message, ![409, 413, 422].includes(error.status));
        } finally {
            active -= 1;
            pump();
        }
    }

    // One timer per batch, while any of its files is still on its way.
    function poll(batchId) {
        if (polls.has(batchId)) {
            return;
        }

        const state = { timer: null, cursor: null };

        const tick = async () => {
            try {
                const { data } = await api.get(`${ASSET_UPLOADS_API_URL}/${batchId}`, { cursor: state.cursor }, { silent: true });
                state.cursor = data.cursor;
                data.uploads.forEach(settle);
            } catch {
                // A missed poll is retried on the next tick.
            }

            const waiting = items.some((item) => item.batchId === batchId && !settled(item));

            if (waiting) {
                state.timer = setTimeout(tick, ASSET_UPLOAD_POLL_INTERVAL);
            } else {
                polls.delete(batchId);
            }
        };

        state.timer = setTimeout(tick, ASSET_UPLOAD_POLL_INTERVAL);
        polls.set(batchId, state);
    }

    // Polls overlap a little, so an upload may come more than once: only the first settles it.
    function settle(upload) {
        const item = items.find((entry) => entry.uploadId === upload.id);

        if (!item || item.status !== 'processing') {
            return;
        }

        item.processing = Math.max(item.processing, upload.progress ?? 0);

        if (upload.status === 'processing') {
            return;
        }

        if (upload.status === 'failed') {
            fail(item, upload.error);

            return;
        }

        item.status = 'done';
        onCompleted?.(upload.asset);
    }

    function fail(item, message, retryable = false) {
        item.status = 'error';
        item.error = message || trans('library.upload.failed');
        item.retryable = retryable;
    }

    // Only files that never reached the server can be sent again; a rejected or failed upload stays as it is.
    function retry(item) {
        if (!item.retryable || !item.batchId) {
            return;
        }

        Object.assign(item, { status: 'queued', progress: 0, processing: 0, error: null, retryable: false });
        pump();
    }

    function remove(item) {
        const index = items.indexOf(item);

        if (index >= 0 && (settled(item) || item.status === 'queued')) {
            if (item.previewUrl) {
                URL.revokeObjectURL(item.previewUrl);
            }

            items.splice(index, 1);
        }
    }

    function clearSettled() {
        items.filter(settled).forEach(remove);
    }

    return { items, summary, add, retry, remove, clearSettled };
}

/**
 * One bar per file over its whole way: the transfer fills the first half, processing the second.
 */
export function percentOf(item) {
    switch (item.status) {
        case 'done':
            return 100;
        case 'processing':
            return Math.round(50 + item.processing / 2);
        case 'uploading':
        case 'error':
            return Math.round(item.progress / 2 + item.processing / 2);
        default:
            return 0;
    }
}
