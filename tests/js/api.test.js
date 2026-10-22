import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError, CanceledError } from 'axios';

vi.mock('@/core/notify', () => ({
    default: {
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
        confirm: vi.fn(() => Promise.resolve(false)),
    },
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (key) => key }));
vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }));

const { default: api, http, pendingRequests, newRequestId, parseContentDisposition, toFormData, setTokenRefresher } = await import('@/core/api');
const { default: notify } = await import('@/core/notify');
const { router } = await import('@inertiajs/vue3');
const { useLoading } = await import('@/composables/useLoading');
const { ERROR_CODE, CSRF_REFRESH_URL } = await import('@/config/constant');

/**
 * Fake transport: each call takes the next responder from the queue.
 * A responder returns (or resolves to) { status, data, headers }, or throws to simulate a network failure.
 */
let responders = [];
let sent = [];

function respond(status, data = {}, headers = {}) {
    responders.push(() => ({ status, data, headers }));
}

function failNetwork() {
    responders.push((config) => {
        throw new AxiosError('Network Error', AxiosError.ERR_NETWORK, config);
    });
}

http.defaults.adapter = async (config) => {
    sent.push(config);

    if (config.signal?.aborted) {
        throw new CanceledError(undefined, undefined, config);
    }

    const responder = responders.shift();
    if (!responder) {
        throw new Error(`No response queued for ${config.method} ${config.url}`);
    }

    const { status, data, headers } = await responder(config);
    const response = { status, statusText: '', data, headers, config, request: {} };

    if (status >= 400) {
        throw new AxiosError(`Request failed with status code ${status}`, AxiosError.ERR_BAD_RESPONSE, config, {}, response);
    }

    return response;
};

beforeEach(() => {
    responders = [];
    sent = [];
    vi.clearAllMocks();
    vi.useRealTimers();
    setTokenRefresher(null);
});

describe('request id and idempotency', () => {
    it('sends the request id as Idempotency-Key on mutating requests', async () => {
        respond(201, { id: 1 });

        await api.post('/api/assets', { title: 'Sunset' });

        expect(sent[0].requestId).toMatch(/^[0-9a-f-]{36}$/);
        expect(sent[0].headers.get('Idempotency-Key')).toBe(sent[0].requestId);
    });

    it('does not send Idempotency-Key on GET', async () => {
        respond(200, []);

        await api.get('/api/assets');

        expect(sent[0].headers.has('Idempotency-Key')).toBe(false);
    });

    it('uses a caller supplied request id', async () => {
        const requestId = newRequestId();
        respond(200, {});

        await api.put('/api/assets/1', {}, { requestId });

        expect(sent[0].headers.get('Idempotency-Key')).toBe(requestId);
    });

    it('rejects a request id that is already running', async () => {
        const requestId = newRequestId();
        let release;
        responders.push(() => new Promise((resolve) => {
            release = () => resolve({ status: 200, data: {}, headers: {} });
        }));

        const first = api.post('/api/a', {}, { requestId });

        await expect(api.post('/api/a', {}, { requestId })).rejects.toThrow(/already running/);
        await vi.waitFor(() => expect(release).toBeTypeOf('function'));
        release();
        await first;

        expect(sent).toHaveLength(1);
        respond(200, {});
        await expect(api.post('/api/a', {}, { requestId })).resolves.toEqual({});
    });

    it('resolves to the response body', async () => {
        respond(200, { data: [1, 2] });

        await expect(api.get('/api/assets')).resolves.toEqual({ data: [1, 2] });
    });
});

describe('loading state', () => {
    it('tracks each request separately and clears it when done', async () => {
        const loading = useLoading();
        const first = newRequestId();
        const second = newRequestId();
        let releaseFirst;

        responders.push(() => new Promise((resolve) => {
            releaseFirst = () => resolve();
        }).then(() => ({ status: 200, data: {}, headers: {} })));

        respond(200, {});
        const firstRequest = api.get('/api/a', null, { loadingState: loading, requestId: first });
        const secondRequest = api.get('/api/b', null, { loadingState: loading, requestId: second });

        await secondRequest;
        expect(loading.is(second)).toBe(false);
        expect(loading.is(first)).toBe(true);
        expect(loading.any.value).toBe(true);
        expect(pendingRequests[first]).toMatchObject({ method: 'get', url: '/api/a' });

        releaseFirst();
        await firstRequest;

        expect(loading.any.value).toBe(false);
        expect(pendingRequests[first]).toBeUndefined();
    });

    it('clears the state when the request fails', async () => {
        const loading = useLoading();
        respond(500, { message: 'Boom', code: ERROR_CODE.INTERNAL });

        await expect(api.post('/api/a', {}, { loadingState: loading })).rejects.toThrow();

        expect(loading.any.value).toBe(false);
        expect(Object.keys(pendingRequests)).toHaveLength(0);
    });

    it('accepts a plain reactive object', async () => {
        const state = {};
        respond(200, {});

        await api.get('/api/a', null, { loadingState: state });

        expect(state).toEqual({});
    });
});

