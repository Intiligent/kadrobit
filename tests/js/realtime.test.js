import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('laravel-echo', () => ({ default: vi.fn() }));
vi.mock('pusher-js', () => ({ default: vi.fn() }));

const { connectionChanged, dispatchEntityChanged, onEntityChanged, onReconnect, realtime } = await import('@/core/realtime');
const { pendingAssetIds } = await import('@/composables/usePendingAssets');

describe('realtime notices', () => {
    beforeEach(() => {
        connectionChanged('disconnected');
    });

    it('hands a notice to the handlers of its type only', () => {
        const assets = vi.fn();
        const listings = vi.fn();
        const offAssets = onEntityChanged('asset', assets);
        const offListings = onEntityChanged('listing', listings);

        dispatchEntityChanged({ type: 'asset', id: '01a', reason: 'metadata' });

        expect(assets).toHaveBeenCalledWith({ type: 'asset', id: '01a', reason: 'metadata' });
        expect(listings).not.toHaveBeenCalled();

        offAssets();
        dispatchEntityChanged({ type: 'asset', id: '01b', reason: 'metadata' });
        expect(assets).toHaveBeenCalledTimes(1);
        offListings();
    });

    it('runs reconnect handlers when the connection comes back, not on the first connect', () => {
        const handler = vi.fn();
        const off = onReconnect(handler);

        connectionChanged('connecting');
        connectionChanged('connected');
        expect(realtime.connected).toBe(true);
        expect(handler).not.toHaveBeenCalled();

        connectionChanged('unavailable');
        expect(realtime.connected).toBe(false);
        connectionChanged('connected');
        expect(handler).toHaveBeenCalledTimes(1);
        off();
    });

    it('ignores notices of unknown types', () => {
        expect(() => dispatchEntityChanged({ type: 'nothing', id: 'x' })).not.toThrow();
        expect(() => dispatchEntityChanged(null)).not.toThrow();
    });
});

describe('pendingAssetIds', () => {
    it('lists the works waiting for a generation', () => {
        expect(pendingAssetIds([
            { id: 'a', metadata_pending: true },
            { id: 'b', metadata_pending: false },
            { id: 'c' },
        ])).toEqual(['a']);
    });
});
