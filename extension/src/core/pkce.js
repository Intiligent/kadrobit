/**
 * PKCE for the OAuth sign-in (RFC 7636): a random verifier kept by the extension and its S256 challenge
 * sent to Kadrobit.
 */
export function randomString(bytes = 32) {
    return base64Url(crypto.getRandomValues(new Uint8Array(bytes)));
}

export async function challenge(verifier) {
    return base64Url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))));
}

function base64Url(bytes) {
    return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
