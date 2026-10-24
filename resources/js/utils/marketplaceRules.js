/**
 * Checks a work against a marketplace's rules: its file (marketplaces.file_rules, by media type)
 * and its base metadata (marketplaces.metadata_rules).
 * Pure functions, so the info panel and the publish flow show the same verdict.
 *
 * Every item: { level: 'ok' | 'warning' | 'error' | 'info' | 'off', key, params, choice }.
 * `key` is a translation key under library.checks; `choice` is the count for pluralized keys, otherwise null.
 */

const item = (level, key, params = {}, choice = null) => ({ level, key: `library.checks.${key}`, params, choice });

const needsFix = (items) => items.some((i) => i.level === 'warning' || i.level === 'error');

function verdict(items, { off = false } = {}) {
    return { items, off, needsFix: needsFix(items) };
}

export function checkAsset(asset, account) {
    const marketplace = account.marketplace;
    const rules = marketplace.metadata_rules ?? {};

    if (account.status === 'invalid_credentials') {
        return verdict([item('error', 'invalid_credentials')]);
    }

    if (account.status === 'disabled') {
        return verdict([item('off', 'disabled')], { off: true });
    }

    if (!marketplace.supported_types.includes(asset.type)) {
        return verdict([item('off', 'unsupported_type')], { off: true });
    }

    const items = fileItems(asset, marketplace.file_rules?.[asset.type]);

    if (!asset.title?.trim()) {
        items.push(item('warning', 'title_required'));
    }

    // Metadata entered in the marketplace's own portal: nothing more to check on our side.
    if (marketplace.metadata_delivery === 'manual') {
        items.push(item('info', 'manual_metadata'));

        return verdict(items);
    }

    const count = asset.keywords.length;
    const { keywords_min: min, keywords_max: max } = rules;

    if (!count) {
        items.push(item('warning', 'keywords_required'));
    } else if (min && count < min) {
        items.push(item('warning', 'keywords_min', { min, count }));
    } else if (max && count > max) {
        items.push(item('warning', 'keywords_max', { max, count }));
    } else {
        items.push(item('ok', 'keywords_ok', { count }, count));
    }

    if (rules.categories_max) {
        const chosen = asset.categories?.[marketplace.code]?.length ?? 0;

        if (chosen < (rules.categories_min ?? 0)) {
            items.push(item('warning', 'categories_min'));
        } else if (chosen) {
            items.push(item('ok', 'categories_ok'));
        }
    }

    return verdict(items);
}

// Rounded down, so a file just under the minimum never reads as "4.0 MP".
const megapixels = (width, height) => Math.floor((width * height) / 100_000) / 10;

const megabytes = (bytes) => Math.round(bytes / 104_857.6) / 10;

/**
 * File limits the marketplace rejects on upload. Errors, not warnings: editing metadata cannot fix them.
 * Skipped while the work's size is not known yet (still processing).
 */
function fileItems(asset, rules) {
    if (!rules) {
        return [];
    }

    const items = [];
    const { width, height, size_bytes: size } = asset;

    if (width && height) {
        const mp = megapixels(width, height);
        const params = { width, height, mp };

        if (rules.min_megapixels && (width * height) < rules.min_megapixels * 1_000_000) {
            items.push(item('error', 'resolution_min', { ...params, min: rules.min_megapixels }));
        } else if (rules.max_megapixels && (width * height) > rules.max_megapixels * 1_000_000) {
            items.push(item('error', 'resolution_max', { ...params, max: rules.max_megapixels }));
        }
    }

    if (size && rules.max_file_mb && size > rules.max_file_mb * 1024 * 1024) {
        items.push(item('error', 'file_too_large', { size: megabytes(size), max: rules.max_file_mb }));
    }

    return items;
}

/**
 * Number of connected marketplaces where the work cannot be published as is.
 */
export function countIssues(asset, accounts) {
    return accounts.filter((account) => checkAsset(asset, account).needsFix).length;
}

/**
 * Category fields of the metadata form: connected, enabled marketplaces that accept the type of work
 * and have a category list. Options are limited to the categories for that type.
 */
export function categoryFields(asset, accounts, categories) {
    return accounts
        .filter((account) => account.status !== 'disabled'
            && account.marketplace.supported_types.includes(asset.type)
            && categories[account.marketplace.code]?.length)
        .map((account) => {
            const { code, name, metadata_rules: rules = {} } = account.marketplace;

            return {
                code,
                name,
                min: rules.categories_min ?? 0,
                max: rules.categories_max ?? 1,
                options: categories[code].filter((c) => c.media_types.includes(asset.type)),
            };
        })
        // Two accounts on one marketplace share its categories: one field each.
        .filter((field, index, fields) => fields.findIndex((f) => f.code === field.code) === index);
}
