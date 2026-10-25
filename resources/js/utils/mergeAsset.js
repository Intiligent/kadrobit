/**
 * Writes the server's version of a work into the local one, in place.
 *
 * - A version older than the shown one is skipped: responses can arrive out of order (an autosave answered
 *   before a generation finished may come after the refresh that followed it).
 * - Fields in `local` (edited meanwhile, being sent or rejected) keep their local value.
 *
 * Returns whether the version was applied.
 */
export function mergeAsset(asset, saved, local = new Set()) {
    if (asset.updated_at && saved.updated_at && new Date(saved.updated_at) < new Date(asset.updated_at)) {
        return false;
    }

    for (const [field, value] of Object.entries(saved)) {
        if (!local.has(field)) {
            asset[field] = value;
        }
    }

    return true;
}
