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

        {{-- 10 summary cards — 2×5 grid (mobile 2 / md 3 / lg 5).
             Each card: colored left border, icon tile in soft tint, then
             value + label (+ optional subtitle). All Tailwind classes are
             spelled out per palette so JIT picks them up reliably. --}}
        @if(!empty($overview))
        @php
            // Per-color class bundle. Adding a new color = add a new entry here.
            $palette = [
                'blue'   => ['border' => 'border-blue-500',   'bg' => 'bg-blue-50 dark:bg-blue-900/30',     'fg' => 'text-blue-600 dark:text-blue-400'],
                'indigo' => ['border' => 'border-indigo-500', 'bg' => 'bg-indigo-50 dark:bg-indigo-900/30', 'fg' => 'text-indigo-600 dark:text-indigo-400'],
                'yellow' => ['border' => 'border-yellow-500', 'bg' => 'bg-yellow-50 dark:bg-yellow-900/30', 'fg' => 'text-yellow-600 dark:text-yellow-400'],
                'red'    => ['border' => 'border-red-500',    'bg' => 'bg-red-50 dark:bg-red-900/30',       'fg' => 'text-red-600 dark:text-red-400'],
                'green'  => ['border' => 'border-green-500',  'bg' => 'bg-green-50 dark:bg-green-900/30',   'fg' => 'text-green-600 dark:text-green-400'],
                'orange' => ['border' => 'border-orange-500', 'bg' => 'bg-orange-50 dark:bg-orange-900/30', 'fg' => 'text-orange-600 dark:text-orange-400'],
                'purple' => ['border' => 'border-purple-500', 'bg' => 'bg-purple-50 dark:bg-purple-900/30', 'fg' => 'text-purple-600 dark:text-purple-400'],
            ];

            $compliance      = $overview['sla_compliance_rate'];
            $complianceColor = $compliance >= 80 ? 'green' : 'orange';

            // Reopen rate: green <5%, orange <15%, red ≥15%.
            $reopenRate  = $overview['reopen_rate'] ?? 0;
            $reopenColor = $reopenRate < 5 ? 'green' : ($reopenRate < 15 ? 'orange' : 'red');

            // At-risk: green = 0, orange > 0, red > 5.
            $atRisk      = $overview['at_risk'] ?? 0;
            $atRiskColor = $atRisk === 0 ? 'green' : ($atRisk > 5 ? 'red' : 'orange');

            // Each row: [label, value, icon name (heroicon-o-*), color key, subtitle?]
            $cards = [
                [__('ui.total_tickets'),    $overview['total_assigned'],                    'heroicon-o-ticket',                'blue',           null],
                [__('ui.open_tickets'),     $overview['currently_open'],                    'heroicon-o-folder-open',           'indigo',         null],
                ['Beklemede',               $overview['currently_on_hold'],                 'heroicon-o-pause-circle',          'yellow',         null],
                ['Toplam İhlal',            $overview['total_breached'],                    'heroicon-o-exclamation-circle',    'red',            null],
                ['Zamanında Kapanan',       $overview['closed_on_time'],                    'heroicon-o-check-circle',          'green',          null],
                [__('ui.compliance_rate'),  $compliance . '%',                              'heroicon-o-shield-check',          $complianceColor, null],
                ['Ort. Çözüm',              $overview['avg_resolution_minutes'] . ' dk',    'heroicon-o-clock',                 'purple',         null],
                ['Ort. Yanıt',              $overview['avg_response_time_minutes'] . ' dk', 'heroicon-o-bolt',                  'purple',         null],
                ['Yeniden Açılma',          $reopenRate . '%',                              'heroicon-o-arrow-path',            'orange',         ($overview['reopen_count'] ?? 0) . ' adet'],
                ['Risk Altında',            $atRisk,                                        'heroicon-o-exclamation-triangle',  $atRiskColor,     '≤2sa içinde SLA'],
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            @foreach($cards as [$label, $value, $icon, $color, $subtitle])
            @php $p = $palette[$color]; @endphp
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border-l-4 {{ $p['border'] }} px-4 py-3 flex items-center gap-3">
                <div class="{{ $p['bg'] }} rounded-full w-11 h-11 flex items-center justify-center flex-shrink-0">
                    @svg($icon, 'w-6 h-6 ' . $p['fg'])
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-tight truncate">{{ $value }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $label }}</p>
                    @if($subtitle)
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- Priority breakdown — one card per priority. Card tint, border,
             and badge colors are keyed off the priority enum value (Low=1
             /blue, Medium=2/yellow, High=3/orange, Urgent=4/red). --}}
        @if(!empty($overview['priority_breakdown'] ?? []))
        @php
            $priorityPalette = [
                1 => ['card' => 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800',     'badge' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
                2 => ['card' => 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800', 'badge' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'],
                3 => ['card' => 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800', 'badge' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'],
                4 => ['card' => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',         'badge' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
            ];
        @endphp
        <div>
            <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3 mb-3">Öncelik Dağılımı</h3>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($overview['priority_breakdown'] as $row)
                    @php
                        $pp = $priorityPalette[$row['priority']->value] ?? $priorityPalette[2];
                        $r  = $row['compliance_rate'];
                        $rateClass = $r >= 80 ? 'text-green-600 dark:text-green-400'
                            : ($r >= 50 ? 'text-orange-600 dark:text-orange-400' : 'text-red-600 dark:text-red-400');
                    @endphp
                    <div class="rounded-xl shadow-sm p-4 border {{ $pp['card'] }}">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold uppercase tracking-wide rounded-full px-2 py-0.5 {{ $pp['badge'] }}">{{ $row['label'] }}</span>
                        </div>
                        <div class="text-3xl font-bold text-gray-900 dark:text-gray-100 leading-tight">{{ $row['total'] }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">Toplam</div>
                        <div class="grid grid-cols-3 gap-2 text-xs pt-3 border-t border-gray-200/60 dark:border-gray-700/60">
                            <div class="text-center">
                                <div class="font-semibold text-green-600 dark:text-green-400">{{ $row['closed_on_time'] }}</div>
                                <div class="text-gray-500 dark:text-gray-400 mt-0.5">Zamanında</div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold text-red-600 dark:text-red-400">{{ $row['breached'] }}</div>
                                <div class="text-gray-500 dark:text-gray-400 mt-0.5">İhlal</div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold {{ $rateClass }}">{{ $r }}%</div>
                                <div class="text-gray-500 dark:text-gray-400 mt-0.5">Uyum</div>
                            </div>
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
