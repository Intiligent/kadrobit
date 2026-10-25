import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/core/api', () => ({
    default: { patch: vi.fn() },
}));

const { default: api } = await import('@/core/api');
const { useAssetAutosave } = await import('@/composables/useAssetAutosave');
const { ASSET_AUTOSAVE_DELAY } = await import('@/config/constant');

// Resolves pending promise callbacks between steps.
const flush = () => new Promise((resolve) => setImmediate(resolve));

const invalid = (errors) => Object.assign(new Error('Invalid'), { status: 422, errors });

describe('useAssetAutosave', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.resetAllMocks();
    });

    it('merges changes until the delay passes and sends them once', async () => {
        api.patch.mockResolvedValue({ data: { id: 'a1', title: 'Glacier' } });
        const onSaved = vi.fn();
        const autosave = useAssetAutosave({ onSaved });

        autosave.queue('a1', { title: 'Gla' });
        autosave.queue('a1', { title: 'Glacier' });
        autosave.queue('a1', { keywords: ['ice'] });

        expect(autosave.state.a1).toBe('pending');
        expect(autosave.unsaved.value).toBe(true);

        vi.advanceTimersByTime(ASSET_AUTOSAVE_DELAY);
        await flush();

        expect(api.patch).toHaveBeenCalledTimes(1);
        expect(api.patch).toHaveBeenCalledWith('/api/assets/a1', { title: 'Glacier', keywords: ['ice'] }, { silent: [422] });
        expect(autosave.state.a1).toBe('saved');
        expect(onSaved).toHaveBeenCalledWith({ id: 'a1', title: 'Glacier' }, new Set());
    });

    it('waits for the running request before sending newer changes', async () => {
        let finish;
        api.patch.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
        api.patch.mockResolvedValue({ data: { id: 'a1' } });
        const onSaved = vi.fn();
        const autosave = useAssetAutosave({ onSaved });

        autosave.queue('a1', { title: 'One' }, { immediate: true });
        vi.advanceTimersByTime(0);
        autosave.queue('a1', { title: 'Two' }, { immediate: true });
        vi.advanceTimersByTime(0);
        await flush();

        expect(api.patch).toHaveBeenCalledTimes(1);

        finish({ data: { id: 'a1', title: 'One' } });
        await flush();

        // The first response arrives while "Two" is queued: the title stays local.
        expect(onSaved).toHaveBeenNthCalledWith(1, { id: 'a1', title: 'One' }, new Set(['title']));
        expect(api.patch).toHaveBeenCalledTimes(2);
        expect(api.patch.mock.calls[1][1]).toEqual({ title: 'Two' });
    });

    it('shows field errors of a 422 and saves the rest of the request', async () => {
        api.patch.mockRejectedValueOnce(invalid({ 'categories.shutterstock': ['Unknown category'] }));
        api.patch.mockResolvedValue({ data: { id: 'a1' } });
        const onSaved = vi.fn();
        const autosave = useAssetAutosave({ onSaved });

        autosave.queue('a1', { title: 'Glacier', categories: { shutterstock: ['Moon'] } }, { immediate: true });
        vi.advanceTimersByTime(0);
        await flush();

        expect(autosave.errors.a1).toEqual({ categories: 'Unknown category' });

        vi.advanceTimersByTime(0);
        await flush();

        expect(api.patch.mock.calls[1][1]).toEqual({ title: 'Glacier' });
        // Still not everything is saved, and the rejected field keeps its local value.
        expect(autosave.state.a1).toBe('error');
        expect(onSaved).toHaveBeenCalledWith({ id: 'a1' }, new Set(['categories']));

        // Editing the field clears its error and sends it again.
        autosave.queue('a1', { categories: { shutterstock: ['Nature'] } });

        expect(autosave.errors.a1).toEqual({});
    });

    it('keeps the changes after a network error and sends them with the next edit', async () => {
        api.patch.mockRejectedValueOnce(Object.assign(new Error('Network'), { status: null }));
        api.patch.mockResolvedValue({ data: { id: 'a1' } });
        const onError = vi.fn();
        const autosave = useAssetAutosave();

        autosave.queue('a1', { status: 'ready' }, { immediate: true, onError });
        vi.advanceTimersByTime(0);
        await flush();

        expect(onError).toHaveBeenCalledOnce();
        expect(autosave.state.a1).toBe('error');

        autosave.queue('a1', { description: 'Icebergs' });
        vi.advanceTimersByTime(ASSET_AUTOSAVE_DELAY);
        await flush();

        expect(api.patch.mock.calls[1][1]).toEqual({ status: 'ready', description: 'Icebergs' });
        expect(autosave.state.a1).toBe('saved');
    });

    it('counts the fields of a running request as local until it answers', async () => {
        let answer;
        api.patch.mockReturnValue(new Promise((resolve) => { answer = resolve; }));
        const onSaved = vi.fn();
        const autosave = useAssetAutosave({ onSaved });

        autosave.queue('a1', { title: 'Glacier' }, { immediate: true });
        vi.advanceTimersByTime(0);
        await flush();

        // Being sent: a version fetched meanwhile must not undo it.
        expect([...autosave.localFields('a1')]).toEqual(['title']);

        answer({ data: { id: 'a1', title: 'Glacier' } });
        await flush();

        // The request's own answer is applied in full.
        expect([...onSaved.mock.calls[0][1]]).toEqual([]);
        expect([...autosave.localFields('a1')]).toEqual([]);
    });

    it('sends everything at once on flushAll', async () => {
        api.patch.mockResolvedValue({ data: {} });
        const autosave = useAssetAutosave();

        autosave.queue('a1', { title: 'One' });
        autosave.queue('a2', { title: 'Two' });
        await autosave.flushAll();

        expect(api.patch).toHaveBeenCalledTimes(2);
        expect(autosave.unsaved.value).toBe(false);
    });
    it('forgets the unsent changes of a work that goes away', async () => {
        const autosave = useAssetAutosave();

        autosave.queue('a1', { title: 'Gone soon' });
        autosave.forget('a1');
        vi.advanceTimersByTime(ASSET_AUTOSAVE_DELAY);
        await flush();

        expect(api.patch).not.toHaveBeenCalled();
        expect(autosave.state.a1).toBeUndefined();
        expect(autosave.unsaved.value).toBe(false);
    });
    it('ignores a request still running when the work is deleted, and later edits of it', async () => {
        let fail;
        api.patch.mockImplementationOnce(() => new Promise((resolve, reject) => { fail = reject; }));
        const onSaved = vi.fn();
        const autosave = useAssetAutosave({ onSaved });

        autosave.queue('a1', { title: 'Late' }, { immediate: true });
        vi.advanceTimersByTime(0);
        autosave.forget('a1');
        fail(Object.assign(new Error('Not found'), { status: 404 }));
        await flush();

        autosave.queue('a1', { title: 'After' });
        vi.advanceTimersByTime(ASSET_AUTOSAVE_DELAY);
        await flush();

        expect(api.patch).toHaveBeenCalledTimes(1);
        expect(autosave.state.a1).toBeUndefined();
        expect(autosave.unsaved.value).toBe(false);
        expect(onSaved).not.toHaveBeenCalled();
    });
});
