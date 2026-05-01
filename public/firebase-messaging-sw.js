// Firebase Cloud Messaging — background service worker.
// Loaded by /firebase-messaging-sw.js, registered from
// resources/views/filament/fcm-init.blade.php after permission grant.
// Service-worker context can't read Laravel config, so the public web
// values from the Firebase console are inlined here. The private
// service-account credentials live in storage/ on the server only.

importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: 'AIzaSyBbOpRe9dujYbwHz31pGbd4OLqeVq07ngA',
    authDomain: 'gursoy-ailem-rezervasyon.firebaseapp.com',
    projectId: 'gursoy-ailem-rezervasyon',
    storageBucket: 'gursoy-ailem-rezervasyon.firebasestorage.app',
    messagingSenderId: '880306329585',
    appId: '1:880306329585:web:88d838ba3c666637b59650',
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function (payload) {
    const title = (payload.data && payload.data.title)
        || (payload.notification && payload.notification.title)
        || 'Arıza Takip';
    const body = (payload.data && payload.data.body)
        || (payload.notification && payload.notification.body)
        || '';
    const url = (payload.data && payload.data.url) || '/';

    // Skip if the page is already focused — the page's onMessage handler
    // shows the Filament toast for foreground messages, and FCM SDK fires
    // BOTH paths when the payload includes a notification block. Without
    // this guard the user sees two popups for one push.
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function (clientList) {
            const hasFocus = clientList.some(function (c) { return c.focused; });
            if (hasFocus) {
                return;
            }
            self.registration.showNotification(title, {
                body: body,
                icon: '/favicon.ico',
                badge: '/favicon.ico',
                data: { url: url },
                requireInteraction: false,
            });
        });
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window' }).then(function (clientList) {
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.focus();
                    if ('navigate' in client) {
                        client.navigate(url);
                    }
                    return;
                }
            }
            return clients.openWindow(url);
        })
    );
});
