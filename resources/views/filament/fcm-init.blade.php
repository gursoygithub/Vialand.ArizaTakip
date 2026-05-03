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

        // BroadcastChannel handshake with the service worker so the SW
        // knows whether to show an OS notification. The SW skips when
        // pageVisible === true; the page handles foreground rendering.
        let _fcmChannel = null;
        try {
            _fcmChannel = new BroadcastChannel('fcm-channel');
            const postVisibility = function () {
                try {
                    _fcmChannel.postMessage({
                        type: 'visibility',
                        hidden: document.hidden,
                    });
                } catch (_) {}
            };
            document.addEventListener('visibilitychange', postVisibility);
            // Prime once at load so the SW has a value before the first push.
            postVisibility();
            // Re-prime when the page is shown after coming back from bfcache.
            window.addEventListener('pageshow', postVisibility);
        } catch (_) {
            // Older browsers — SW will fall back to clients.focused checks.
        }

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
            const url = (payload.data && payload.data.url) || '/tickets';

            console.log('[FCM] Foreground message:', title);

            playChime();

            // Filament 3 toast — uses the global FilamentNotification /
            // FilamentNotificationAction constructors exposed by the panel
            // plugin. The .duration(8000) keeps the toast on screen long
            // enough to read; the .button() flag makes the action a
            // primary button instead of a link.
            try {
                new FilamentNotification()
                    .title(title)
                    .body(body)
                    .warning()
                    .duration(8000)
                    .actions([
                        new FilamentNotificationAction('view')
                            .label('Talebi Görüntüle')
                            .url(url)
                            .button(),
                    ])
                    .send();
            } catch (e) {
                console.error('[FCM] Toast error:', e);
            }
        });

        initFcm();
    }
</script>
