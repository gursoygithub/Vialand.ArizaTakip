<script>
// Browser notifications + soft chime, driven by Filament's bell-badge.
// Polling cadence is set on the panel (databaseNotificationsPolling('5s')),
// this script just reacts to the badge count changing.
(function () {
    'use strict';

    if (!('Notification' in window)) {
        return;
    }

    // Ask once, on the first user gesture (browsers reject silent prompts).
    if (Notification.permission === 'default') {
        document.addEventListener('click', function req() {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    new Notification('Arıza Takip', {
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
            // Audio context creation can fail before any user gesture; ignore.
        }
    }

    function showDesktopNotification(delta) {
        if (Notification.permission !== 'granted') return;
        const n = new Notification('Arıza Takip • Yeni Bildirim', {
            body: delta + ' yeni bildiriminiz var',
            icon: '/favicon.ico',
            tag: 'ariza-takip-notif', // collapses repeated bells into one
            requireInteraction: false,
        });
        n.onclick = function () { window.focus(); n.close(); };
        setTimeout(function () { n.close(); }, 5000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // sessionStorage persists across Livewire navigations within the tab.
        let prevCount = parseInt(sessionStorage.getItem('ariza_notif_count') || '0', 10) || 0;

        function readBadgeCount() {
            const badge = document.querySelector(
                '.fi-notification-badge, [x-text="unreadNotificationsCount"], [data-unread-count]'
            );
            if (!badge) return null;
            const raw = (badge.textContent || '').trim();
            const n = parseInt(raw, 10);
            return Number.isFinite(n) ? n : 0;
        }

        function checkBadge() {
            const current = readBadgeCount();
            if (current === null) return; // bell not in the DOM yet

            if (current > prevCount) {
                playChime();
                showDesktopNotification(current - prevCount);
            }
            prevCount = current;
            sessionStorage.setItem('ariza_notif_count', String(current));
        }

        // Re-check on Livewire navigations and DOM mutations. The MutationObserver
        // covers Filament's polling re-render of the bell badge.
        document.addEventListener('livewire:navigated', checkBadge);

        const observer = new MutationObserver(checkBadge);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            characterDataOldValue: true,
        });

        // Initial sync after the bell hydrates.
        setTimeout(checkBadge, 1000);
    });
})();
</script>
