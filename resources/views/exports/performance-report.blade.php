<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Performans Raporu</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; padding: 20px 30px; }
        .section { margin-top: 18px; page-break-inside: avoid; }
        .section h2 { font-size: 13px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin-bottom: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #f3f4f6; }
        th { background: #f9fafb; font-weight: 600; font-size: 10px; }
        td.center, th.center { text-align: center; }
        .pill-success { color: #047857; font-weight: 600; }
        .pill-warning { color: #b45309; font-weight: 600; }
        .pill-danger  { color: #b91c1c; font-weight: 600; }

        .header { margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #1f2937; }
        .header-logo { margin-bottom: 12px; }
        .header-logo img { height: 48px; width: auto; display: block; }
        .header h1 { font-size: 22px; font-weight: 700; margin: 0 0 6px 0; color: #111827; }
        .header .meta { font-size: 10px; color: #6b7280; margin: 0; line-height: 1.5; }

        .kpi-row { width: 100%; border-bottom: 1px solid #f3f4f6; }
        .kpi-label { width: 60%; padding: 5px 8px; color: #6b7280; font-size: 10px; }
        .kpi-value { width: 40%; padding: 5px 8px; font-weight: 600; text-align: right; }
    </style>
</head>
<body>

{{-- Header --}}
<div class="header">
    @if($logoData ?? false)
        <div class="header-logo">
            <img src="{{ $logoData }}" alt="">
        </div>
    @endif
    <h1>Performans Raporu</h1>
    <p class="meta">
        {{ $dateFrom }} — {{ $dateTo }} &nbsp;|&nbsp; Bölge: {{ $areaName }}<br>
        Oluşturuldu: {{ $generatedAt }} &nbsp;|&nbsp; Hazırlayan: {{ $generatedBy }}
    </p>
</div>

{{-- KPI Summary --}}
@if(!is_null($overview))
<div class="section">
    <h2>KPI Özeti</h2>
    <table>
        <tbody>
            <tr>
                <td>Toplam Talep</td>
                <td class="center">{{ $overview['total_assigned'] }}</td>
            </tr>
            <tr>
                <td>Zamanında Çözülen</td>
                <td class="center">{{ $overview['closed_on_time'] }}</td>
            </tr>
            <tr>
                <td>İhlal Sayısı</td>
                <td class="center {{ $overview['total_breached'] > 0 ? 'pill-danger' : '' }}">{{ $overview['total_breached'] }}</td>
            </tr>
            <tr>
                <td>SLA Uyum Oranı</td>
                @php $r = $overview['sla_compliance_rate']; @endphp
                <td class="center {{ $r >= 80 ? 'pill-success' : ($r >= 50 ? 'pill-warning' : 'pill-danger') }}">
                    %{{ number_format($r, 1, ',', '.') }}
                </td>
            </tr>
            <tr>
                <td>Risk Altındaki Talepler</td>
                <td class="center {{ $overview['at_risk'] > 0 ? 'pill-warning' : '' }}">{{ $overview['at_risk'] }}</td>
            </tr>
            <tr>
                <td>Yeniden Açılma Oranı</td>
                <td class="center">%{{ number_format($overview['reopen_rate'], 1, ',', '.') }} ({{ $overview['reopen_count'] }} adet)</td>
            </tr>
            <tr>
                <td>Ortalama Yanıt Süresi</td>
                <td class="center">{{ \App\Support\DurationFormatter::minutes((int) $overview['avg_response_time_minutes']) }}</td>
            </tr>
            <tr>
                <td>Ortalama Çözüm Süresi (Brüt)</td>
                <td class="center">{{ \App\Support\DurationFormatter::minutes((int) $overview['avg_resolution_minutes']) }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endif

{{-- Priority Breakdown --}}
@if($showPriority && !is_null($overview) && !empty($overview['priority_breakdown'] ?? []))
<div class="section">
    <h2>Öncelik Dağılımı</h2>
    <table>
        <thead>
            <tr>
                <th>ÖNCELİK</th>
                <th class="center">TOPLAM</th>
                <th class="center">ZAMANINDA</th>
                <th class="center">İHLAL</th>
                <th class="center">UYUM %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($overview['priority_breakdown'] as $row)
                @php $rate = $row['compliance_rate']; @endphp
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="center">{{ $row['total'] }}</td>
                    <td class="center">{{ $row['closed_on_time'] }}</td>
                    <td class="center {{ $row['breached'] > 0 ? 'pill-danger' : '' }}">{{ $row['breached'] }}</td>
                    <td class="center {{ $rate >= 80 ? 'pill-success' : ($rate >= 50 ? 'pill-warning' : 'pill-danger') }}">
                        %{{ number_format($rate, 1, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Region Breakdown --}}
@if(!is_null($regionBreakdown) && $regionBreakdown->isNotEmpty())
<div class="section">
    <h2>Bölge Dağılımı</h2>
    <table>
        <thead>
            <tr>
                <th>BÖLGE</th>
                <th class="center">TOPLAM</th>
                <th class="center">ÇÖZÜLEN</th>
                <th class="center">ZAMANINDA</th>
                <th class="center">İHLAL</th>
                <th class="center">UYUM %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($regionBreakdown->sortByDesc('total') as $row)
                @php $rc = $row['compliance']; @endphp
                <tr>
                    <td>{{ $row['area_name'] }}</td>
                    <td class="center">{{ $row['total'] }}</td>
                    <td class="center">{{ $row['closed'] }}</td>
                    <td class="center">{{ $row['on_time'] }}</td>
                    <td class="center {{ $row['breached'] > 0 ? 'pill-danger' : '' }}">{{ $row['breached'] }}</td>
                    <td class="center {{ $rc >= 80 ? 'pill-success' : ($rc >= 50 ? 'pill-warning' : 'pill-danger') }}">
                        %{{ number_format($rc, 1, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Team Stats --}}
@if(!is_null($teamStats) && $teamStats->isNotEmpty())
<div class="section">
    <h2>Teknisyen Performansı</h2>
    <table>
        <thead>
            <tr>
                <th>TEKNİSYEN</th>
                <th class="center">TOPLAM</th>
                <th class="center">ZAMANINDA</th>
                <th class="center">İHLAL</th>
                <th class="center">UYUM %</th>
                <th class="center">YANIT SÜRESİ</th>
                <th class="center">ÇÖZÜM (NET)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($teamStats->sortByDesc('sla_compliance_rate') as $row)
                @php
                    $r  = $row['sla_compliance_rate'];
                    $hi = $row['employee_threshold'] ?? 80.0;
                    $lo = $hi * 0.75;
                    $cls = $r >= $hi ? 'pill-success' : ($r >= $lo ? 'pill-warning' : 'pill-danger');
                @endphp
                <tr>
                    <td>{{ $row['user']->name ?? '?' }}</td>
                    <td class="center">{{ $row['total_assigned'] }}</td>
                    <td class="center">{{ $row['closed_on_time'] }}</td>
                    <td class="center {{ $row['closed_breached'] > 0 ? 'pill-danger' : '' }}">{{ $row['closed_breached'] }}</td>
                    <td class="center {{ $cls }}">%{{ number_format($r, 1, ',', '.') }}</td>
                    <td class="center">{{ \App\Support\DurationFormatter::minutes((int) $row['avg_response_time_minutes']) }}</td>
                    <td class="center">{{ \App\Support\DurationFormatter::minutes((int) $row['avg_resolution_active_minutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

</body>
</html>
