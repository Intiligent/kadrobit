/**
 * The user's tags on the frontend: `{ id, name }`, `id` null for a tag typed in but not saved yet.
 * Names are compared ignoring case, as the server does ("Batumi" and "batumi" are one tag).
 */

const sameName = (a, b) => a.localeCompare(b, undefined, { sensitivity: 'accent' }) === 0;

/** The same tag: by id once both are saved, otherwise by name. */
export const sameTag = (a, b) => (a.id != null && b.id != null ? a.id === b.id : sameName(a.name, b.name));

export const sortTags = (tags) => [...tags].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true }));

/** Value of a tag in the picker (el-select): the id of a saved tag, the name of a new one. */
export const tagValue = (tag) => tag.id ?? tag.name;

/**
 * Picker values back to tags. An id is looked up among the known tags; a typed name becomes the known tag of that
 * name, or a new one. Empty names and repeats are dropped, the order is kept.
 */
export function tagsFromValues(values, known) {
    const tags = [];

    for (const value of values) {
        const tag = typeof value === 'number'
            ? known.find((t) => t.id === value)
            : tagFromName(String(value).trim(), known);

        if (tag && !tags.some((t) => sameTag(t, tag))) {
            tags.push(tag);
        }
    }

    return tags;
}

function tagFromName(name, known) {
    if (!name) {
        return null;
    }

    return known.find((t) => sameName(t.name, name)) ?? { id: null, name };
}

/** The saved tags of `added` put into the list `tags`, alphabetically: e.g. tags created by a save. */
export function withTags(tags, added) {
    const fresh = added.filter((tag) => tag.id != null && !tags.some((t) => t.id === tag.id));

    return fresh.length ? sortTags([...tags, ...fresh]) : tags;
}
