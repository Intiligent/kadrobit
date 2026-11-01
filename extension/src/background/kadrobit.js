import { APP_URL } from '../config.js';
import { SignedOutError, accessToken, clear, load } from './auth.js';

/**
 * The extension API of Kadrobit (docs/browser-extension.md, «API расширения»).
 */
const REPORT_CHUNK = 500;

/**
 * A request with the extension's token; answers the response's `data`, or the whole body with `raw`.
 */
export async function request(path, { method = 'GET', body, raw = false } = {}, retried = false) {
    const response = await fetch(`${APP_URL}${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${await accessToken({ forceRefresh: retried })}`,
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    // An expired OAuth access token is refreshed once; a revoked token (disconnected in Kadrobit) or a personal one
    // Kadrobit does not accept signs the extension out.
    if (response.status === 401 && !retried && (await load())?.kind === 'oauth') {
        return request(path, { method, body, raw }, true);
    }

    if (response.status === 401 || response.status === 403) {
        const personal = (await load())?.kind === 'personal';
        await clear();
        throw new SignedOutError(personal
            ? 'Kadrobit does not accept this token. Create a new one in Settings → Extension.'
            : 'The extension was disconnected from Kadrobit. Sign in again.');
    }

    if (!response.ok) {
        throw new Error(`Kadrobit answered ${response.status} to ${path}`);
    }

    if (response.status === 204) {
        return null;
    }

    const json = await response.json();

    return raw ? json : json.data;
}

/**
 * The Kadrobit account, overall counts and the accounts on supported marketplaces.
 */
export function me() {
    return request('/api/extension/me');
}

/**
 * Every sent work on a marketplace, all pages.
 */
export async function listings(marketplace) {
    const all = [];
    let cursor = null;

    do {
        const query = new URLSearchParams({ marketplace, ...(cursor ? { cursor } : {}) });
        const json = await request(`/api/extension/listings?${query}`, { raw: true });
        all.push(...json.data);
        cursor = json.meta?.next_cursor ?? null;
    } while (cursor);

    return all;
}

/**
 * What the adapter found out, in chunks the API accepts. Answers how many works were updated.
 */
export async function report(reports) {
    let updated = 0;

    for (let start = 0; start < reports.length; start += REPORT_CHUNK) {
        const data = await request('/api/extension/listings/report', {
            method: 'POST',
            body: { listings: reports.slice(start, start + REPORT_CHUNK) },
        });
        updated += data.updated;
    }

    return updated;
}

export function logout() {
    return request('/api/extension/logout', { method: 'POST' });
}