describe('errors', () => {
    it('rejects with a normalized ApiError', async () => {
        respond(422, { message: 'Invalid.', code: ERROR_CODE.VALIDATION, errors: { title: ['Required.'] } });

        const error = await api.post('/api/a', {}).catch((e) => e);

        expect(error.name).toBe('ApiError');
        expect(error).toMatchObject({ status: 422, code: ERROR_CODE.VALIDATION, message: 'Invalid.' });
        expect(error.fieldError('title')).toBe('Required.');
        expect(error.requestId).toBe(sent[0].requestId);
        expect(notify.warning).toHaveBeenCalledOnce();
    });

    it('skips global handling when silent', async () => {
        respond(422, { code: ERROR_CODE.VALIDATION, errors: {} });

        await api.post('/api/a', {}, { silent: true }).catch(() => null);

        expect(notify.warning).not.toHaveBeenCalled();
    });

    it('skips global handling only for listed statuses', async () => {
        respond(422, { code: ERROR_CODE.VALIDATION, errors: {} });
        respond(403, { code: ERROR_CODE.ACCESS_DENIED, message: 'No.' });

        await api.post('/api/a', {}, { silent: [422] }).catch(() => null);
        await api.post('/api/a', {}, { silent: [422] }).catch(() => null);

        expect(notify.warning).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalledOnce();
    });

    it('reports retry-after for 429', async () => {
        respond(429, { code: ERROR_CODE.TOO_MANY_REQUESTS, message: 'Wait.', retry_after: 30 });

        const error = await api.post('/api/a').catch((e) => e);

        expect(error.retryAfter).toBe(30);
        expect(notify.warning).toHaveBeenCalledWith(expect.objectContaining({ key: 'too-many-requests' }));
    });

    it('shows a generic notification for a raw 5xx without code', async () => {
        respond(500, '<html>Bad gateway</html>');

        await api.post('/api/a').catch(() => null);

        expect(notify.error).toHaveBeenCalledWith(expect.objectContaining({ key: 'server' }));
    });

    it('asks to log in on 401 and keeps the current page as redirect', async () => {
        vi.stubGlobal('window', { location: { pathname: '/assets', search: '?page=2' } });
        notify.confirm.mockResolvedValueOnce(true);
        respond(401, { code: ERROR_CODE.UNAUTHENTICATED });

        await api.get('/api/user', null, { retry: 0 }).catch(() => null);
        await vi.waitFor(() => expect(router.visit).toHaveBeenCalledWith('/login?redirect=%2Fassets%3Fpage%3D2'));

        expect(notify.confirm).toHaveBeenCalledWith(expect.objectContaining({ key: 'unauthenticated' }));
        vi.unstubAllGlobals();
    });

    it('marks canceled requests and does not notify', async () => {
        const controller = new AbortController();
        controller.abort();

        const error = await api.get('/api/a', null, { signal: controller.signal }).catch((e) => e);

        expect(error.isCanceled).toBe(true);
        expect(api.isCanceled(error)).toBe(true);
        expect(notify.error).not.toHaveBeenCalled();
        expect(notify.warning).not.toHaveBeenCalled();
    });
});

describe('csrf retry', () => {
    it('renews the token and retries once with the same Idempotency-Key', async () => {
        respond(419, { code: ERROR_CODE.CSRF });
        respond(204);
        respond(201, { ok: true });

        await expect(api.post('/api/a', { x: 1 })).resolves.toEqual({ ok: true });

        expect(sent.map((c) => c.url)).toEqual(['/api/a', CSRF_REFRESH_URL, '/api/a']);
        expect(sent[2].headers.get('Idempotency-Key')).toBe(sent[0].headers.get('Idempotency-Key'));
        expect(notify.confirm).not.toHaveBeenCalled();
    });

    it('asks to refresh the page when the retry fails again', async () => {
        respond(419, { code: ERROR_CODE.CSRF });
        respond(204);
        respond(419, { code: ERROR_CODE.CSRF });

        await expect(api.post('/api/a')).rejects.toMatchObject({ code: ERROR_CODE.CSRF });

        expect(sent).toHaveLength(3);
        expect(notify.confirm).toHaveBeenCalledOnce();
    });
});

describe('csrf retry with parallel requests', () => {
    it('renews the token once for parallel 419 responses', async () => {
        respond(419, { code: ERROR_CODE.CSRF });
        respond(419, { code: ERROR_CODE.CSRF });
        respond(204);
        respond(200, {});
        respond(200, {});

        await Promise.all([api.post('/api/a'), api.post('/api/b')]);

        expect(sent.filter((c) => c.url === CSRF_REFRESH_URL)).toHaveLength(1);
    });
});

