{{--
    SLA usage bar under the SLA section. Inline styles on purpose: Filament's
    prebuilt CSS does not scan this partial.
--}}
@php
    $colors = ['success' => '#16a34a', 'warning' => '#d97706', 'danger' => '#dc2626', 'gray' => '#9ca3af'];
    $fill = $colors[$tone] ?? $colors['gray'];
    $width = max(2, min(100, $percent));
@endphp
<div style="display:flex;flex-direction:column;gap:6px;">
    <div style="height:10px;border-radius:9999px;background:rgba(148,163,184,.25);overflow:hidden;" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
        <div style="height:100%;width:{{ $width }}%;background:{{ $fill }};border-radius:9999px;transition:width .3s;"></div>
    </div>
    <div style="font-size:.8rem;opacity:.75;">
        {{ $paused ? __('ui.sla_used_paused', ['percent' => $percent]) : __('ui.sla_used', ['percent' => $percent]) }}
    </div>
</div>
