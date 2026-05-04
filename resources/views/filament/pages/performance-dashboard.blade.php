<x-filament-panels::page>
    <div class="space-y-6">

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

        {{-- 10 summary cards --}}
        @if(!empty($overview))
        @php
            $colorMap = [
                'gray'   => 'text-gray-900 dark:text-gray-100',
                'blue'   => 'text-blue-600',
                'orange' => 'text-orange-500',
                'red'    => 'text-red-500',
                'green'  => 'text-green-600',
            ];

            // Reopen rate: green <5%, orange <15%, red ≥15%
            $reopenRate  = $overview['reopen_rate'] ?? 0;
            $reopenColor = $reopenRate < 5 ? 'green' : ($reopenRate < 15 ? 'orange' : 'red');

            // At-risk: green = 0, orange > 0, red > 5
            $atRisk      = $overview['at_risk'] ?? 0;
            $atRiskColor = $atRisk === 0 ? 'green' : ($atRisk > 5 ? 'red' : 'orange');

            $cards = [
                [__('ui.total_tickets'),  $overview['total_assigned'],                  'gray',   null],
                [__('ui.open_tickets'),   $overview['currently_open'],                  'blue',   null],
                ['Beklemede',             $overview['currently_on_hold'],               'orange', null],
                ['Toplam İhlal',          $overview['total_breached'],                  'red',    null],
                ['Zamanında Kapanan',     $overview['closed_on_time'],                  'green',  null],
                [__('ui.compliance_rate'), $overview['sla_compliance_rate'].'%',
                    $overview['sla_compliance_rate'] >= 80 ? 'green' : 'orange', null],
                ['Ort. Çözüm',            $overview['avg_resolution_minutes'].' dk',    'gray',   null],
                ['Ort. Yanıt',            $overview['avg_response_time_minutes'].' dk', 'gray',   null],
                ['Yeniden Açılma',        $reopenRate.'%',                              $reopenColor, ($overview['reopen_count'] ?? 0).' adet'],
                ['Risk Altında',          $atRisk,                                      $atRiskColor, '≤2sa içinde SLA'],
            ];
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach($cards as [$label, $value, $color, $subtitle])
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center">
                    <p class="text-2xl font-bold {{ $colorMap[$color] ?? $colorMap['gray'] }}">{{ $value }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ $label }}</p>
                    @if($subtitle)
                        <p class="text-xs text-gray-400 mt-0.5">{{ $subtitle }}</p>
                    @endif
                </div>
            @endforeach
        </div>
        @endif

        {{-- Priority breakdown --}}
        @if(!empty($overview['priority_breakdown'] ?? []))
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">Öncelik Dağılımı</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Öncelik</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Toplam</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Zamanında</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">İhlal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">Uyum %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($overview['priority_breakdown'] as $row)
                        @php
                            $r = $row['compliance_rate'];
                            $pillClass = $r >= 80
                                ? 'bg-green-100 text-green-700'
                                : ($r >= 50 ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700');
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                            <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total'] }}</td>
                            <td class="px-4 py-3 text-center text-green-600">{{ $row['closed_on_time'] }}</td>
                            <td class="px-4 py-3 text-center text-red-500">{{ $row['breached'] }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $pillClass }}">{{ $r }}%</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- SLA Compliance Trend Chart (last 30 days, fixed window — does
             not respect this page's date filter; rendered as-is). --}}
        @livewire(\App\Filament\Widgets\SlaComplianceTrendChart::class)

        {{-- Region breakdown --}}
        @if($regionBreakdown->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">Bölge Dağılımı</h3>
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
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($regionBreakdown->sortByDesc('total') as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['area_name'] }}</td>
                            <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total'] }}</td>
                            <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['closed'] }}</td>
                            <td class="px-4 py-3 text-center text-green-600">{{ $row['on_time'] }}</td>
                            <td class="px-4 py-3 text-center text-red-500">{{ $row['breached'] }}</td>
                            <td class="px-4 py-3 text-center">
                                <div class="w-24 mx-auto">
                                    <div class="bg-gray-200 dark:bg-gray-600 rounded-full h-2 overflow-hidden">
                                        <div class="h-2 rounded-full {{ $row['compliance'] >= 80 ? 'bg-green-500' : 'bg-orange-500' }}" style="width:{{ $row['compliance'] }}%"></div>
                                    </div>
                                    <div class="text-xs mt-1 {{ $row['compliance'] >= 80 ? 'text-green-700' : 'text-orange-700' }}">{{ $row['compliance'] }}%</div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Per-person table --}}
        @if($teamStats->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('ui.technician_performance') }}</h3>
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
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['user']->name }}</td>
                            <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total_assigned'] }}</td>
                            <td class="px-4 py-3 text-center text-blue-600">{{ $row['currently_open'] }}</td>
                            <td class="px-4 py-3 text-center text-orange-600">{{ $row['currently_on_hold'] }}</td>
                            <td class="px-4 py-3 text-center text-green-600">{{ $row['closed_on_time'] }}</td>
                            <td class="px-4 py-3 text-center text-red-500">{{ $row['closed_breached'] }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $row['sla_compliance_rate'] >= 80 ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' }}">
                                    {{ $row['sla_compliance_rate'] }}%
                                </span>
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
