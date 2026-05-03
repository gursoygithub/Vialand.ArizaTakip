// SW Version: 2 — BroadcastChannel dedup + visibilityState guard.
// Bump this comment whenever you change SW behavior; browsers cache the
// previous SW aggressively and only re-fetch / re-install when the byte
// content of this file changes. A version comment is the cheapest way
// to force that.
//
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

// Page-visibility tracking via BroadcastChannel. The page posts its
// document.hidden state on visibilitychange (and once at load); the SW
// uses the latest value to decide whether to show the OS notification.
// This is more reliable than client.focused — Chrome treats unfocused
// (but visible) tabs as "focused: true" sometimes and "false" other times.
let pageVisible = false;
let _channel = null;
try {
    _channel = new BroadcastChannel('fcm-channel');
    _channel.onmessage = function (e) {
        if (e.data && e.data.type === 'visibility') {
            pageVisible = !e.data.hidden;
        }
    };
} catch (_) {
    // Older browsers / private mode may not support BroadcastChannel —
    // fall back to client.focused below.
}

messaging.onBackgroundMessage(function (payload) {
    const title = (payload.data && payload.data.title)
        || (payload.notification && payload.notification.title)
        || 'Arıza Takip';
    const body = (payload.data && payload.data.body)
        || (payload.notification && payload.notification.body)
        || '';
    const url = (payload.data && payload.data.url) || '/';

    // Multi-layer dedup. Any one signal that the page is alive in this
    // browser suppresses the OS popup so the user never sees both the
    // Filament toast AND a duplicate native notification:
    //
    //   1) pageVisible — set via the BroadcastChannel handshake from
    //      fcm-init.blade.php on visibilitychange / pageshow.
    //   2) clients.matchAll — at least one window scoped to this SW
    //      reports visibilityState === 'visible'.
    //   3) clients.focused — fallback when the browser doesn't expose
    //      visibilityState on the Client interface (some Chromium builds).
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function (clientList) {
            const anyVisible = clientList.some(function (c) {
                return c.visibilityState === 'visible' || c.focused;
            });
            if (pageVisible || anyVisible) {
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
