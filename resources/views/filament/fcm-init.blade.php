<script type="module">
    // Firebase Cloud Messaging — foreground init.
    // Registers /firebase-messaging-sw.js as the background SW, then asks
    // for the device token and POSTs it to /fcm/token. Foreground messages
    // play a soft chime and surface a Filament toast (background ones go
    // through the SW's onBackgroundMessage handler).
    import { initializeApp } from 'https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js';
    import { getMessaging, getToken, onMessage } from 'https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging.js';

    const firebaseConfig = {
        apiKey:            @json(config('services.firebase.api_key')),
        authDomain:        @json(config('services.firebase.auth_domain')),
        projectId:         @json(config('services.firebase.project_id')),
        storageBucket:     @json(config('services.firebase.storage_bucket')),
        messagingSenderId: @json(config('services.firebase.messaging_sender_id')),
        appId:             @json(config('services.firebase.app_id')),
    };
    const vapidKey = @json(config('services.firebase.vapid_key'));

    // Bail without breaking the page if FCM env vars aren't filled in
    // (local dev that doesn't want push). The polling fallback keeps the
    // bell badge updated regardless.
    if (!firebaseConfig.apiKey || !firebaseConfig.projectId || !vapidKey) {
        console.log('[FCM] disabled — missing config');
    } else {
        const app = initializeApp(firebaseConfig);
        const messaging = getMessaging(app);

        // Foreground audio: same two-tone chime the polling system used.
        function playChime() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                if (ctx.state === 'suspended') ctx.resume().catch(function () {});
                [660, 880].forEach(function (freq, i) {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    const t = ctx.currentTime + i * 0.18;
                    gain.gain.setValueAtTime(0, t);
                    gain.gain.linearRampToValueAtTime(0.2, t + 0.05);
                    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.7);
                    osc.start(t);
                    osc.stop(t + 0.7);
                });
            } catch (e) {
                // AudioContext won't start without a prior user gesture; ignore.
            }
        }

        // Foreground toast — uses Filament's Notification API when present,
        // falls back to the native browser Notification if the user has
        // granted permission and the page is hidden.
        function showFilamentToast(title, body, url) {
            try {
                if (window.FilamentNotification && window.FilamentNotificationAction) {
                    window.FilamentNotification.make()
                        .title(title)
                        .body(body)
                        .actions([
                            window.FilamentNotificationAction.make('view')
                                .label('Talebi Görüntüle')
                                .url(url),
                        ])
                        .warning()
                        .send();
                    return;
                }
            } catch (e) {
                // Fall through to native notification.
            }

            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    const n = new Notification(title, { body: body, icon: '/favicon.ico' });
                    n.onclick = function () {
                        window.focus();
                        if (url) window.location.href = url;
                        n.close();
                    };
                    setTimeout(function () { n.close(); }, 5000);
                } catch (_) {}
            }
        }

        async function initFcm() {
            if (!('serviceWorker' in navigator)) {
                console.log('[FCM] service workers unsupported');
                return;
            }

            try {
                const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');

                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    console.log('[FCM] permission denied — push disabled');
                    return;
                }

                const token = await getToken(messaging, {
                    vapidKey: vapidKey,
                    serviceWorkerRegistration: registration,
                });
                if (!token) {
                    console.log('[FCM] no token returned');
                    return;
                }

                const csrf = document.querySelector('meta[name="csrf-token"]');
                await fetch('/fcm/token', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': csrf ? csrf.content : '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ token: token }),
                });

                console.log('[FCM] token registered');
            } catch (e) {
                console.error('[FCM] init error:', e);
            }
        }

        onMessage(messaging, function (payload) {
            // Only handle in foreground — background SW already showed the
            // OS notification, and we don't want to double-surface.
            if (document.visibilityState === 'hidden') return;

            const title = (payload.data && payload.data.title)
                || (payload.notification && payload.notification.title)
                || 'Yeni Bildirim';
            const body = (payload.data && payload.data.body)
                || (payload.notification && payload.notification.body)
                || '';
            const url = (payload.data && payload.data.url) || '/';

            playChime();
            showFilamentToast(title, body, url);
            console.log('[FCM] foreground message:', title);
        });

        initFcm();
    }
</script>
