import { onScopeDispose, watch } from 'vue';
import api from '@/core/api';
import { onEntityChanged, onReconnect, realtime } from '@/core/realtime';
import { ASSETS_API_LIST_MAX, ASSETS_API_URL, REALTIME_FALLBACK_POLL_INTERVAL, REALTIME_SAFETY_POLL_INTERVAL } from '@/config/constant';
import { hasPendingListings } from '@/utils/publishing';

/** Works waiting for a background generation or for their uploads to marketplaces. */
export const pendingAssetIds = (assets) => assets
    .filter((asset) => asset.metadata_pending || hasPendingListings(asset))
    .map((asset) => asset.id);

/** How long a notice for a work not in the list yet is kept: it may arrive before the upload poll brings the work. */
const EARLY_NOTICE_MS = 60_000;

/**
 * Works waiting for a background generation (`metadata_pending`) or with uploads to marketplaces in the queue
 * (`listings`) are fetched again when it is over:
 * - on the server's real-time notice, also one that came before the work reached the list;
 * - after a reconnect: notices sent meanwhile may have been missed;
 * - by a poll: every `interval` while there is no real-time connection, and every `safetyInterval` while there
 *   is one, since a notice can still be lost (the server could not send it, the channel was not subscribed).
 *
 * `merge(asset)` takes each fresh version. Works in a component or any effect scope.
 */
export function usePendingAssets({
    assets,
    merge,
    interval = REALTIME_FALLBACK_POLL_INTERVAL,
    safetyInterval = REALTIME_SAFETY_POLL_INTERVAL,
}) {
    // id → when its notice came, for works not in the list yet
    const early = new Map();
    let lastPoll = Date.now();

    async function refresh(ids) {
        // The API takes ASSETS_API_LIST_MAX works per request; a large publishing batch needs several.
        for (let start = 0; start < ids.length; start += ASSETS_API_LIST_MAX) {
            try {
                const { data } = await api.get(ASSETS_API_URL, { ids: ids.slice(start, start + ASSETS_API_LIST_MAX) }, { silent: true });
                data.forEach((asset) => merge(asset));
            } catch {
                // Tried again on the next notice, reconnect or poll.
            }
        }
    }

    const offChanged = onEntityChanged('asset', ({ id }) => {
        if (assets.value.some((asset) => asset.id === id)) {
            refresh([id]);
        } else {
            early.set(id, Date.now());
        }
    });

    // A work whose notice came early: fetched as soon as it shows up still pending.
    watch(() => pendingAssetIds(assets.value).join(), () => {
        const now = Date.now();
        const due = pendingAssetIds(assets.value).filter((id) => early.has(id) && now - early.get(id) < EARLY_NOTICE_MS);

        due.forEach((id) => early.delete(id));
        refresh(due);
    });

    const offReconnect = onReconnect(() => refresh(pendingAssetIds(assets.value)));

    const timer = setInterval(() => {
        if (Date.now() - lastPoll < (realtime.connected ? safetyInterval : interval)) {
            return;
        }

        lastPoll = Date.now();
        refresh(pendingAssetIds(assets.value));

        for (const [id, at] of early) {
            if (Date.now() - at >= EARLY_NOTICE_MS) {
                early.delete(id);
            }
        }
    }, Math.min(interval, safetyInterval));

    onScopeDispose(() => {
        offChanged();
        offReconnect();
        clearInterval(timer);
    });

    return { refresh };
}
