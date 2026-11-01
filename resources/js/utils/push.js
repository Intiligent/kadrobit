/**
 * Browser notifications of the current browser (docs/notifications.md): the service worker public/sw.js and its push
 * subscription, kept on the server through PUSH_SUBSCRIPTIONS_API_URL.
 */
import api from '@/core/api';
import { PUSH_SUBSCRIPTIONS_API_URL } from '@/config/constant';

const SERVICE_WORKER_URL = '/sw.js';

export const pushSupported = () => typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window;

/**
 * 'unsupported', 'blocked' (the user denied the permission), 'on' (subscribed) or 'off'.
 */
export async function pushState() {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    return (await currentSubscription()) ? 'on' : 'off';
}

async function currentSubscription() {
    const registration = await navigator.serviceWorker.getRegistration(SERVICE_WORKER_URL);

    return registration ? registration.pushManager.getSubscription() : null;
}

/**
 * Asks for the permission, subscribes and keeps the subscription on the server. Answers the new state.
 *
 * @param {string} vapidPublicKey The server's VAPID public key, base64url
 */
export async function turnOn(vapidPublicKey) {
    if ((await Notification.requestPermission()) !== 'granted') {
        return Notification.permission === 'denied' ? 'blocked' : 'off';
    }

    await navigator.serviceWorker.register(SERVICE_WORKER_URL);
    const registration = await navigator.serviceWorker.ready;

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(vapidPublicKey),
    });

    await api.post(PUSH_SUBSCRIPTIONS_API_URL, {
        ...subscription.toJSON(),
        content_encoding: (PushManager.supportedContentEncodings ?? ['aes128gcm'])[0],
    });

    return 'on';
}

/** Unsubscribes this browser and forgets it on the server. */
export async function turnOff() {
    const subscription = await currentSubscription();

    if (subscription) {
        await api.delete(PUSH_SUBSCRIPTIONS_API_URL, { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
    }

    return 'off';
}

export function base64UrlToBytes(value) {
    const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}
