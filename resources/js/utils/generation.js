/**
 * Metadata generation in the info panel (docs/metadata-generation.md): where each field came from
 * (asset.metadata_sources, App\Enums\MetadataSource) and what a regeneration or a restore would replace.
 */

/** Fields a generation writes, in the order the form shows them. */
export const GENERATED_FIELDS = ['title', 'description', 'keywords', 'categories'];

/** The field holds what the last generation wrote and nobody has edited it since: its label shows "AI". */
export const isGenerated = (asset, field) => asset.metadata_sources?.[field] === 'ai';

/**
 * Sources after a local edit: the edited fields belong to the user at once, so "AI" goes away while the change is
 * still being saved. The server records the same on PATCH.
 */
export function markEdited(sources, patch) {
    const edited = GENERATED_FIELDS.filter((field) => field in patch);

    if (!edited.length) {
        return sources;
    }

    return { ...sources, ...Object.fromEntries(edited.map((field) => [field, 'user'])) };
}

/** Fields the user typed in themselves, which a regeneration or a restore would replace: the confirmation names them. */
export const editedFields = (asset) => GENERATED_FIELDS.filter((field) => asset.metadata_sources?.[field] === 'user');

/**
 * State of a soft length limit: under it, or over it (shown in the warning colour; still saved, since some
 * marketplaces take longer texts).
 */
export const overLimit = (text, max) => Boolean(max) && (text ?? '').length > max;
