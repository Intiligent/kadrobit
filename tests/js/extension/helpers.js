import { vi } from 'vitest';

/**
 * A stand-in for the chrome.* APIs the extension uses: storage.local in memory, runtime messages handed to
 * `onMessage`, identity answering with `authRedirect`.
 */
export function fakeChrome({ onMessage = async () => null, authRedirect = null } = {}) {
    const store = {};

    globalThis.chrome = {
        storage: {
            local: {
                get: vi.fn(async (key) => (key in store ? { [key]: structuredClone(store[key]) } : {})),
                set: vi.fn(async (items) => Object.assign(store, structuredClone(items))),
                remove: vi.fn(async (key) => delete store[key]),
            },
        },
        runtime: {
            sendMessage: vi.fn(async (message) => {
                try {
                    return { ok: true, data: await onMessage(message) };
                } catch (error) {
                    return { ok: false, error: error.message };
                }
            }),
        },
        identity: {
            getRedirectURL: () => 'https://fpdfkhaaacgkafhamlleoadkbkekeokp.chromiumapp.org/',
            launchWebAuthFlow: vi.fn(async ({ url }) => authRedirect(new URL(url))),
        },
    };

    return store;
}

export function jsonResponse(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}
