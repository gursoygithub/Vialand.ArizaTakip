{{--
    Team performance report. Inline styles for layout/colour on purpose:
    Filament's prebuilt CSS does not scan custom page views.
--}}
@php
    $s = $report['summary'];
    $pct = fn ($v) => $v === null ? '—' : '%'.number_format($v, 1, ',', '.');
    $tone = fn ($v) => $v === null ? '#6b7280' : ($v >= 90 ? '#15803d' : ($v >= 75 ? '#b45309' : '#b91c1c'));
    $cell = 'padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.25);text-align:left;white-space:nowrap;';
    $head = $cell.'font-size:.75rem;letter-spacing:.03em;text-transform:none;opacity:.7;font-weight:600;';
    $num = 'padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.25);text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;';
@endphp

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">
        @foreach ([
            [__('ui.report_compliance'), $pct($s['compliance']), $tone($s['compliance']), __('ui.report_compliance_hint', ['on' => $s['on_time'], 'total' => $s['total']])],
            [__('ui.report_breached'), $s['breached'], $s['breached'] > 0 ? '#b91c1c' : '#15803d', __('ui.report_breach_split', ['person' => $s['personal_breaches'], 'handover' => $s['handover_breaches'], 'dispatch' => $s['dispatch_delays']])],
            [__('ui.report_response_breached'), $s['response_breached'], $s['response_breached'] > 0 ? '#b45309' : '#15803d', __('ui.report_response_hint')],
            [__('ui.report_reopen_rate'), $pct($s['reopen_rate']), '#374151', __('ui.report_reopen_hint', ['count' => $s['reopened'], 'rejections' => $s['rejections']])],
            [__('ui.report_satisfaction'), $s['satisfaction'] === null ? '—' : number_format($s['satisfaction'], 2, ',', '.').' / 5', '#374151', __('ui.report_rated', ['count' => $s['rated']])],
            [__('ui.report_exempted'), $s['exempted'], '#6f42c1', __('ui.report_cancelled_after_breach', ['count' => $s['cancelled_after_breach']])],
        ] as [$label, $value, $color, $hint])
            <x-filament::section>
                <div style="font-size:.8rem;opacity:.7;">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:700;color:{{ $color }};font-variant-numeric:tabular-nums;">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.65;margin-top:2px;">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('ui.teams')" icon="heroicon-o-user-group">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead><tr>
                    <th style="{{ $head }}">{{ __('ui.team') }}</th>
                    <th style="{{ $head }}">{{ __('ui.ticket_type') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_sealed') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_compliance') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_response_breached') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.breach_dispatch') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.breach_handover') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_avg_resolution') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_reopen_rate') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_satisfaction') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($report['teams'] as $row)
                    <tr>
                        <td style="{{ $cell }}font-weight:600;">{{ $row['name'] }}</td>
                        <td style="{{ $cell }}">{{ $row['type'] }}</td>
                        <td style="{{ $num }}">{{ $row['total'] }}</td>
                        <td style="{{ $num }}font-weight:700;color:{{ $tone($row['compliance']) }};">{{ $pct($row['compliance']) }}</td>
                        <td style="{{ $num }}">{{ $row['response_breached'] }}</td>
                        <td style="{{ $num }}">{{ $row['dispatch_delays'] }}</td>
                        <td style="{{ $num }}">{{ $row['handover_breaches'] }}</td>
                        <td style="{{ $num }}">{{ $minutes($row['avg_resolution_minutes']) }}</td>
                        <td style="{{ $num }}">{{ $pct($row['reopen_rate']) }}</td>
                        <td style="{{ $num }}">{{ $row['satisfaction'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" style="{{ $cell }}opacity:.7;">{{ __('ui.report_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('ui.report_people')" :description="__('ui.report_people_hint')" icon="heroicon-o-users">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead><tr>
                    <th style="{{ $head }}">{{ __('ui.name') }}</th>
                    <th style="{{ $head }}">{{ __('ui.company') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_sealed') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_personal_breaches') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_compliance') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_avg_resolution') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_reopened') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_rejections') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('ui.report_satisfaction') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($report['people'] as $row)
                    <tr>
                        <td style="{{ $cell }}font-weight:600;">{{ $row['name'] }}</td>
                        <td style="{{ $cell }}">{{ $row['company'] ?? '—' }}</td>
                        <td style="{{ $num }}">{{ $row['total'] }}</td>
                        <td style="{{ $num }}">{{ $row['personal_breaches'] }}</td>
                        <td style="{{ $num }}font-weight:700;color:{{ $tone($row['compliance']) }};">{{ $pct($row['compliance']) }}</td>
                        <td style="{{ $num }}">{{ $minutes($row['avg_resolution_minutes']) }}</td>
                        <td style="{{ $num }}">{{ $row['reopened'] }}</td>
                        <td style="{{ $num }}">{{ $row['rejections'] }}</td>
                        <td style="{{ $num }}">{{ $row['satisfaction'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="{{ $cell }}opacity:.7;">{{ __('ui.report_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
        @foreach ([[__('ui.ticket_types'), $report['types'], 'total'], [__('ui.report_companies'), $report['companies'], 'total']] as [$title, $rows])
            <x-filament::section :heading="$title">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                    <thead><tr>
                        <th style="{{ $head }}">{{ __('ui.name') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('ui.report_sealed') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('ui.report_compliance') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td style="{{ $cell }}">{{ $row['name'] }}</td>
                            <td style="{{ $num }}">{{ $row['total'] }}</td>
                            <td style="{{ $num }}font-weight:700;color:{{ $tone($row['compliance']) }};">{{ $pct($row['compliance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="{{ $cell }}opacity:.7;">{{ __('ui.report_empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endforeach
    </div>

    <p style="font-size:.75rem;opacity:.65;">{{ __('ui.report_rules') }}</p>
</x-filament-panels::page>
