import { isCancel } from 'axios';

const TIMEOUT_CODES = ['ECONNABORTED', 'ETIMEDOUT'];

/**
 * Normalized error thrown by the API client, so callers never dig into axios internals.
 */
export class ApiError extends Error {
    constructor(message, fields = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = fields.status ?? null;
        this.code = fields.code ?? null;
        this.errors = fields.errors ?? {};
        this.retryAfter = fields.retryAfter ?? null;
        this.data = fields.data ?? null;
        this.requestId = fields.requestId ?? null;
        this.isCanceled = fields.isCanceled ?? false;
        this.isTimeout = fields.isTimeout ?? false;
        this.isNetworkError = fields.isNetworkError ?? false;
        this.config = fields.config ?? null;
        this.response = fields.response ?? null;
        this.cause = fields.cause ?? null;
    }

    /**
     * First validation message of a field, or null.
     */
    fieldError(field) {
        return this.errors[field]?.[0] ?? null;
    }

    static from(error) {
        if (error instanceof ApiError) {
            return error;
        }

        const config = error?.config ?? null;
        const response = error?.response ?? null;
        const data = response?.data && typeof response.data === 'object' ? response.data : null;
        const retryAfterHeader = Number(response?.headers?.['retry-after']);

        return new ApiError(data?.message || error?.message || 'Request failed', {
            status: response?.status ?? null,
            code: data?.code ?? null,
            errors: data?.errors ?? {},
            retryAfter: data?.retry_after ?? (Number.isFinite(retryAfterHeader) ? retryAfterHeader : null),
            data,
            requestId: config?.requestId ?? null,
            isCanceled: isCancel(error),
            isTimeout: TIMEOUT_CODES.includes(error?.code),
            isNetworkError: !response && !isCancel(error) && !TIMEOUT_CODES.includes(error?.code),
            config,
            response,
            cause: error,
        });
    }
}

export const isCanceled = (error) => error instanceof ApiError ? error.isCanceled : isCancel(error);
