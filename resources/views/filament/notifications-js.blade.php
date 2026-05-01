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
    // Filament removes the badge element when unreadCount === 0, so DOM
    // selectors return null both for "actually zero" and "bell not mounted".
    // Read the source of truth instead: Alpine $data on the bell button,
    // then the Livewire component's data, then 0 as a safe baseline.
    function getUnreadCount() {
        // 1. Alpine.$data on the bell wrapper. The bell's x-data block has
        //    unreadNotificationsCount as a reactive property — querying it
        //    works whether or not the badge is currently rendered.
        try {
            const bellBtn = document.querySelector(
                '[x-data*="unreadNotificationsCount"],[x-data*="notifications"]'
            );
            if (bellBtn && window.Alpine && typeof window.Alpine.$data === 'function') {
                const alpineData = window.Alpine.$data(bellBtn);
                if (alpineData && typeof alpineData.unreadNotificationsCount !== 'undefined') {
                    console.log('[Notif] Alpine count:', alpineData.unreadNotificationsCount);
                    return parseInt(alpineData.unreadNotificationsCount, 10) || 0;
                }
            }
        } catch (e) {
            console.log('[Notif] Alpine error:', e.message);
        }

        // 2. Livewire component's public state. Filament 3 stores the
        //    unread count on the DatabaseNotifications Livewire component.
        try {
            if (window.Livewire && typeof window.Livewire.all === 'function') {
                const components = window.Livewire.all();
                for (const comp of components) {
                    // Log component keys once so we can see what's available
                    // when debugging — only on the first iteration to avoid
                    // spam on every poll.
                    if (comp === components[0]) {
                        console.log('[Notif] Livewire component keys:', Object.keys(comp.data ?? {}));
                    }
                    let val;
                    try { val = comp.get('unreadNotificationsCount'); } catch (_) { val = undefined; }
                    if (typeof val !== 'undefined') {
                        console.log('[Notif] Livewire count:', val);
                        return parseInt(val, 10) || 0;
                    }
                }
            }
        } catch (e) {
            console.log('[Notif] Livewire error:', e.message);
        }

        // 3. Final fallback: 0 (not null). Returning null here would let the
        //    watcher reset _prevNotifCount mid-session and miss real
        //    increments. 0 is the safest baseline when both APIs are missing.
        return 0;
    }

    let _prevNotifCount = -1;

    function checkNotificationBadge() {
        const current = getUnreadCount(); // always a number now, never null
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

    // Triple-source badge polling — any of these will bump the watcher.
    setInterval(checkNotificationBadge, 5000);

    document.addEventListener('livewire:update', function () {
        setTimeout(checkNotificationBadge, 200);
    });

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(checkNotificationBadge, 1500);
    });

    // ── One-shot debug: find the leaf element whose text is exactly "5"
    // (the user's known unread count) and dump its tag/id/class plus its
    // parent's class. Then dump the first 2KB of header HTML so we can
    // see the surrounding markup. Runs 2s after script load.
    function debugFindNotifCount() {
        console.log('=== NOTIF DEBUG ===');

        const all = document.querySelectorAll('*');
        for (const el of all) {
            if (el.children.length === 0
                && (el.textContent || '').trim() === '5') {
                console.log('FOUND "5" in:',
                    el.tagName,
                    el.id,
                    el.className,
                    'parent:', el.parentElement ? el.parentElement.className : '(no parent)'
                );
            }
        }

        const header = document.querySelector('header');
        if (header) {
            console.log('HEADER HTML:', (header.innerHTML || '').substring(0, 2000));
        }

        console.log('=== END DEBUG ===');
    }

    setTimeout(debugFindNotifCount, 2000);
})();
</script>
