<script>window.APP_NAME = @json(config('app.name'));</script>
<script>
// Browser notifications + soft chime, driven by Filament's bell-badge.
// Verbose console logging is intentional while we stabilise the audio +
// permission flow — every step prefixes [Notif] so it's easy to grep.
(function () {
    'use strict';

    if (!('Notification' in window)) {
        console.warn('[Notif] Notifications not supported in this browser');
        return;
    }

    // Permission requested on first user gesture (browsers reject silent prompts).
    if (Notification.permission === 'default') {
        document.addEventListener('click', function req() {
            Notification.requestPermission().then(function (perm) {
                console.log('[Notif] permission result:', perm);
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

    // ── Audio context — primed on first user gesture ──────────────────────
    // Web Audio refuses to start sound without a user gesture (browser
    // autoplay policy). Build the context lazily and resume() it from the
    // first click so subsequent chimes from setInterval / livewire:update
    // play even though they don't originate from a click.
    let _audioCtx = null;

    function getAudioContext() {
        if (!_audioCtx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            _audioCtx = new Ctx();
        }
        if (_audioCtx.state === 'suspended') {
            _audioCtx.resume().catch(function (e) {
                console.warn('[Notif] resume() failed:', e);
            });
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
            if (!ctx) {
                console.warn('[Notif] AudioContext unavailable');
                return;
            }
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
            console.error('[Notif] Chime error:', e);
        }
    }

    function showDesktopNotification(count) {
        console.log('[Notif] desktop notification requested, permission:', Notification.permission);

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
                console.log('[Notif] desktop notification shown!');
            } catch (e) {
                console.error('[Notif] desktop notification error:', e);
            }
        } else if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                console.log('[Notif] permission result (lazy):', perm);
                if (perm === 'granted') showDesktopNotification(count);
            });
        } else {
            console.warn('[Notif] desktop notification denied — user blocked');
        }
    }

    // ── Badge readers ─────────────────────────────────────────────────────
    // Try Alpine.js store first; fall back to DOM selectors. The selector
    // list covers Filament 3 markup, the older [x-text=] binding, and the
    // generic data-attribute used by some custom badges.
    function getUnreadCount() {
        try {
            if (window.Alpine && typeof window.Alpine.store === 'function') {
                const store = window.Alpine.store('notifications');
                if (store && typeof store.unreadCount !== 'undefined') {
                    return parseInt(store.unreadCount, 10) || 0;
                }
            }
        } catch (e) {
            // Alpine may not be initialized yet on first reads.
        }

        const selectors = [
            '[x-text="unreadNotificationsCount"]',
            '.fi-notification-badge',
            '[data-unread-notifications-count]',
        ];
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el) {
                return parseInt((el.textContent || '').trim(), 10) || 0;
            }
        }
        return null; // bell not in DOM yet
    }

    let _prevNotifCount = -1;

    function checkNotificationBadge() {
        const current = getUnreadCount();
        console.log('[Notif] check — current:', current, 'prev:', _prevNotifCount);

        if (current === null) return; // bell not mounted

        if (_prevNotifCount === -1) {
            _prevNotifCount = current;
            return;
        }

        if (current > _prevNotifCount) {
            console.log('[Notif] NEW NOTIFICATION! delta=' + (current - _prevNotifCount));
            playChime();
            showDesktopNotification(current - _prevNotifCount);
        }
        _prevNotifCount = current;
    }

    // Triple-source badge polling — any of these will bump the watcher.
    setInterval(checkNotificationBadge, 5000);

    document.addEventListener('livewire:update', function () {
        setTimeout(checkNotificationBadge, 200);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(checkNotificationBadge, 1500);
    });
})();
</script>