describe('token refresh', () => {
    it('retries once after the refresher renews the token', async () => {
        setTokenRefresher(vi.fn().mockResolvedValue(true));
        respond(401, { code: ERROR_CODE.UNAUTHENTICATED });
        respond(200, { id: 1 });

        await expect(api.get('/api/user')).resolves.toEqual({ id: 1 });
        expect(notify.confirm).not.toHaveBeenCalled();
    });

    it('shares one refresh between parallel 401 responses', async () => {
        const refresher = vi.fn().mockResolvedValue(true);
        setTokenRefresher(refresher);
        respond(401, { code: ERROR_CODE.UNAUTHENTICATED });
        respond(401, { code: ERROR_CODE.UNAUTHENTICATED });
        respond(200, {});
        respond(200, {});

        await Promise.all([api.get('/api/a'), api.get('/api/b')]);

        expect(refresher).toHaveBeenCalledOnce();
    });
});

describe('retries', () => {
    it('retries GET on 503 and succeeds', async () => {
        vi.useFakeTimers();
        respond(503, {});
        respond(200, { ok: true });

        const request = api.get('/api/a');
        await vi.runAllTimersAsync();

        await expect(request).resolves.toEqual({ ok: true });
        expect(sent).toHaveLength(2);
    });

    it('gives up on GET after the configured attempts', async () => {
        vi.useFakeTimers();
        failNetwork();
        failNetwork();
        failNetwork();

        const request = api.get('/api/a').catch((e) => e);
        await vi.runAllTimersAsync();

        expect((await request).isNetworkError).toBe(true);
        expect(sent).toHaveLength(3);
        expect(notify.error).toHaveBeenCalledWith(expect.objectContaining({ key: 'network' }));
    });

    it('does not retry POST unless asked', async () => {
        respond(503, {});

        await api.post('/api/a').catch(() => null);

        expect(sent).toHaveLength(1);
    });

    it('retries POST with the same Idempotency-Key when retry is set', async () => {
        vi.useFakeTimers();
        failNetwork();
        respond(201, {});

        const request = api.post('/api/a', {}, { retry: 1 });
        await vi.runAllTimersAsync();
        await request;

        expect(sent[1].headers.get('Idempotency-Key')).toBe(sent[0].headers.get('Idempotency-Key'));
    });
});

describe('cancellable', () => {
    it('aborts the previous request', async () => {
        const search = api.cancellable();
        respond(200, { q: 'b' });

        const first = search.get('/api/search', { q: 'a' }, { signal: undefined });
        const second = search.get('/api/search', { q: 'b' });

        await expect(first).rejects.toMatchObject({ isCanceled: true });
        await expect(second).resolves.toEqual({ q: 'b' });
        await expect(first.catch(api.ignoreCanceled)).resolves.toBeUndefined();
    });
});

describe('upload and download helpers', () => {
    it('converts plain objects to FormData', () => {
        const file = new Blob(['x'], { type: 'image/jpeg' });
        const form = toFormData({ title: 'A', tags: ['a', 'b'], meta: { editorial: true }, photo: file, skip: undefined });

        expect(form.get('title')).toBe('A');
        expect(form.getAll('tags[]')).toEqual(['a', 'b']);
        expect(form.get('meta[editorial]')).toBe('1');
        expect(form.get('photo')).toBeInstanceOf(Blob);
        expect(form.has('skip')).toBe(false);
    });

    it('serializes arrays of objects with indexes', () => {
        const form = toFormData({ items: [{ id: 1, qty: 2 }, { id: 3, tags: ['x'] }], matrix: [[1, 2]] });

        expect(form.get('items[0][id]')).toBe('1');
        expect(form.get('items[0][qty]')).toBe('2');
        expect(form.getAll('items[1][tags][]')).toEqual(['x']);
        expect(form.getAll('matrix[0][0]')).toEqual(['1']);
    });

    it('sends PUT uploads as POST with _method', async () => {
        respond(200, {});

        await api.upload('/api/assets/5', { title: 'A' }, { method: 'put' });

        expect(sent[0].method).toBe('post');
        expect(sent[0].data.get('_method')).toBe('PUT');
    });

    it('uploads as multipart without timeout and reports progress', async () => {
        const progress = vi.fn();
        respond(201, { id: 5 });

        await api.upload('/api/assets', { title: 'A' }, { onProgress: progress });
        sent[0].onUploadProgress({ loaded: 50, total: 200 });

        expect(sent[0].data).toBeInstanceOf(FormData);
        expect(sent[0].timeout).toBe(0);
        expect(progress).toHaveBeenCalledWith(25);
    });

    it('reads JSON errors from blob responses', async () => {
        responders.push(() => ({
            status: 404,
            data: new Blob([JSON.stringify({ code: ERROR_CODE.NOT_FOUND, message: 'Gone.' })], { type: 'application/json' }),
            headers: {},
        }));

        const error = await api.download('/api/export', null, { save: false }).catch((e) => e);

        expect(error).toMatchObject({ status: 404, code: ERROR_CODE.NOT_FOUND, message: 'Gone.' });
    });

    it.each([
        ['attachment; filename="report.csv"', 'report.csv'],
        ['attachment; filename=report.csv', 'report.csv'],
        ["attachment; filename=\"fallback.csv\"; filename*=UTF-8''caf%C3%A9.csv", 'café.csv'],
        [undefined, null],
    ])('parses Content-Disposition %s', (header, expected) => {
        expect(parseContentDisposition(header)).toBe(expected);
    });
});

