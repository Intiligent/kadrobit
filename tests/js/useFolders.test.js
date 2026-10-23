import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/core/api', () => ({
    default: { post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const { default: api } = await import('@/core/api');
const { useFolders } = await import('@/composables/useFolders');

const list = [{ id: 'f1', parent_id: null, name: 'Travel' }];
const invalid = (errors) => Object.assign(new Error('Invalid'), { status: 422, errors });

describe('useFolders', () => {
    afterEach(() => {
        vi.resetAllMocks();
    });

    it('replaces the list with the server one after a change', async () => {
        const next = [...list, { id: 'f2', parent_id: 'f1', name: 'Iceland' }];
        api.post.mockResolvedValue({ data: next, meta: { id: 'f2' } });
        const store = useFolders(list);

        const result = await store.create('Iceland', 'f1');

        expect(api.post).toHaveBeenCalledWith('/api/folders', { name: 'Iceland', parent_id: 'f1' }, { silent: [422] });
        expect(result).toMatchObject({ id: 'f2', error: null, failed: false });
        expect(store.folders.value).toEqual(next);
    });

    it('returns the validation message of a refused name and keeps the list', async () => {
        api.patch.mockRejectedValue(invalid({ name: ['There is already a folder with this name here.'] }));
        const store = useFolders(list);

        const result = await store.rename('f1', 'Food');

        expect(result).toMatchObject({ failed: true, error: 'There is already a folder with this name here.' });
        expect(store.folders.value).toEqual(list);
    });

    it('moves and deletes through the folder endpoints', async () => {
        api.patch.mockResolvedValue({ data: list });
        api.delete.mockResolvedValue({ data: [] });
        const store = useFolders(list);

        await store.move('f1', { parent_id: null, position: 0 });
        await store.remove('f1');

        expect(api.patch).toHaveBeenCalledWith('/api/folders/f1', { parent_id: null, position: 0 });
        expect(api.delete).toHaveBeenCalledWith('/api/folders/f1');
        expect(store.folders.value).toEqual([]);
    });

    it('moves works and reports the outcome', async () => {
        api.post.mockResolvedValueOnce({ data: { moved: 2 } }).mockRejectedValueOnce(new Error('Network'));
        const store = useFolders(list);

        expect(await store.moveAssets(['a1', 'a2'], 'f1')).toBe(true);
        expect(api.post).toHaveBeenCalledWith('/api/assets/move', { assets: ['a1', 'a2'], folder_id: 'f1' });
        expect(await store.moveAssets(['a1'], null)).toBe(false);
    });
});
