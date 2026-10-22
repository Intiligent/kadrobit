import { h, reactive } from 'vue';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { API_DEFAULT_TIMEOUT, CSRF_REFRESH_URL, ERROR_CODE, LOGIN_URL, RETRY } from '@/config/constant';
import { ApiError, isCanceled } from './ApiError';
import notify from './notify';

/**
 * API client for Laravel + Inertia.
 *
 * - Session cookies and the XSRF-TOKEN cookie are sent with every request.
 * - Every request gets a request id (UUID). Mutating requests send it as the Idempotency-Key header,
 *   so a retry of the same request is not executed twice by the server.
 * - Errors are normalized to ApiError and handled globally by error code, unless `silent`.
 * - 419: the CSRF cookie is renewed and the request retried once.
 * - Network failures and 502/503/504 are retried for GET/HEAD (or when `retry` is set).
 *
 * Request config options on top of axios:
 * @property {string}   requestId     Own request id; generated when omitted. It is also the Idempotency-Key,
 *                                    so use a new id for every submission: a reused id replays the earlier response.
 * @property {object}   loadingState  Object from useLoading() or a reactive object; holds the request while it runs.
 *                                    Supported by the `api` methods, not by the raw `http` instance.
 * @property {boolean}  notify        Show a success notification with the response `message`.
 * @property {boolean|Array<number>} silent  Skip global error handling: all, or only these statuses/codes.
 * @property {number}   retry         Retry attempts for network failures and 502/503/504.
 */

const MUTATING_METHODS = ['post', 'put', 'patch', 'delete'];
const RETRYABLE_METHODS = ['get', 'head', 'options'];

export const IDEMPOTENCY_HEADER = 'Idempotency-Key';

export const newRequestId = () => crypto.randomUUID();

/**
 * Every running request of the app by request id, e.g. for a global progress indicator.
 */
export const pendingRequests = reactive({});

export const http = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
    timeout: API_DEFAULT_TIMEOUT,
});

/**
 * Optional callback that renews the API token after a 401 and resolves to true on success.
 * Set by the auth module once token re-issue exists; the request is then retried once.
 */
let tokenRefresher = null;
let tokenRefreshInFlight = null;

/**
 * Parallel 419 responses share one CSRF renewal: separate calls could each start a new session
 * and overwrite the XSRF cookie the others just received.
 */
let csrfRefreshInFlight = null;

export function setTokenRefresher(refresher) {
    tokenRefresher = refresher;
}

/**
 * Loading state objects by request id. Kept outside the axios config on purpose:
 * axios deep-copies plain objects when merging configs, which would detach a reactive state.
 */
const loadingTargets = new Map();

/**
 * Request ids of `api` calls that have not settled yet, marked synchronously at call time.
 */
const activeRequestIds = new Set();

function markPending(config) {
    const entry = { method: config.method, url: config.url, startedAt: Date.now() };

    pendingRequests[config.requestId] = entry;

    const target = loadingTargets.get(config.requestId);
    if (target) {
        target[config.requestId] = entry;
    }
}

function markFinished(config) {
    if (!config?.requestId) {
        return;
    }

    delete pendingRequests[config.requestId];

    const target = loadingTargets.get(config.requestId);
    if (target) {
        delete target[config.requestId];
        loadingTargets.delete(config.requestId);
    }
}

http.interceptors.request.use((config) => {
    config.requestId ??= newRequestId();

    if (MUTATING_METHODS.includes(config.method) && !config.headers.has(IDEMPOTENCY_HEADER)) {
        config.headers.set(IDEMPOTENCY_HEADER, config.requestId);
    }

    if (!config.untracked) {
        markPending(config);
    }

    return config;
});

