import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeChrome, jsonResponse } from './helpers.js';

const APP_URL = 'https://kadrobit.test';

let auth;
let kadrobit;
let store;

beforeEach(async () => {
    vi.resetModules();
    vi.stubEnv('VITE_EXTENSION_APP_URL', APP_URL);
    vi.stubEnv('VITE_EXTENSION_CLIENT_ID', 'client-1');
    auth = await import('../../../extension/src/background/auth.js');
    kadrobit = await import('../../../extension/src/background/kadrobit.js');
});

afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
});

const tokens = (n, expiresIn = 7200) => jsonResponse(200, { access_token: `access-${n}`, refresh_token: `refresh-${n}`, expires_in: expiresIn });

describe('signIn', () => {
    it('runs the PKCE flow and keeps the tokens', async () => {
        let request;
        store = fakeChrome({
            authRedirect: (url) => {
                request = url;

                return `https://fpdfkhaaacgkafhamlleoadkbkekeokp.chromiumapp.org/?code=code-1&state=${url.searchParams.get('state')}`;
            },
        });
        const fetch = vi.fn(async () => tokens(1));
        vi.stubGlobal('fetch', fetch);

        await auth.signIn();

        expect(request.origin + request.pathname).toBe(`${APP_URL}/oauth/authorize`);
        expect(request.searchParams.get('client_id')).toBe('client-1');
        expect(request.searchParams.get('scope')).toBe('extension');
        expect(request.searchParams.get('code_challenge_method')).toBe('S256');

        const body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(fetch.mock.calls[0][0]).toBe(`${APP_URL}/oauth/token`);
        expect(body).toMatchObject({ grant_type: 'authorization_code', client_id: 'client-1', code: 'code-1' });
        expect(body.code_verifier.length).toBeGreaterThanOrEqual(43);
        expect(store.auth).toMatchObject({ kind: 'oauth', accessToken: 'access-1', refreshToken: 'refresh-1' });
    });

    it('refuses an answer to another request', async () => {
        fakeChrome({ authRedirect: () => 'https://fpdfkhaaacgkafhamlleoadkbkekeokp.chromiumapp.org/?code=x&state=forged' });
        vi.stubGlobal('fetch', vi.fn());

        await expect(auth.signIn()).rejects.toThrow('does not match');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows why Kadrobit refused the code instead of calling the sign-in expired', async () => {
        store = fakeChrome({ authRedirect: (url) => `https://x.chromiumapp.org/?code=c&state=${url.searchParams.get('state')}` });
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(400, { error: 'invalid_grant', error_description: 'Authorization code has been revoked' })));

        await expect(auth.signIn()).rejects.toThrow('Kadrobit refused the sign-in (400: Authorization code has been revoked).');
    });

    it('tells a denied request apart', async () => {
        fakeChrome({ authRedirect: (url) => `https://x.chromiumapp.org/?error=access_denied&state=${url.searchParams.get('state')}` });

        await expect(auth.signIn()).rejects.toThrow('Access was not allowed.');
    });
});

describe('tokens', () => {
    it('refreshes an access token that is about to expire', async () => {
        store = fakeChrome();
        store.auth = { kind: 'oauth', accessToken: 'old', refreshToken: 'refresh-0', expiresAt: Date.now() + 10_000 };
        const fetch = vi.fn(async () => tokens(1));
        vi.stubGlobal('fetch', fetch);

        expect(await auth.accessToken()).toBe('access-1');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ grant_type: 'refresh_token', refresh_token: 'refresh-0' });
        expect(store.auth.refreshToken).toBe('refresh-1');
    });

    it('refreshes once for parallel requests', async () => {
        store = fakeChrome();
        store.auth = { kind: 'oauth', accessToken: 'old', refreshToken: 'refresh-0', expiresAt: 0 };
        const fetch = vi.fn(async () => tokens(1));
        vi.stubGlobal('fetch', fetch);

        await Promise.all([auth.accessToken(), auth.accessToken(), auth.accessToken()]);

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('signs out when the refresh token is rejected', async () => {
        store = fakeChrome();
        store.auth = { kind: 'oauth', accessToken: 'old', refreshToken: 'refresh-0', expiresAt: 0 };
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(400, { error: 'invalid_grant' })));

        await expect(auth.accessToken()).rejects.toBeInstanceOf(auth.SignedOutError);
        expect(store.auth).toBeUndefined();
    });

    it('uses a personal token as it is', async () => {
        store = fakeChrome();
        await auth.signInWithToken('  personal-1 \n');

        expect(await auth.accessToken()).toBe('personal-1');
    });
});

describe('kadrobit API', () => {
    it('reads all pages of listings', async () => {
        store = fakeChrome();
        store.auth = { kind: 'personal', accessToken: 'personal-1' };
        const fetch = vi.fn(async (url) => (url.includes('cursor=c2')
            ? jsonResponse(200, { data: [{ id: 2 }], meta: { next_cursor: null } })
            : jsonResponse(200, { data: [{ id: 1 }], meta: { next_cursor: 'c2' } })));
        vi.stubGlobal('fetch', fetch);

        expect(await kadrobit.listings('shutterstock')).toEqual([{ id: 1 }, { id: 2 }]);
        expect(fetch.mock.calls[0][1].headers.Authorization).toBe('Bearer personal-1');
    });

    it('reports in chunks the API accepts', async () => {
        store = fakeChrome();
        store.auth = { kind: 'personal', accessToken: 'personal-1' };
        const fetch = vi.fn(async (url, { body }) => jsonResponse(200, { data: { updated: JSON.parse(body).listings.length } }));
        vi.stubGlobal('fetch', fetch);

        const reports = Array.from({ length: 1201 }, (_, id) => ({ id }));

        expect(await kadrobit.report(reports)).toBe(1201);
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('does not try to refresh a personal token Kadrobit refuses', async () => {
        store = fakeChrome();
        store.auth = { kind: 'personal', accessToken: 'mistyped' };
        const fetch = vi.fn(async () => jsonResponse(401, {}));
        vi.stubGlobal('fetch', fetch);

        await expect(kadrobit.me()).rejects.toThrow('Kadrobit does not accept this token.');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(store.auth).toBeUndefined();
    });

    it('forgets a token Kadrobit no longer accepts', async () => {
        store = fakeChrome();
        store.auth = { kind: 'personal', accessToken: 'revoked' };
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(401, {})));

        await expect(kadrobit.me()).rejects.toBeInstanceOf(auth.SignedOutError);
        expect(store.auth).toBeUndefined();
    });
});
