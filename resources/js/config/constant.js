// API client settings
export const API_DEFAULT_TIMEOUT = 20000;

// Route that renews the XSRF-TOKEN cookie after a 419
export const CSRF_REFRESH_URL = '/csrf-token';

// Issues a new API token cookie for the logged-in session
export const API_TOKEN_REFRESH_URL = '/auth/token';

// Auth pages. The API client sends the user to LOGIN_URL on 401.
export const LOGIN_URL = '/login';
export const REGISTER_URL = '/register';
export const LOGOUT_URL = '/logout';
export const FORGOT_PASSWORD_URL = '/forgot-password';
export const RESET_PASSWORD_URL = '/reset-password';

// Automatic retries of idempotent requests on network failures and 502/503/504
export const RETRY = {
    attempts: 2,
    baseDelay: 400,
    statuses: [502, 503, 504],
};

// Error codes of JSON error responses. Mirrors App\Enums\ErrorCode; keep both in sync.
export const ERROR_CODE = {
    HTTP_ERROR: 140001,
    CSRF: 140101,
    UNAUTHENTICATED: 140302,
    ACCESS_DENIED: 140303,
    WITH_MESSAGE: 140305,
    NOT_FOUND: 140401,
    CONFLICT: 140901,
    VALIDATION: 142201,
    TOO_MANY_REQUESTS: 142901,
    INTERNAL: 150001,
};
