/**
 * Messages between the popup, the content scripts on stock sites and the background worker, which alone holds
 * the Kadrobit token. Every answer is { ok: true, data } or { ok: false, error }.
 */
export const MESSAGE = {
    // Popup → background
    STATE: 'state',
    SIGN_IN: 'sign-in',
    SIGN_IN_WITH_TOKEN: 'sign-in-with-token',
    SIGN_OUT: 'sign-out',
    // Content script → background
    LISTINGS: 'listings',
    REPORT: 'report',
    ACTION: 'action',
    // Popup → content script
    RUN: 'run',
};

/**
 * Sends a message to the background worker and unwraps its answer.
 */
export async function send(type, payload = {}) {
    const answer = await chrome.runtime.sendMessage({ type, ...payload });

    if (!answer?.ok) {
        throw new Error(answer?.error ?? 'The extension did not answer');
    }

    return answer.data;
}
