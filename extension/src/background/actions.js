/**
 * The last thing the extension did on each marketplace's site, for the popup: kept in chrome.storage.local
 * under "actions" by marketplace code.
 *
 * { at, found, submitted, errors: [string], error: string|null }
 */
const KEY = 'actions';

export async function all() {
    return (await chrome.storage.local.get(KEY))[KEY] ?? {};
}

export async function record(marketplace, action) {
    const actions = await all();
    actions[marketplace] = {
        found: action.found ?? 0,
        submitted: action.submitted ?? 0,
        errors: action.errors ?? [],
        error: action.error ?? null,
        at: new Date().toISOString(),
    };
    await chrome.storage.local.set({ [KEY]: actions });
}

export async function clearAll() {
    await chrome.storage.local.remove(KEY);
}
