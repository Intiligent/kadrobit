/**
 * Library filters from the toolbar popover (LibraryFilters.vue): content types, marketplaces a work was uploaded to,
 * tags (by id). Every filter is a list; an empty list lets everything through. Values inside one list are
 * alternatives: a work matches any of them.
 */

// The "not uploaded anywhere" choice in the marketplace filter, next to account ids.
export const NOT_UPLOADED = 'none';

export const emptyFilters = () => ({ types: [], marketplaces: [], tags: [] });

/** Accounts a work has been uploaded to: listings sent (also those edited afterwards, "outdated"). */
export const uploadedAccounts = (asset) => (asset.listings ?? [])
    .filter((listing) => listing.status === 'sent')
    .map((listing) => listing.account_id);

export const matchesTypes = (asset, types) => !types.length || types.includes(asset.type);

export function matchesMarketplaces(asset, marketplaces) {
    if (!marketplaces.length) {
        return true;
    }

    const uploaded = uploadedAccounts(asset);

    return marketplaces.some((key) => (key === NOT_UPLOADED ? !uploaded.length : uploaded.includes(key)));
}

export const matchesTags = (asset, tags) => !tags.length || (asset.tags ?? []).some((tag) => tags.includes(tag.id));

export const matchesFilters = (asset, filters) => matchesTypes(asset, filters.types)
    && matchesMarketplaces(asset, filters.marketplaces)
    && matchesTags(asset, filters.tags);

/** Chosen values in all filters: the count on the toolbar button. */
export const activeCount = (filters) => filters.types.length + filters.marketplaces.length + filters.tags.length;

/**
 * Works per choice, for the counts in the popover:
 * { types: { photo: 3 }, marketplaces: { [accountId]: 2, none: 1 }, tags: { [tagId]: 4 } }.
 */
export function filterCounts(assets) {
    const counts = { types: {}, marketplaces: { [NOT_UPLOADED]: 0 }, tags: {} };

    for (const asset of assets) {
        counts.types[asset.type] = (counts.types[asset.type] ?? 0) + 1;

        for (const tag of asset.tags ?? []) {
            if (tag.id != null) {
                counts.tags[tag.id] = (counts.tags[tag.id] ?? 0) + 1;
            }
        }

        const uploaded = new Set(uploadedAccounts(asset));

        if (!uploaded.size) {
            counts.marketplaces[NOT_UPLOADED]++;
        }

        uploaded.forEach((id) => {
            counts.marketplaces[id] = (counts.marketplaces[id] ?? 0) + 1;
        });
    }

    return counts;
}

/** Toggles a value in a filter list, keeping the order it was chosen in. */
export const toggle = (list, value) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
