<script>window.APP_NAME = @json(config('app.name'));</script>
<script>
// Browser notifications + soft chime, polled from a dedicated unread-count
// endpoint. Filament's Livewire components don't expose
// unreadNotificationsCount via window.Livewire.find in this build, so
// reading from JS would require deep DOM/Alpine probing — fetching a
// 4-byte JSON every 5s is simpler and survives panel internals changes.
(function () {
    'use strict';

    if (!('Notification' in window)) {
        return;
    }

    // Permission requested on first user gesture (browsers reject silent prompts).
    if (Notification.permission === 'default') {
        document.addEventListener('click', function req() {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    new Notification(window.APP_NAME, {
                        body: 'Masaüstü bildirimler aktif edildi.',
                        icon: '/favicon.ico',
                    });
                }
            });
            document.removeEventListener('click', req);
        }, { once: true });
    }

    // ── Audio context — primed on first user gesture so subsequent chimes
    // from setInterval / livewire:update aren't blocked by autoplay policy.
    let _audioCtx = null;

    function getAudioContext() {
        if (!_audioCtx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            _audioCtx = new Ctx();
        }
        if (_audioCtx.state === 'suspended') {
            _audioCtx.resume().catch(function () {});
        }
        return _audioCtx;
    }

    document.addEventListener('click', function primeAudio() {
        getAudioContext();
        document.removeEventListener('click', primeAudio);
    }, { once: true });

    function playChime() {
        try {
            const ctx = getAudioContext();
            if (!ctx) return;
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
            console.log('[Notif] Chime played!');
        } catch (e) {
            // Audio context may fail before any user gesture; ignore.
        }
    }

    function showDesktopNotification(count) {
        if (Notification.permission === 'granted') {
            try {
                const n = new Notification((window.APP_NAME || 'Arıza Takip') + ' • Yeni Bildirim', {
                    body: count + ' yeni bildiriminiz var',
                    icon: '/favicon.ico',
                    tag: 'ariza-takip-notif',
                    requireInteraction: false,
                });
                n.onclick = function () { window.focus(); n.close(); };
                setTimeout(function () { n.close(); }, 5000);
                console.log('[Notif] Desktop notification shown!');
            } catch (e) {
                // Notification constructor can throw on iOS Safari etc.; ignore.
            }
        } else if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') showDesktopNotification(count);
            });
        }
    }

    // ── Count source: server-side endpoint ────────────────────────────────
    // 4-byte JSON every 5s; cheaper than Filament's full polling roundtrip
    // and independent of Alpine/Livewire internals.
    async function getUnreadCount() {
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]');
            const res = await fetch('/api/notifications/unread-count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf ? csrf.content : '',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
            if (!res.ok) return 0;
            const data = await res.json();
            return parseInt(data.count, 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    let _prevNotifCount = -1;

    async function checkNotificationBadge() {
        const current = await getUnreadCount();
        console.log('[Notif] check — current:', current, 'prev:', _prevNotifCount);

        if (_prevNotifCount === -1) {
            _prevNotifCount = current;
            return;
        }

        if (current > _prevNotifCount) {
            console.log('[Notif] NEW NOTIFICATION! delta:', current - _prevNotifCount);
            playChime();
            showDesktopNotification(current - _prevNotifCount);
        }
        _prevNotifCount = current;
    }

    // Triple-source watcher — any of these triggers a count refresh.
    setInterval(function () { checkNotificationBadge(); }, 5000);

    document.addEventListener('livewire:update', function () {
        setTimeout(function () { checkNotificationBadge(); }, 200);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () { checkNotificationBadge(); }, 1500);
    });
})();
</script>
