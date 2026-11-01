/**
 * Stock sites the extension works on (config/extension.php, `marketplaces`). `matches` are the manifest patterns
 * for host permissions and content scripts; the build puts them in place of "@marketplaces".
 */
export const MARKETPLACES = [
    { code: 'shutterstock', name: 'Shutterstock', matches: ['https://submit.shutterstock.com/*'] },
    { code: '123rf', name: '123RF', matches: ['https://www.123rf.com/*', 'https://123rf.com/*'] },
    { code: 'adobe_stock', name: 'Adobe Stock', matches: ['https://contributor.stock.adobe.com/*'] },
];

/**
 * The marketplace whose site the URL is on, or null.
 *
 * @param {string|URL|null|undefined} url
 */
export function marketplaceForUrl(url) {
    if (!url) {
        return null;
    }

    let parsed;

    try {
        parsed = new URL(url);
    } catch {
        return null;
    }

    return MARKETPLACES.find((marketplace) => marketplace.matches.some((pattern) => matchesPattern(parsed, pattern))) ?? null;
}

/**
 * A manifest match pattern of the form used above: scheme, exact host, any path.
 */
function matchesPattern(url, pattern) {
    const { protocol, hostname } = new URL(pattern.replace(/\*$/, ''));

    return url.protocol === protocol && url.hostname === hostname;
}
