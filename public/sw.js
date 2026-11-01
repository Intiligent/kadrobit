/**
 * Kadrobit's service worker: browser notifications (docs/notifications.md). It lives at the site root so its scope
 * is the whole site, and is a plain file, not built by Vite: the browser checks it for updates by itself.
 *
 * A push carries the notification as laravel-notification-channels/webpush sends it: {title, body, icon, tag,
 * data: {url}}.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let message = {};

    try {
        message = event.data?.json() ?? {};
    } catch {
        message = { body: event.data?.text() };
    }

    const { title = 'Kadrobit', ...options } = message;

    event.waitUntil(self.registration.showNotification(title, options));
});

// A click opens the page the notification is about: in a Kadrobit tab already open if there is one.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL(event.notification.data?.url ?? '/', self.location.origin).href;

    event.waitUntil((async () => {
        const tabs = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const tab = tabs.find((client) => new URL(client.url).origin === self.location.origin);

        if (tab) {
            await tab.focus();

            return tab.navigate(url).catch(() => null);
        }

        return self.clients.openWindow(url);
    })());
});
