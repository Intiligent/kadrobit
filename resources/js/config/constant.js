// API client settings
export const API_DEFAULT_TIMEOUT = 20000;

// Route that renews the XSRF-TOKEN cookie after a 419
export const CSRF_REFRESH_URL = '/csrf-token';

// Issues a new API token cookie for the logged-in session
export const API_TOKEN_REFRESH_URL = '/auth/token';

// Auth pages. The API client sends the user to LOGIN_URL on 401.
export const LOGIN_URL = '/login';
// A full page visit, not an Inertia one: the browser leaves for Google.
export const GOOGLE_LOGIN_URL = '/oauth/google/redirect';
export const REGISTER_URL = '/register';
export const LOGOUT_URL = '/logout';
export const FORGOT_PASSWORD_URL = '/forgot-password';
export const RESET_PASSWORD_URL = '/reset-password';
export const VERIFY_EMAIL_URL = '/verify-email';

// Library: the main page after login, and its JSON endpoints (routes/api.php)
export const LIBRARY_URL = '/library';
export const ASSETS_API_URL = '/api/assets';
export const ASSETS_MOVE_API_URL = '/api/assets/move';
export const FOLDERS_API_URL = '/api/folders';
export const ASSET_UPLOADS_API_URL = '/api/assets/uploads';
// Works per GET /api/assets request (AssetController::LIST_MAX)
export const ASSETS_API_LIST_MAX = 100;
// Publishing to marketplaces: the batch of ready works, and cancelling or retrying one delivery
export const LISTINGS_BATCH_API_URL = '/api/listings/batch';
export const LISTINGS_API_URL = '/api/listings';

// Settings and their JSON endpoints
export const SETTINGS_URL = '/settings';
export const SETTINGS_GENERAL_URL = '/settings/general';
export const SETTINGS_MARKETPLACES_URL = '/settings/marketplaces';
export const SETTINGS_METADATA_URL = '/settings/metadata';
export const USER_SETTINGS_API_URL = '/api/settings';
export const MARKETPLACE_ACCOUNTS_API_URL = '/api/marketplace-accounts';

// Real-time notices (core/realtime.js): channel authorization; the poll that replaces them without a connection,
// and the rarer one that catches a lost notice while connected (ms)
export const BROADCASTING_AUTH_URL = '/broadcasting/auth';
export const REALTIME_FALLBACK_POLL_INTERVAL = 5000;
export const REALTIME_SAFETY_POLL_INTERVAL = 20000;

// Uploads: files sent at once, and how often a batch's processing is polled (ms)
export const ASSET_UPLOAD_CONCURRENCY = 3;
export const ASSET_UPLOAD_POLL_INTERVAL = 3000;

// Editing: edits are saved this long after the last change (ms). Keyword limits mirror UpdateAssetRequest.
export const ASSET_AUTOSAVE_DELAY = 600;
export const KEYWORDS_MAX = 50;
// The first keywords carry the most weight on marketplaces and are highlighted.
export const KEYWORDS_HIGHLIGHTED = 10;

// Drag-and-drop between the grid and the explorer: dataTransfer types of dragged works and folders
export const DRAG_ASSETS = 'application/x-kadrobit-assets';
export const DRAG_FOLDER = 'application/x-kadrobit-folder';

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