http.interceptors.response.use(
    (response) => {
        markFinished(response.config);

        if (response.config.notify && response.data?.message) {
            notify.success({ title: trans('common.notifications.success'), message: response.data.message });
        }

        return response;
    },
    async (error) => {
        await parseBlobErrorBody(error);

        const apiError = ApiError.from(error);
        const config = error.config;

        if (!config || apiError.isCanceled) {
            markFinished(config);
            throw apiError;
        }

        // The server rejected the request without running it, so repeating it is safe.
        if (apiError.code === ERROR_CODE.CSRF && !config.csrfRetried) {
            config.csrfRetried = true;
            await refreshCsrfToken();
            return http.request(config);
        }

        if (apiError.code === ERROR_CODE.UNAUTHENTICATED && tokenRefresher && !config.authRetried) {
            config.authRetried = true;
            if (await refreshToken()) {
                return http.request(config);
            }
        }

        if (shouldRetry(config, apiError)) {
            config.retryCount = (config.retryCount ?? 0) + 1;
            await sleep(RETRY.baseDelay * 2 ** (config.retryCount - 1));
            return http.request(config);
        }

        markFinished(config);
        logInDevelopment(apiError);

        if (!isSilenced(config, apiError)) {
            handleGlobally(apiError);
        }

        throw apiError;
    },
);

function refreshCsrfToken() {
    csrfRefreshInFlight ??= http.get(CSRF_REFRESH_URL, { silent: true, untracked: true })
        .catch(() => null)
        .finally(() => {
            csrfRefreshInFlight = null;
        });

    return csrfRefreshInFlight;
}

function refreshToken() {
    tokenRefreshInFlight ??= Promise.resolve()
        .then(() => tokenRefresher())
        .catch(() => false)
        .finally(() => {
            tokenRefreshInFlight = null;
        });

    return tokenRefreshInFlight;
}

function shouldRetry(config, apiError) {
    const method = config.method ?? 'get';
    const attempts = config.retry ?? (RETRYABLE_METHODS.includes(method) ? RETRY.attempts : 0);
    const retryable = apiError.isNetworkError
        || (apiError.isTimeout && RETRYABLE_METHODS.includes(method))
        || RETRY.statuses.includes(apiError.status);

    return retryable && (config.retryCount ?? 0) < attempts;
}

