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

    // Livewire fires 'livewire:update' after every server round-trip, including
    // the polling tick that bumps the bell-badge. The 100ms timeout gives Alpine
    // a moment to write the new count into the DOM before we read it.
    document.addEventListener('livewire:update', function () {
        setTimeout(function () {
            const badge = document.querySelector('.fi-notification-badge');
            if (!badge) return;

            const current = parseInt((badge.textContent || '').trim(), 10) || 0;
            const stored  = sessionStorage.getItem('ariza_notif_count');
            const prev    = stored === null ? -1 : parseInt(stored, 10);

            // First reading after a fresh tab/login: prime the storage and
            // bail without sounding — we don't know the user's baseline yet.
            if (prev === -1) {
                sessionStorage.setItem('ariza_notif_count', String(current));
                return;
            }

            if (current > prev) {
                playChime();
                showDesktopNotification(current - prev);
            }
            sessionStorage.setItem('ariza_notif_count', String(current));
        }, 100);
    });
})();
</script>
