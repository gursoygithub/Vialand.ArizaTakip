<script>window.APP_NAME = @json(config('app.name'));</script>
<script>
// Browser notifications + soft chime, driven by Filament's bell-badge.
// Polling cadence is set on the panel (databaseNotificationsPolling('5s'));
// this script reacts to the badge count changing on Livewire updates.
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

    // Soft two-tone chime via Web Audio API. No sample file dependency.
    function playChime() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            [660, 880].forEach(function (freq, i) {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.value = freq;
                const t = ctx.currentTime + i * 0.15;
                gain.gain.setValueAtTime(0, t);
                gain.gain.linearRampToValueAtTime(0.15, t + 0.05);
                gain.gain.exponentialRampToValueAtTime(0.001, t + 0.6);
                osc.start(t);
                osc.stop(t + 0.6);
            });
        } catch (e) {
            // AudioContext creation can fail before any user gesture; ignore.
        }
    }

    function showDesktopNotification(delta) {
        if (Notification.permission !== 'granted') return;
        const title = (window.APP_NAME || 'Arıza Takip') + ' • Yeni Bildirim';
        const n = new Notification(title, {
            body: delta + ' yeni bildiriminiz var',
            icon: '/favicon.ico',
            tag: 'ariza-takip-notif', // collapses repeated bells into one popup
            requireInteraction: false,
        });
        n.onclick = function () { window.focus(); n.close(); };
        setTimeout(function () { n.close(); }, 5000);
    }

    // Triple-source badge polling:
    //  - setInterval  every 5s in case Livewire isn't actively round-tripping.
    //  - livewire:update  with a small debounce, for the polling tick that
    //    bumps the bell badge.
    //  - DOMContentLoaded  initial read once Filament has hydrated the bell.
    // _prevNotifCount is module-scoped (this IIFE), not sessionStorage —
    // sessionStorage made the first reading after navigation false-positive
    // when the prior tab had a different value.
    let _prevNotifCount = -1;

    function checkNotificationBadge() {
        const badge = document.querySelector('[x-text="unreadNotificationsCount"]')
            || document.querySelector('.fi-notification-badge');
        if (!badge) return;

        const current = parseInt((badge.textContent || '').trim(), 10) || 0;

        // First read primes the baseline silently — we don't sound a chime
        // for the badge's initial render.
        if (_prevNotifCount === -1) {
            _prevNotifCount = current;
            return;
        }

        if (current > _prevNotifCount) {
            playChime();
            showDesktopNotification(current - _prevNotifCount);
        }
        _prevNotifCount = current;
    }

    setInterval(checkNotificationBadge, 5000);

    document.addEventListener('livewire:update', function () {
        setTimeout(checkNotificationBadge, 200);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(checkNotificationBadge, 1500);
    });
})();
</script>