function isSilenced(config, apiError) {
    if (Array.isArray(config.silent)) {
        return config.silent.includes(apiError.status) || config.silent.includes(apiError.code);
    }

    return Boolean(config.silent);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * With responseType "blob" the JSON error body arrives as a Blob.
 */
async function parseBlobErrorBody(error) {
    const data = error?.response?.data;

    if (typeof Blob === 'undefined' || !(data instanceof Blob) || !data.type.includes('json')) {
        return;
    }

    try {
        error.response.data = JSON.parse(await data.text());
    } catch {
        // Not JSON after all: keep the blob.
    }
}

function logInDevelopment(apiError) {
    if (import.meta.env.DEV && apiError.data?.exception) {
        console.error(
            `[api] ${apiError.data.exception}: ${apiError.message}\n  at ${apiError.data.file}:${apiError.data.line}`,
            apiError.data.trace,
        );
    }
}

function handleGlobally(apiError) {
    if (apiError.isTimeout) {
        return notify.warning({ key: 'timeout', title: trans('errors.titles.timeout'), message: trans('errors.timeout') });
    }

    if (apiError.isNetworkError) {
        return notify.error({ key: 'network', title: trans('errors.titles.network'), message: trans('errors.network') });
    }

    const handler = errorHandlers[apiError.code];

    if (handler) {
        return handler(apiError);
    }

    if (apiError.status >= 500) {
        return notify.error({ key: 'server', title: trans('errors.titles.server'), message: trans('errors.server') });
    }

    return notify.error({ title: trans('errors.titles.error'), message: apiError.message || trans('errors.generic') });
}

const errorHandlers = {
    [ERROR_CODE.CSRF]: async () => {
        const confirmed = await notify.confirm({
            key: 'session-expired',
            title: trans('errors.titles.session_expired'),
            message: trans('errors.session_expired'),
            confirmText: trans('common.actions.refresh'),
            cancelText: trans('common.actions.cancel'),
        });

        if (confirmed) {
            window.location.reload();
        }
    },

    // The backend message is the raw "Unauthenticated."; replace it with an actionable one.
    [ERROR_CODE.UNAUTHENTICATED]: async () => {
        const confirmed = await notify.confirm({
            key: 'unauthenticated',
            title: trans('errors.titles.unauthenticated'),
            message: trans('errors.unauthenticated'),
            confirmText: trans('common.actions.log_in'),
            cancelText: trans('common.actions.cancel'),
            type: 'info',
        });

        if (confirmed) {
            const redirect = window.location.pathname + window.location.search;
            router.visit(`${LOGIN_URL}?redirect=${encodeURIComponent(redirect)}`);
        }
    },

    [ERROR_CODE.ACCESS_DENIED]: (apiError) => notify.error({
        title: trans('errors.titles.forbidden'),
        message: apiError.message || trans('errors.forbidden'),
    }),

    [ERROR_CODE.NOT_FOUND]: (apiError) => notify.warning({
        title: trans('errors.titles.not_found'),
        message: apiError.message || trans('errors.not_found'),
    }),

    [ERROR_CODE.CONFLICT]: (apiError) => notify.warning({
        title: trans('errors.titles.conflict'),
        message: apiError.message,
    }),

    [ERROR_CODE.VALIDATION]: (apiError) => {
        const messages = Object.values(apiError.errors).flat();

        return notify.warning({
            title: trans('errors.titles.validation'),
            message: messages.length
                ? h('div', { class: 'space-y-1' }, messages.map((message) => h('div', message)))
                : apiError.message,
        });
    },

    [ERROR_CODE.TOO_MANY_REQUESTS]: (apiError) => notify.warning({
        key: 'too-many-requests',
        title: trans('errors.titles.too_many_requests'),
        message: apiError.message,
    }),

    [ERROR_CODE.WITH_MESSAGE]: (apiError) => notify.warning({
        title: trans('errors.titles.error'),
        message: apiError.message,
    }),

    [ERROR_CODE.INTERNAL]: (apiError) => notify.error({
        key: 'server',
        title: trans('errors.titles.server'),
        message: apiError.message || trans('errors.server'),
    }),
};

/**
 * File name from a Content-Disposition header, preferring the RFC 5987 `filename*` form.
 */
export function parseContentDisposition(header) {
    if (!header) {
        return null;
    }

    const encoded = /filename\*\s*=\s*[^']*'[^']*'([^;]+)/i.exec(header);
    if (encoded) {
        try {
            return decodeURIComponent(encoded[1].trim().replace(/^"|"$/g, ''));
        } catch {
            // Fall back to the plain form.
        }
    }

    const plain = /filename\s*=\s*("([^"]*)"|[^;]+)/i.exec(header);

    return plain ? (plain[2] ?? plain[1]).trim() : null;
}

/**
 * Plain object to FormData. FormData passes through.
 * - nested objects: `meta[editorial]`
 * - arrays of scalars or files: `tags[]`
 * - arrays of objects or arrays: `items[0][id]`
 */
export function toFormData(data, form = new FormData(), prefix = '') {
    if (data instanceof FormData) {
        return data;
    }

    Object.entries(data ?? {}).forEach(([key, value]) => {
        const name = prefix ? `${prefix}[${key}]` : key;

        if (value === undefined) {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                if (isNested(item)) {
                    toFormData(item, form, `${name}[${index}]`);
                } else {
                    appendValue(form, `${name}[]`, item);
                }
            });
        } else if (isNested(value)) {
            toFormData(value, form, name);
        } else {
            appendValue(form, name, value);
        }
    });

    return form;
}

function isNested(value) {
    return value !== null && typeof value === 'object' && !(value instanceof Blob) && !(value instanceof Date);
}

function appendValue(form, name, value) {
    if (value instanceof Blob) {
        form.append(name, value);
    } else if (value instanceof Date) {
        form.append(name, value.toISOString());
    } else if (typeof value === 'boolean') {
        form.append(name, value ? '1' : '0');
    } else {
        form.append(name, value ?? '');
    }
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename ?? 'download';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}

