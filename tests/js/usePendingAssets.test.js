import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

vi.mock('laravel-echo', () => ({ default: vi.fn() }));
vi.mock('pusher-js', () => ({ default: vi.fn() }));
vi.mock('@/core/api', () => ({ default: { get: vi.fn() } }));

const { default: api } = await import('@/core/api');
const { connectionChanged, dispatchEntityChanged } = await import('@/core/realtime');
const { usePendingAssets } = await import('@/composables/usePendingAssets');

describe('usePendingAssets', () => {
    let scope;
    let assets;
    let merge;

    function start() {
        scope = effectScope();
        scope.run(() => usePendingAssets({ assets, merge, interval: 5000, safetyInterval: 20000 }));
    }

    beforeEach(() => {
        vi.useFakeTimers();
        api.get.mockReset().mockResolvedValue({ data: [{ id: 'a', metadata_pending: false }] });
        assets = ref([{ id: 'a', metadata_pending: true }, { id: 'b', metadata_pending: false }]);
        merge = vi.fn();
        connectionChanged('disconnected');
    });

    afterEach(() => {
        scope.stop();
        vi.useRealTimers();
    });

    it('polls works with uploads to marketplaces in the queue too', async () => {
        assets.value.push({ id: 'c', metadata_pending: false, listings: [{ status: 'queued' }] });
        start();

        await vi.advanceTimersByTimeAsync(5000);

        expect(api.get).toHaveBeenCalledWith('/api/assets', { ids: ['a', 'c'] }, { silent: true });
    });

    it('fetches a long list of works in parts the API takes', async () => {
        const ids = Array.from({ length: 150 }, (_, i) => `w${i}`);
        let composable;
        scope = effectScope();
        scope.run(() => {
            composable = usePendingAssets({ assets, merge, interval: 5000, safetyInterval: 20000 });
        });

        await composable.refresh(ids);

        expect(api.get).toHaveBeenCalledTimes(2);
        expect(api.get.mock.calls[0][1].ids).toHaveLength(100);
        expect(api.get.mock.calls[1][1].ids).toEqual(ids.slice(100));
    });

    it('polls the pending works every few seconds without a connection', async () => {
        start();

        await vi.advanceTimersByTimeAsync(5000);

        expect(api.get).toHaveBeenCalledWith('/api/assets', { ids: ['a'] }, { silent: true });
        expect(merge).toHaveBeenCalledWith({ id: 'a', metadata_pending: false });
    });

    it('still polls, more rarely, while connected: a notice can be lost', async () => {
        connectionChanged('connected');
        start();

        await vi.advanceTimersByTimeAsync(15000);
        expect(api.get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(5000);
        expect(api.get).toHaveBeenCalledTimes(1);
    });

    it('fetches a work on its notice', async () => {
        connectionChanged('connected');
        start();

        dispatchEntityChanged({ type: 'asset', id: 'b', reason: 'metadata' });

        expect(api.get).toHaveBeenCalledWith('/api/assets', { ids: ['b'] }, { silent: true });
    });

    it('keeps a notice that came before its work reached the list', async () => {
        connectionChanged('connected');
        start();

        dispatchEntityChanged({ type: 'asset', id: 'c', reason: 'metadata' });
        expect(api.get).not.toHaveBeenCalled();

        assets.value.push({ id: 'c', metadata_pending: true });
        await nextTick();

        expect(api.get).toHaveBeenCalledWith('/api/assets', { ids: ['c'] }, { silent: true });
    });

    it('stops with its scope', async () => {
        start();
        scope.stop();

        await vi.advanceTimersByTimeAsync(10000);
        dispatchEntityChanged({ type: 'asset', id: 'a', reason: 'metadata' });

        expect(api.get).not.toHaveBeenCalled();
        scope = effectScope();
    });
});
