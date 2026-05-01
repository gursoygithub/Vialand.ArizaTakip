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
    // Field findings (Livewire 3 in this build):
    //  - Livewire.all() returns [], so iterating registered components fails.
    //  - Each Livewire root has wire:id (regenerated per page load).
    //  - The bell panel exposes wire:click="markAllNotificationsAsRead";
    //    walking up from there hits an Alpine $data carrying
    //    unreadNotificationsCount even when the badge dot is hidden.
    //
    // Strategy: scan every [wire:id] for a component whose Livewire state
    // has unreadNotificationsCount; if none, walk up from the
    // markAllNotificationsAsRead button to find the Alpine wrapper that
    // holds the count.
    function getUnreadCount() {
        // 1. Iterate every Livewire-rooted element and ask the component
        //    for unreadNotificationsCount via Livewire.find(id).
        try {
            const els = document.querySelectorAll('[wire\\:id]');
            for (const el of els) {
                const id = el.getAttribute('wire:id');

                try {
                    const comp = window.Livewire ? window.Livewire.find(id) : null;
                    if (comp) {
                        const count = comp.get('unreadNotificationsCount');
                        if (typeof count !== 'undefined' && count !== null) {
                            console.log('[Notif] Found count in Livewire component', id, ':', count);
                            return parseInt(count, 10) || 0;
                        }
                    }
                } catch (_) {
                    // Component might not expose .get() yet; fall through.
                }

                // Same element via Alpine — Filament wraps each Livewire
                // root in an [x-data] for client-side reactivity.
                try {
                    if (window.Alpine && typeof window.Alpine.$data === 'function') {
                        const data = window.Alpine.$data(el);
                        if (data && typeof data.unreadNotificationsCount !== 'undefined') {
                            console.log('[Notif] Found count in Alpine', id, ':', data.unreadNotificationsCount);
                            return parseInt(data.unreadNotificationsCount, 10) || 0;
                        }
                    }
                } catch (_) {
                    // Alpine may not be attached to this root.
                }
            }
        } catch (e) {
            console.log('[Notif] Component scan error:', e.message);
        }

        // 2. Walk up from the markAllNotificationsAsRead button (always
        //    rendered inside the open panel + the closed dropdown) to find
        //    an Alpine wrapper carrying unreadNotificationsCount. Bounded
        //    to 10 ancestor hops so this can't spin away from the bell.
        try {
            const markBtn = document.querySelector('[wire\\:click="markAllNotificationsAsRead"]');
            if (markBtn) {
                let el = markBtn;
                for (let i = 0; i < 10 && el; i++) {
                    el = el.parentElement;
                    if (!el) break;
                    try {
                        if (window.Alpine && typeof window.Alpine.$data === 'function') {
                            const data = window.Alpine.$data(el);
                            if (data && typeof data.unreadNotificationsCount !== 'undefined') {
                                console.log('[Notif] Found via bell-parent walk:', data.unreadNotificationsCount);
                                return parseInt(data.unreadNotificationsCount, 10) || 0;
                            }
                        }
                    } catch (_) {}
                }
            }
        } catch (e) {
            console.log('[Notif] Bell-parent walk error:', e.message);
        }

        // 3. Last-resort baseline. 0 (not null) so the watcher can establish
        //    a stable prev count; the next successful read will compare
        //    against it correctly.
        console.log('[Notif] All methods failed, returning 0');
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

    // ── One-shot debug: find the bell button specifically (the "5"
    // we found earlier was the nav-item badge for Arıza Talepleri,
    // not the bell). Filament's bell button carries wire:click with
    // "Notification" or aria-label with "notification" / "bildirim".
    // Also dumps every Livewire-rooted element so we can match the
    // bell's component id against Livewire.all() entries.
    function debugFindNotifCount() {
        console.log('=== NOTIF DEBUG ===');

        document.querySelectorAll('button').forEach(function (btn, i) {
            const wire = btn.getAttribute('wire:click') || '';
            const aria = btn.getAttribute('aria-label') || '';
            if (wire.toLowerCase().includes('otification')
                || aria.toLowerCase().includes('otification')
                || aria.toLowerCase().includes('ildirim')) {
                console.log('BELL BUTTON:', (btn.outerHTML || '').substring(0, 500));
            }
        });

        document.querySelectorAll('[wire\\:id]').forEach(function (el) {
            console.log('Livewire component:',
                el.getAttribute('wire:id'),
                (el.className || '').toString().substring(0, 100));
        });

        console.log('=== END DEBUG ===');
    }

    setTimeout(debugFindNotifCount, 2000);
})();
</script>
