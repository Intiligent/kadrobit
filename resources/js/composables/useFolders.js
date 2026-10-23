import { ref } from 'vue';
import api from '@/core/api';
import { ASSETS_MOVE_API_URL, FOLDERS_API_URL } from '@/config/constant';

/**
 * The library's folders as a flat list in tree order ([{ id, parent_id, name }]), and their changes.
 * Every folder change answers with the whole list, which replaces the local one: positions of the
 * siblings are the server's.
 *
 * Name changes resolve to `{ error }` with the message of a 422, so the inline field can show it;
 * other failures are reported by the API client and resolve to `{ error: null, failed: true }`.
 */
export function useFolders(initial = []) {
    const folders = ref(initial.map((folder) => ({ ...folder })));

    async function call(request) {
        try {
            const response = await request();
            folders.value = response.data;

            return { response, error: null, failed: false };
        } catch (error) {
            const message = error.status === 422 ? Object.values(error.errors ?? {})[0]?.[0] ?? error.message : null;

            return { response: null, error: message, failed: true };
        }
    }

    async function create(name, parentId = null) {
        const result = await call(() => api.post(FOLDERS_API_URL, { name, parent_id: parentId }, { silent: [422] }));

        return { ...result, id: result.response?.meta?.id ?? null };
    }

    const rename = (id, name) => call(() => api.patch(`${FOLDERS_API_URL}/${id}`, { name }, { silent: [422] }));

    // `placement`: { parent_id, position } from dropPlacement(). A refused move (422) is reported like other errors.
    const move = (id, placement) => call(() => api.patch(`${FOLDERS_API_URL}/${id}`, placement));

    const remove = (id) => call(() => api.delete(`${FOLDERS_API_URL}/${id}`));

    /**
     * Puts works into a folder (null: Unsorted). Resolves to true on success.
     */
    async function moveAssets(ids, folderId) {
        try {
            await api.post(ASSETS_MOVE_API_URL, { assets: ids, folder_id: folderId });

            return true;
        } catch {
            return false;
        }
    }

    return { folders, create, rename, move, remove, moveAssets };
}
