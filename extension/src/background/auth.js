import { APP_URL, CLIENT_ID, SCOPE } from '../config.js';
import { challenge, randomString } from '../core/pkce.js';

/**
 * The Kadrobit sign-in, kept in chrome.storage.local under "auth":
 * - oauth: { kind, accessToken, refreshToken, expiresAt } from the Authorization Code flow with PKCE;
 * - personal: { kind, accessToken } pasted from Settings → Extension, lasts a year, cannot be refreshed.
 */
const KEY = 'auth';

// Refresh this long before the access token expires.
const REFRESH_MARGIN_MS = 60_000;

export class SignedOutError extends Error {
    constructor(message = 'Sign in to Kadrobit') {
        super(message);
        this.name = 'SignedOutError';
    }
}

export async function load() {
    return (await chrome.storage.local.get(KEY))[KEY] ?? null;
}

async function save(auth) {
    await chrome.storage.local.set({ [KEY]: auth });
}

export async function clear() {
    await chrome.storage.local.remove(KEY);
}

/**
 * Opens the Kadrobit consent window; a signed-out user signs in to Kadrobit there first.
 */
export async function signIn() {
    const verifier = randomString(48);
    const state = randomString(16);
    const redirectUri = chrome.identity.getRedirectURL();

    const url = new URL(`${APP_URL}/oauth/authorize`);
    url.search = new URLSearchParams({
        client_id: CLIENT_ID,
        redirect_uri: redirectUri,
        response_type: 'code',
        scope: SCOPE,
        state,
        code_challenge: await challenge(verifier),
        code_challenge_method: 'S256',
    });

    const answer = new URL(await chrome.identity.launchWebAuthFlow({ url: url.href, interactive: true }));

    if (answer.searchParams.get('state') !== state) {
        throw new Error('The sign-in answer does not match the request. Try again.');
    }

    if (answer.searchParams.has('error')) {
        throw new Error(answer.searchParams.get('error') === 'access_denied' ? 'Access was not allowed.' : 'Kadrobit refused the sign-in.');
    }

    await requestTokens({
        grant_type: 'authorization_code',
        client_id: CLIENT_ID,
        redirect_uri: redirectUri,
        code_verifier: verifier,
        code: answer.searchParams.get('code'),
    });
}

/**
 * A personal token from Settings → Extension. Checked by the caller with a first API request.
 */
export async function signInWithToken(token) {
    await save({ kind: 'personal', accessToken: token.trim() });
}

/**
 * The access token for a request, refreshed when it is about to expire.
 */
export async function accessToken({ forceRefresh = false } = {}) {
    const auth = await load();

    if (!auth) {
        throw new SignedOutError();
    }

    const expiring = auth.kind === 'oauth' && auth.expiresAt - REFRESH_MARGIN_MS < Date.now();

    if (forceRefresh || expiring) {
        return (await refresh()).accessToken;
    }

    return auth.accessToken;
}

let refreshing = null;

/**
 * New tokens from the refresh token; one refresh at a time, since the old refresh token stops working.
 * A rejected refresh signs the extension out.
 */
export function refresh() {
    refreshing ??= (async () => {
        const auth = await load();

        if (auth?.kind !== 'oauth' || !auth.refreshToken) {
            await clear();
            throw new SignedOutError();
        }

        return requestTokens({
            grant_type: 'refresh_token',
            client_id: CLIENT_ID,
            refresh_token: auth.refreshToken,
            scope: SCOPE,
        });
    })().finally(() => {
        refreshing = null;
    });

    return refreshing;
}

async function requestTokens(body) {
    const response = await fetch(`${APP_URL}/oauth/token`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });

    if ((response.status === 400 || response.status === 401) && body.grant_type === 'refresh_token') {
        await clear();
        throw new SignedOutError('The Kadrobit sign-in has expired. Sign in again.');
    }

    if (!response.ok) {
        const reason = await response.json().then((json) => json.error_description ?? json.error).catch(() => null);
        throw new Error(`Kadrobit refused the sign-in (${response.status}${reason ? `: ${reason}` : ''}).`);
    }

    const json = await response.json();
    const auth = {
        kind: 'oauth',
        accessToken: json.access_token,
        refreshToken: json.refresh_token,
        expiresAt: Date.now() + json.expires_in * 1000,
    };

    await save(auth);

    return auth;
}
