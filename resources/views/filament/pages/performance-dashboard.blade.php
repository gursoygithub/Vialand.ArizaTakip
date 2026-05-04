<x-filament-panels::page>
    {{-- Outer container: section spacing is now driven by <hr> separators
         between each major block, so space-y-* on the wrapper would
         double-stack with my-6 on the rules. --}}
    <div>

        {{-- Filters --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <form wire:submit.prevent="loadStats" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ui.date_from') }}</label>
                    <input type="date" wire:model="dateFrom"
                           class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ui.date_to') }}</label>
                    <input type="date" wire:model="dateTo"
                           class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ui.area') }}</label>
                    <select wire:model="areaId"
                            class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white">
                        <option value="">{{ __('ui.all') }}</option>
                        @foreach(\App\Models\Area::orderBy('name')->get() as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg px-4 py-2 text-sm transition">
                        Filtrele
                    </button>
                    <button type="button" wire:click="exportCsv" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg px-4 py-2 text-sm transition">
                        {{ __('ui.export_csv') }}
                    </button>
                </div>
            </form>
        </div>

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- 10 summary cards — top-row icon tile + value, label below in
             uppercase. Optional subtitle for reopen/risk cards. Palette
             keys map to fully-spelled Tailwind classes so JIT picks them
             up reliably. --}}
        @if(!empty($overview))
        @php
            $palette = [
                'blue'    => ['bg' => 'bg-blue-50 dark:bg-blue-900/30',       'fg' => 'text-blue-500 dark:text-blue-400'],
                'indigo'  => ['bg' => 'bg-indigo-50 dark:bg-indigo-900/30',   'fg' => 'text-indigo-500 dark:text-indigo-400'],
                'amber'   => ['bg' => 'bg-amber-50 dark:bg-amber-900/30',     'fg' => 'text-amber-500 dark:text-amber-400'],
                'red'     => ['bg' => 'bg-red-50 dark:bg-red-900/30',         'fg' => 'text-red-500 dark:text-red-400'],
                'emerald' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/30', 'fg' => 'text-emerald-500 dark:text-emerald-400'],
                'green'   => ['bg' => 'bg-green-50 dark:bg-green-900/30',     'fg' => 'text-green-500 dark:text-green-400'],
                'orange'  => ['bg' => 'bg-orange-50 dark:bg-orange-900/30',   'fg' => 'text-orange-500 dark:text-orange-400'],
                'violet'  => ['bg' => 'bg-violet-50 dark:bg-violet-900/30',   'fg' => 'text-violet-500 dark:text-violet-400'],
                'sky'     => ['bg' => 'bg-sky-50 dark:bg-sky-900/30',         'fg' => 'text-sky-500 dark:text-sky-400'],
            ];

            $compliance      = $overview['sla_compliance_rate'];
            $complianceColor = $compliance >= 80 ? 'green' : ($compliance >= 50 ? 'orange' : 'red');

            // Reopen: green <5%, orange <15%, red ≥15%
            $reopenRate  = $overview['reopen_rate'] ?? 0;
            $reopenColor = $reopenRate < 5 ? 'green' : ($reopenRate < 15 ? 'orange' : 'red');

            // At-risk: green = 0, orange ≤ 5, red > 5
            $atRisk      = $overview['at_risk'] ?? 0;
            $atRiskColor = $atRisk === 0 ? 'green' : ($atRisk > 5 ? 'red' : 'orange');

            // [label, value, heroicon, color key, subtitle?]
            $cards = [
                [__('ui.total_tickets'),    $overview['total_assigned'],                    'heroicon-o-inbox',                 'blue',           null],
                [__('ui.open_tickets'),     $overview['currently_open'],                    'heroicon-o-folder-open',           'indigo',         null],
                ['Beklemede',               $overview['currently_on_hold'],                 'heroicon-o-pause-circle',          'amber',          null],
                ['Toplam İhlal',            $overview['total_breached'],                    'heroicon-o-exclamation-triangle',  'red',            null],
                ['Zamanında Kapanan',       $overview['closed_on_time'],                    'heroicon-o-check-circle',          'emerald',        null],
                [__('ui.compliance_rate'),  $compliance . '%',                              'heroicon-o-shield-check',          $complianceColor, null],
                ['Ort. Çözüm',              $overview['avg_resolution_minutes'] . ' dk',    'heroicon-o-clock',                 'violet',         null],
                ['Ort. Yanıt',              $overview['avg_response_time_minutes'] . ' dk', 'heroicon-o-bolt',                  'sky',            null],
                ['Yeniden Açılma',          $reopenRate . '%',                              'heroicon-o-arrow-path',            'orange',         ($overview['reopen_count'] ?? 0) . ' adet'],
                ['Risk Altında',            $atRisk,                                        'heroicon-o-exclamation-circle',    $atRiskColor,     '≤2sa içinde SLA'],
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-8">
            @foreach($cards as [$label, $value, $icon, $color, $subtitle])
            @php $p = $palette[$color]; @endphp
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center {{ $p['bg'] }}">
                        @svg($icon, 'w-5 h-5 ' . $p['fg'])
                    </div>
                    <span class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $value }}</span>
                </div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ $label }}</p>
                @if($subtitle)
                    <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- Priority breakdown — fully standalone cards (border-2 + bg
             tint + matching divider). Compliance bar at the bottom mirrors
             the rate; per-priority palette keyed off enum value:
             Düşük (1)→blue, Orta (2)→amber, Yüksek (3)→orange, Acil (4)→red. --}}
        @if(!empty($overview['priority_breakdown'] ?? []))
        @php
            $priorityPalette = [
                1 => ['border' => 'border-blue-200 dark:border-blue-800',     'bg' => 'bg-blue-50/30 dark:bg-blue-900/10',     'text' => 'text-blue-600 dark:text-blue-400',     'divider' => 'border-blue-200 dark:border-blue-800'],
                2 => ['border' => 'border-amber-200 dark:border-amber-800',   'bg' => 'bg-amber-50/30 dark:bg-amber-900/10',   'text' => 'text-amber-600 dark:text-amber-400',   'divider' => 'border-amber-200 dark:border-amber-800'],
                3 => ['border' => 'border-orange-200 dark:border-orange-800', 'bg' => 'bg-orange-50/30 dark:bg-orange-900/10', 'text' => 'text-orange-600 dark:text-orange-400', 'divider' => 'border-orange-200 dark:border-orange-800'],
                4 => ['border' => 'border-red-200 dark:border-red-800',       'bg' => 'bg-red-50/30 dark:bg-red-900/10',       'text' => 'text-red-600 dark:text-red-400',       'divider' => 'border-red-200 dark:border-red-800'],
            ];
        @endphp
        <div>
            <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3 mb-3">Öncelik Dağılımı</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
                @foreach($overview['priority_breakdown'] as $row)
                    @php
                        $pp     = $priorityPalette[$row['priority']->value] ?? $priorityPalette[2];
                        $rate   = $row['compliance_rate'];
                        $rateText = $rate >= 80 ? 'text-emerald-600 dark:text-emerald-400'
                            : ($rate >= 50 ? 'text-orange-600 dark:text-orange-400' : 'text-red-600 dark:text-red-400');
                        $barFill  = $rate >= 80 ? 'bg-emerald-500'
                            : ($rate >= 50 ? 'bg-orange-500' : 'bg-red-500');
                    @endphp
                    <div class="rounded-2xl border-2 p-5 flex flex-col gap-4 {{ $pp['border'] }} {{ $pp['bg'] }}">
                        {{-- Header: priority label + total --}}
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold uppercase tracking-wider {{ $pp['text'] }}">{{ $row['label'] }}</span>
                            <span class="text-3xl font-black text-gray-800 dark:text-gray-100">{{ $row['total'] }}</span>
                        </div>

                        {{-- Divider matching priority hue --}}
                        <div class="border-t {{ $pp['divider'] }}"></div>

                        {{-- Stats row — horizontal three-up --}}
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div>
                                <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ $row['closed_on_time'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Zamanında</p>
                            </div>
                            <div>
                                <p class="text-lg font-bold text-red-500 dark:text-red-400">{{ $row['breached'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">İhlal</p>
                            </div>
                            <div>
                                <p class="text-lg font-bold {{ $rateText }}">{{ $rate }}%</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Uyum</p>
                            </div>
                        </div>

                        {{-- Compliance progress bar (full width) --}}
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full {{ $barFill }}" style="width: {{ $rate }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- SLA Compliance Trend Chart (last 30 days, fixed window — does
             not respect this page's date filter; rendered as-is). --}}
        @livewire(\App\Filament\Widgets\SlaComplianceTrendChart::class)

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- Region breakdown — colored compliance pill on each row plus a
             thin progress bar in a colspan'd row directly below. divide-y
             is dropped so the data row and its progress bar visually
             belong together; explicit border-t is added between groups
             via $loop->first. --}}
        @if($regionBreakdown->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3">Bölge Dağılımı</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.area') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.total_tickets') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Kapanan</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.on_time') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">İhlal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.compliance_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($regionBreakdown->sortByDesc('total') as $row)
                            @php
                                $rc = $row['compliance'];
                                $pillClass = $rc >= 80 ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                    : ($rc >= 50 ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'
                                        : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300');
                                $barClass = $rc >= 80 ? 'bg-green-500' : ($rc >= 50 ? 'bg-orange-500' : 'bg-red-500');
                            @endphp
                            <tr class="{{ !$loop->first ? 'border-t border-gray-200 dark:border-gray-700' : '' }} hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['area_name'] }}</td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total'] }}</td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['closed'] }}</td>
                                <td class="px-4 py-3 text-center text-green-600">{{ $row['on_time'] }}</td>
                                <td class="px-4 py-3 text-center text-red-500">{{ $row['breached'] }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $pillClass }}">{{ $rc }}%</span>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="px-4 pb-3 pt-0">
                                    <div class="bg-gray-100 dark:bg-gray-700 rounded-full h-1 overflow-hidden">
                                        <div class="h-1 rounded-full {{ $barClass }}" style="width:{{ $rc }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- Per-person table — avatar initial before name, 3-tier compliance
             pill, breach count rendered as red badge when >0 / dim gray when 0. --}}
        @if($teamStats->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3">{{ __('ui.technician_performance') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.name') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.total_tickets') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Açık</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Beklemede</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.on_time') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">İhlal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Uyum</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Çözüm dk</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Yanıt dk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($teamStats->sortByDesc('sla_compliance_rate') as $row)
                            @php
                                $name        = $row['user']->name ?? '?';
                                $initial     = mb_strtoupper(mb_substr($name, 0, 1));
                                $r           = $row['sla_compliance_rate'];
                                $compPill    = $r >= 80 ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                    : ($r >= 50 ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'
                                        : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300');
                                $breachCount = $row['closed_breached'];
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300 flex items-center justify-center text-sm font-semibold flex-shrink-0">
                                            {{ $initial }}
                                        </div>
                                        <span class="truncate">{{ $name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total_assigned'] }}</td>
                                <td class="px-4 py-3 text-center text-blue-600">{{ $row['currently_open'] }}</td>
                                <td class="px-4 py-3 text-center text-orange-600">{{ $row['currently_on_hold'] }}</td>
                                <td class="px-4 py-3 text-center text-green-600">{{ $row['closed_on_time'] }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($breachCount > 0)
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $breachCount }}</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500 text-sm">{{ $breachCount }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $compPill }}">{{ $r }}%</span>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['avg_resolution_minutes'] }}</td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['avg_response_time_minutes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

    </div>
</x-filament-panels::page>

@push('styles')
<style>
.stat-card { @apply bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center; }
</style>
@endpush
