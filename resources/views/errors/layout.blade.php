{{--
    Shared shell of the error pages. Standalone on purpose (no Vite, no
    panel): it must render even when the app itself is failing.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #475569; --line: rgba(148,163,184,.35); --accent: #2563eb; --accent-text: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --card: #111827; --text: #f1f5f9; --muted: #94a3b8; --line: rgba(148,163,184,.25); --accent: #3b82f6; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 32px; text-align: center; }
        .code { font-size: .85rem; font-weight: 600; letter-spacing: .08em; color: var(--muted); }
        .icon { font-size: 44px; line-height: 1; margin: 12px 0; }
        h1 { font-size: 1.35rem; margin: 8px 0 12px; }
        p { color: var(--muted); line-height: 1.6; margin: 0 0 8px; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 24px; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; border: 1px solid var(--line); color: var(--text); background: transparent; cursor: pointer; font-size: .95rem; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">{{ __('ui.error_code', ['code' => $code]) }}</div>
        <div class="icon" aria-hidden="true">{{ $icon }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @isset($hint)
            <p>{{ $hint }}</p>
        @endisset
        <div class="actions">
            <a class="btn" href="javascript:history.back()">{{ __('ui.error_go_back') }}</a>
            @if ($code === 419)
                <a class="btn btn-primary" href="{{ url()->current() }}">{{ __('ui.error_reload') }}</a>
            @else
                <a class="btn btn-primary" href="{{ url('/') }}">{{ __('ui.error_go_home') }}</a>
            @endif
        </div>
    </main>
</body>
</html>
