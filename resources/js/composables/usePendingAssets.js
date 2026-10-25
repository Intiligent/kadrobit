import { onBeforeUnmount } from 'vue';
import api from '@/core/api';
import { onEntityChanged, onReconnect, realtime } from '@/core/realtime';
import { ASSETS_API_URL, REALTIME_FALLBACK_POLL_INTERVAL } from '@/config/constant';

export const pendingAssetIds = (assets) => assets.filter((asset) => asset.metadata_pending).map((asset) => asset.id);

/**
 * Works waiting for a background generation (`metadata_pending`) are fetched again when it is over:
 * on the server's real-time notice, after a reconnect (notices may have been missed), and by a poll
 * while there is no real-time connection. `merge(asset)` takes each fresh version.
 */
export function usePendingAssets({ assets, merge, interval = REALTIME_FALLBACK_POLL_INTERVAL }) {
    async function refresh(ids) {
        if (!ids.length) {
            return;
        }

        try {
            const { data } = await api.get(ASSETS_API_URL, { ids }, { silent: true });
            data.forEach(merge);
        } catch {
            // Tried again on the next notice, reconnect or poll.
        }
    }

    const offChanged = onEntityChanged('asset', ({ id }) => {
        if (assets.value.some((asset) => asset.id === id)) {
            refresh([id]);
        }
    });
    const offReconnect = onReconnect(() => refresh(pendingAssetIds(assets.value)));
    const timer = setInterval(() => {
        if (!realtime.connected) {
            refresh(pendingAssetIds(assets.value));
        }
    }, interval);

    onBeforeUnmount(() => {
        offChanged();
        offReconnect();
        clearInterval(timer);
    });

    return { refresh };
}
