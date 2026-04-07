/* global self */

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'Web Push', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Web Push';
    const body = data.body || '';
    const url = data.url || '/';

    event.waitUntil(
        self.registration.showNotification(title, {
            body,
            data: { url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification?.data?.url || '/';

    event.waitUntil(
        (async () => {
            const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of allClients) {
                if ('focus' in client) {
                    await client.focus();
                    if ('navigate' in client) {
                        await client.navigate(url);
                    }
                    return;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(url);
            }
        })(),
    );
});

