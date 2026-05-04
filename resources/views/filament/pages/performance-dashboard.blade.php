<x-filament-panels::page>
    {{-- Tailwind safelist: every per-color utility used by the dynamic
         summary card icons (compliance / reopen / at-risk pick their
         color at render time, so JIT may not see all variants in source
         scans). Keeping every literal class string in the source ensures
         they're emitted in the build. Hidden from the page. --}}
    <div class="hidden
        bg-blue-50 text-blue-600 bg-blue-900/30 text-blue-400
        bg-indigo-50 text-indigo-600 bg-indigo-900/30 text-indigo-400
        bg-amber-50 text-amber-600 bg-amber-900/30 text-amber-400
        bg-red-50 text-red-600 bg-red-900/30 text-red-400
        bg-emerald-50 text-emerald-600 bg-emerald-900/30 text-emerald-400
        bg-violet-50 text-violet-600 bg-violet-900/30 text-violet-400
        bg-sky-50 text-sky-600 bg-sky-900/30 text-sky-400
        bg-orange-50 text-orange-600 bg-orange-900/30 text-orange-400
    "></div>

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

        {{-- 10 summary cards — icon tile on top, value + label below.
             Color resolves at render time for compliance / risk; safelist
             at the top of this file holds every variant so JIT picks
             them up. --}}
        @if(!empty($overview))
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mt-6 mb-8">

            @foreach([
                ['label' => 'Toplam Talep',       'value' => $overview['total_assigned'],          'icon' => 'inbox',              'color' => 'blue'],
                ['label' => 'Açık Talepler',      'value' => $overview['currently_open'],          'icon' => 'folder-open',        'color' => 'indigo'],
                ['label' => 'Beklemede',          'value' => $overview['currently_on_hold'],       'icon' => 'pause-circle',       'color' => 'amber'],
                ['label' => 'Toplam İhlal',       'value' => $overview['total_breached'],          'icon' => 'exclamation-triangle','color' => 'red'],
                ['label' => 'Zamanında Kapanan',  'value' => $overview['closed_on_time'],          'icon' => 'check-circle',       'color' => 'emerald'],
                ['label' => 'Uyum Oranı',         'value' => $overview['sla_compliance_rate'].'%','icon' => 'shield-check',       'color' => $overview['sla_compliance_rate'] >= 80 ? 'emerald' : ($overview['sla_compliance_rate'] >= 50 ? 'amber' : 'red')],
                ['label' => 'Ort. Çözüm',         'value' => $overview['avg_resolution_minutes'].' dk','icon' => 'clock',           'color' => 'violet'],
                ['label' => 'Ort. Yanıt',         'value' => $overview['avg_response_time_minutes'].' dk','icon' => 'bolt',          'color' => 'sky'],
                ['label' => 'Yeniden Açılma',     'value' => $overview['reopen_rate'].'%',         'icon' => 'arrow-path',         'color' => 'orange', 'sub' => $overview['reopen_count'].' adet'],
                ['label' => 'Risk Altında',       'value' => $overview['at_risk'],                 'icon' => 'exclamation-circle', 'color' => $overview['at_risk'] === 0 ? 'emerald' : ($overview['at_risk'] <= 5 ? 'amber' : 'red'), 'sub' => '≤2sa içinde SLA'],
            ] as $card)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm p-4 flex flex-col gap-3 hover:shadow-md transition-shadow">

                    <div class="flex items-center justify-between">
                        <div class="w-10 h-10 rounded-lg bg-{{ $card['color'] }}-50 dark:bg-{{ $card['color'] }}-900/30 flex items-center justify-center">
                            <x-filament::icon
                                :icon="'heroicon-o-' . $card['icon']"
                                class="w-5 h-5 text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400"
                            />
                        </div>
                    </div>

                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-tight">
                            {{ $card['value'] }}
                        </p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">
                            {{ $card['label'] }}
                        </p>
                        @if(isset($card['sub']))
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                {{ $card['sub'] }}
                            </p>
                        @endif
                    </div>

                </div>
            @endforeach

        </div>
        @endif

        <hr class="border-t border-gray-100 dark:border-gray-700 my-6">

        {{-- Priority breakdown — single card with one horizontal row per
             priority. Each row: priority badge + compliance bar + 3-up
             stats. Palettes spelled out in full so JIT keeps every
             variant. --}}
        @if(!empty($overview['priority_breakdown'] ?? []))
        @php
            $priorityBadge = [
                1 => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                2 => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                3 => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
                4 => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
            ];
        @endphp
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 mt-8">
            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-400 mb-6">
                Öncelik Dağılımı
            </h3>

            @foreach($overview['priority_breakdown'] as $row)
                @php
                    $badge = $priorityBadge[$row['priority']->value] ?? $priorityBadge[2];
                    $rate  = $row['compliance_rate'];
                    $barFill  = $rate >= 80 ? 'bg-emerald-500'
                        : ($rate >= 50 ? 'bg-amber-400' : 'bg-red-400');
                    $rateText = $rate >= 80 ? 'text-emerald-600 dark:text-emerald-400'
                        : ($rate >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-500 dark:text-red-400');
                @endphp
                <div class="flex items-center gap-4 py-3 border-b border-gray-50 dark:border-gray-700 last:border-0">
                    {{-- Priority badge (fixed-width column so bars line up) --}}
                    <div class="w-20 shrink-0">
                        <span class="inline-block text-xs font-bold px-2 py-1 rounded-full {{ $badge }}">
                            {{ $row['label'] }}
                        </span>
                    </div>

                    {{-- Progress bar + percentage --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $barFill }}" style="width: {{ min($rate, 100) }}%"></div>
                            </div>
                            <span class="text-xs font-bold w-10 text-right {{ $rateText }}">{{ $rate }}%</span>
                        </div>
                    </div>

                    {{-- Stats: Toplam / Zamanında / İhlal --}}
                    <div class="flex gap-6 shrink-0 text-center">
                        <div>
                            <p class="text-sm font-bold text-gray-700 dark:text-gray-200">{{ $row['total'] }}</p>
                            <p class="text-xs text-gray-400">Toplam</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-emerald-500">{{ $row['closed_on_time'] }}</p>
                            <p class="text-xs text-gray-400">Zamanında</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold {{ $row['breached'] > 0 ? 'text-red-500' : 'text-gray-400' }}">
                                {{ $row['breached'] }}
                            </p>
                            <p class="text-xs text-gray-400">İhlal</p>
                        </div>
                    </div>
                </div>
            @endforeach
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
