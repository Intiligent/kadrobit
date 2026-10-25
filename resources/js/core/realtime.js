import { reactive } from 'vue';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api';
import { BROADCASTING_AUTH_URL } from '@/config/constant';

/**
 * Real-time notices from the server (App\Events\EntityChanged on the user's private channel, docs/realtime.md).
 *
 * A notice says only what changed — { type: 'asset', id, reason: 'metadata' } — and handlers fetch the data
 * through the API. Pages register a handler per type with onEntityChanged(); onReconnect() runs after the
 * connection comes back, when notices sent meanwhile may have been missed.
 *
 * Without VITE_PUSHER_APP_KEY nothing connects and `realtime.connected` stays false: pages then poll for
 * what they wait for (usePendingAssets), so the app works the same, only slower.
 */
export const realtime = reactive({ connected: false });

// type → Set of handlers
const handlers = new Map();
const reconnectHandlers = new Set();
let echo = null;
let channelName = null;
let wasConnected = false;

export function onEntityChanged(type, handler) {
    if (!handlers.has(type)) {
        handlers.set(type, new Set());
    }

    handlers.get(type).add(handler);

    return () => handlers.get(type)?.delete(handler);
}

export function onReconnect(handler) {
    reconnectHandlers.add(handler);

    return () => reconnectHandlers.delete(handler);
}

// Exported for tests: what the channel listener and the connection hand over.
export function dispatchEntityChanged(notice) {
    handlers.get(notice?.type)?.forEach((handler) => handler(notice));
}

export function connectionChanged(state) {
    const connected = state === 'connected';

    if (connected && !realtime.connected && wasConnected) {
        reconnectHandlers.forEach((handler) => handler());
    }

    wasConnected ||= connected;
    realtime.connected = connected;
}

/**
 * Connects and subscribes to the user's channel once; a later call for another user switches the channel.
 */
export function startRealtime(userUlid, { key = import.meta.env.VITE_PUSHER_APP_KEY, cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER } = {}) {
    if (!key || !userUlid) {
        return;
    }

    echo ??= createEcho(key, cluster);

    const name = `users.${userUlid}`;

    if (channelName === name) {
        return;
    }

    if (channelName) {
        echo.leave(channelName);
    }

    channelName = name;
    echo.private(name).listen('.entity.changed', dispatchEntityChanged);
}

export function stopRealtime() {
    if (channelName) {
        echo?.leave(channelName);
        channelName = null;
    }
}

function createEcho(key, cluster) {
    const instance = new Echo({
        broadcaster: 'pusher',
        Pusher,
        key,
        cluster,
        forceTLS: true,
        // Channel authorization goes through the app's API client: session cookie, XSRF token, CSRF renewal.
        authorizer: (channel) => ({
            authorize(socketId, callback) {
                api.post(BROADCASTING_AUTH_URL, { socket_id: socketId, channel_name: channel.name }, { silent: true })
                    .then((data) => callback(null, data))
                    .catch((error) => callback(error, null));
            },
        }),
    });

    instance.connector.pusher.connection.bind('state_change', ({ current }) => connectionChanged(current));

    return instance;
}