/**
 * API service wrapper. Methods resolve to the response body and reject with ApiError.
 *
 * @example
 * const users = await api.get('/api/users', { page: 2 });
 *
 * // Per-request loading state
 * const loading = useLoading();
 * await api.post('/api/assets', data, { loadingState: loading, notify: true });
 *
 * // Show field errors in the form instead of a global notification
 * try {
 *     await api.post('/api/assets', data, { silent: [422] });
 * } catch (error) {
 *     form.errors = error.errors;
 * }
 */
const api = {
    request({ loadingState = null, ...config }) {
        config.requestId ??= newRequestId();

        const { requestId } = config;

        if (activeRequestIds.has(requestId)) {
            return Promise.reject(new Error(`[api] Request ${requestId} is already running; use a new request id for each request.`));
        }

        activeRequestIds.add(requestId);

        if (loadingState) {
            loadingTargets.set(requestId, loadingState.state ?? loadingState);
        }

        return http.request(config)
            .then((response) => response.data)
            .finally(() => activeRequestIds.delete(requestId));
    },

    get(url, params = null, config = {}) {
        return api.request({ ...config, method: 'get', url, params });
    },

    post(url, data = null, config = {}) {
        return api.request({ ...config, method: 'post', url, data });
    },

    put(url, data = null, config = {}) {
        return api.request({ ...config, method: 'put', url, data });
    },

    patch(url, data = null, config = {}) {
        return api.request({ ...config, method: 'patch', url, data });
    },

    delete(url, data = null, config = {}) {
        return api.request({ ...config, method: 'delete', url, data });
    },

    /**
     * Multipart upload. `onProgress` receives 0–100; uploads have no timeout by default.
     * PHP parses multipart bodies only for POST, so PUT/PATCH/DELETE are sent as POST with `_method`.
     */
    upload(url, data, { onProgress, method = 'post', ...config } = {}) {
        const form = toFormData(data);

        if (method.toLowerCase() !== 'post') {
            form.set('_method', method.toUpperCase());
        }

        return api.request({
            timeout: 0,
            ...config,
            method: 'post',
            url,
            data: form,
            onUploadProgress: (event) => {
                if (onProgress && event.total) {
                    onProgress(Math.round((event.loaded * 100) / event.total));
                }
            },
        });
    },

    /**
     * Downloads a file; saves it in the browser unless `save` is false.
     * Resolves to { blob, filename }.
     */
    async download(url, params = null, { save = true, filename = null, onProgress, loadingState = null, ...config } = {}) {
        config.requestId ??= newRequestId();

        if (loadingState) {
            loadingTargets.set(config.requestId, loadingState.state ?? loadingState);
        }

        const response = await http.request({
            timeout: 0,
            ...config,
            method: config.method ?? 'get',
            url,
            params,
            responseType: 'blob',
            onDownloadProgress: (event) => {
                if (onProgress && event.total) {
                    onProgress(Math.round((event.loaded * 100) / event.total));
                }
            },
        });

        const name = filename ?? parseContentDisposition(response.headers['content-disposition']);

        if (save) {
            saveBlob(response.data, name);
        }

        return { blob: response.data, filename: name };
    },

    /**
     * Requests where only the latest result matters (search, filters): each call aborts the previous one.
     * The aborted call rejects with an ApiError whose `isCanceled` is true.
     *
     * @example
     * const search = api.cancellable();
     * search.get('/api/assets', { q }).then(show).catch(api.ignoreCanceled);
     */
    cancellable() {
        let controller = null;

        const run = (method, url, payload, config = {}) => {
            controller?.abort();
            controller = new AbortController();

            return api[method](url, payload, { ...config, signal: controller.signal });
        };

        return {
            get: (url, params, config) => run('get', url, params, config),
            post: (url, data, config) => run('post', url, data, config),
            put: (url, data, config) => run('put', url, data, config),
            patch: (url, data, config) => run('patch', url, data, config),
            delete: (url, data, config) => run('delete', url, data, config),
            cancel: () => controller?.abort(),
        };
    },

    /**
     * Swallows cancellation, rethrows everything else.
     */
    ignoreCanceled(error) {
        if (!isCanceled(error)) {
            throw error;
        }
    },

    isCanceled,

    /**
     * Raw axios instance for advanced usage
     */
    raw: http,
};

export default api;
