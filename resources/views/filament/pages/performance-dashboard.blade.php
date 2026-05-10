<x-filament-panels::page>
    {{-- Tailwind safelist: every per-color utility used by the dynamic
         summary card icons (compliance / reopen / at-risk pick their
         color at render time, so JIT may not see all variants in source
         scans). Keeping every literal class string in the source ensures
         they're emitted in the build. Hidden from the page. --}}
    <div class="hidden
        bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400
        bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400
        bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400
        bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400
        bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400
        bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-400
        bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400
        bg-orange-50 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400
    "></div>

    {{-- Outer container: section spacing uses inline style="margin-top:
         2rem;" on each major section wrapper EXCEPT the first (filter
         form). Inline styles win the cascade and can't be overridden
         by Filament's panel CSS or any utility class — earlier attempts
         with mt-8 (and before that space-y-8) were occasionally lost
         to higher-specificity rules from the panel layout. --}}
    <div>

        {{-- Filters --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <div class="flex flex-wrap gap-2 mb-5">
                <button type="button" wire:click="setDateRange('this_week')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Bu Hafta
                </button>
                <button type="button" wire:click="setDateRange('this_month')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Bu Ay
                </button>
                <button type="button" wire:click="setDateRange('last_month')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Geçen Ay
                </button>
                <button type="button" wire:click="setDateRange('last_30_days')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Son 30 Gün
                </button>
                <button type="button" wire:click="setDateRange('this_quarter')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Bu Çeyrek
                </button>
                <button type="button" wire:click="setDateRange('this_year')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60 transition border border-sky-200 dark:border-sky-800">
                    Bu Yıl
                </button>
            </div>
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
                        @foreach($this->getVisibleAreas() as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg px-4 py-2 text-sm transition">
                        {{ __('ui.page_performance_filter_submit') }}
                    </button>
                    <button type="button" wire:click="exportCsv" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg px-4 py-2 text-sm transition">
                        {{ __('ui.export_csv') }}
                    </button>
                </div>
            </form>
        </div>

        {{-- KPI summary cards — compact grid. Icon tile on the left,
             value + label (+ optional subtitle) on the right. Per-card
             color resolves at render time; every variant is in the
             safelist at the top of this file. --}}
        @if(!empty($overview))
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4" style="margin-top: 2rem;">
            @foreach([
                ['label'=>__('ui.page_performance_kpi_compliance'),'value'=>'%'.number_format($overview['sla_compliance_rate'],1,',','.'),'icon'=>'shield-check','color'=>$overview['sla_compliance_rate']>=80?'emerald':($overview['sla_compliance_rate']>=50?'amber':'red')],
                ['label'=>__('ui.page_performance_kpi_breached'),'value'=>$overview['total_breached'],'icon'=>'exclamation-triangle','color'=>'red'],
                ['label'=>__('ui.page_performance_kpi_reopen'),'value'=>'%'.number_format($overview['reopen_rate'],1,',','.'),'sub'=>$overview['reopen_count'].' '.__('ui.page_performance_kpi_count_unit'),'icon'=>'arrow-path','color'=>'orange'],
                ['label'=>__('ui.page_performance_kpi_on_time'),'value'=>$overview['closed_on_time'],'icon'=>'check-circle','color'=>'emerald'],
                ['label'=>__('ui.page_performance_kpi_avg_resolution'),'value'=>\App\Support\DurationFormatter::minutes((int) $overview['avg_resolution_minutes']),'icon'=>'clock','color'=>'violet'],
                ['label'=>__('ui.page_performance_kpi_avg_response'),'value'=>\App\Support\DurationFormatter::minutes((int) $overview['avg_response_time_minutes']),'icon'=>'bolt','color'=>'sky'],
                ['label'=>__('ui.total_tickets'),'value'=>$overview['total_assigned'],'icon'=>'inbox','color'=>'blue'],
                ['label'=>__('ui.page_performance_kpi_at_risk'),'value'=>$overview['at_risk'],'icon'=>'fire','color'=>$overview['at_risk']===0?'emerald':($overview['at_risk']<=5?'orange':'red')],
            ] as $card)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm p-3 hover:shadow-md transition">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 shrink-0 rounded-lg bg-{{ $card['color'] }}-50 dark:bg-{{ $card['color'] }}-900/30 flex items-center justify-center">
                            <x-filament::icon
                                :icon="'heroicon-o-' . $card['icon']"
                                class="w-4 h-4 text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-lg font-bold text-gray-900 dark:text-gray-100 leading-none">{{ $card['value'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate">{{ $card['label'] }}</p>
                            @if(isset($card['sub']))
                                <p class="text-[10px] text-gray-400 mt-0.5 truncate">{{ $card['sub'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif

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
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6" style="margin-top: 2rem;">
            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-400 mb-6">
                {{ __('ui.page_performance_priority_heading') }}
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
                            <span class="text-xs font-bold w-10 text-right {{ $rateText }}">%{{ number_format($rate, 1, ',', '.') }}</span>
                        </div>
                    </div>

                    {{-- Stats: Toplam / Zamanında / İhlal --}}
                    <div class="flex gap-6 shrink-0 text-center">
                        <div>
                            <p class="text-sm font-bold text-gray-700 dark:text-gray-200">{{ $row['total'] }}</p>
                            <p class="text-xs text-gray-400">{{ __('ui.page_performance_col_total') }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-emerald-500">{{ $row['closed_on_time'] }}</p>
                            <p class="text-xs text-gray-400">{{ __('ui.on_time') }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold {{ $row['breached'] > 0 ? 'text-red-500' : 'text-gray-400' }}">
                                {{ $row['breached'] }}
                            </p>
                            <p class="text-xs text-gray-400">{{ __('ui.page_performance_col_breached') }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Region breakdown — colored compliance pill on each row plus a
             thin progress bar in a colspan'd row directly below. divide-y
             is dropped so the data row and its progress bar visually
             belong together; explicit border-t is added between groups
             via $loop->first. --}}
        @if($regionBreakdown->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden" style="margin-top: 2rem;">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3">{{ __('ui.page_performance_region_heading') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.area') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.total_tickets') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_resolved') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.on_time') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_breached') }}</th>
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
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $pillClass }}">%{{ number_format($rc, 1, ',', '.') }}</span>
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

        {{-- Per-person table — avatar initial before name, 3-tier compliance
             pill, breach count rendered as red badge when >0 / dim gray when 0. --}}
        @if($teamStats->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden" style="margin-top: 2rem;">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white border-l-4 border-primary-500 pl-3">{{ __('ui.technician_performance') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.name') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.total_tickets') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_open') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_on_hold') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.on_time') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_breached') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300">{{ __('ui.page_performance_col_compliance') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300 cursor-help" title="Talep oluşturulduktan sonra atanmasına kadar geçen süre.">Yanıt Süresi</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300 cursor-help" title="Atanma → çözülme arası geçen toplam süre. Beklemede süresi dahil.">Çözüm (Brüt)</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600 dark:text-gray-300 cursor-help" title="Atanma → çözülme arası net çalışma süresi. Beklemede süresi düşülmüştür. SLA hesabı bu süreyi kullanır.">Çözüm (Net)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($teamStats->sortByDesc('sla_compliance_rate') as $row)
                            @php
                                $name        = $row['user']->name ?? '?';
                                $initial     = mb_strtoupper(mb_substr($name, 0, 1));
                                $r           = $row['sla_compliance_rate'];
                                $hi          = $row['employee_threshold'] ?? 80.0;
                                $lo          = $hi * 0.75;
                                $compPill    = $r >= $hi ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                    : ($r >= $lo ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'
                                        : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300');
                                $breachCount = $row['closed_breached'];
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300 flex items-center justify-center text-sm font-semibold flex-shrink-0">
                                            {{ $initial }}
                                        </div>
                                        @if (!empty($row['employee_id']))
                                            <a href="{{ route('filament.dashboard.resources.employees.view', ['record' => $row['employee_id']]) }}"
                                               class="truncate text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $name }}
                                            </a>
                                        @else
                                            <span class="truncate">{{ $name }}</span>
                                        @endif
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
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $compPill }}">%{{ number_format($r, 1, ',', '.') }}</span>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ \App\Support\DurationFormatter::minutes((int) $row['avg_response_time_minutes']) }}</td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ \App\Support\DurationFormatter::minutes((int) $row['avg_resolution_minutes']) }}</td>
                                <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300">{{ \App\Support\DurationFormatter::minutes((int) $row['avg_resolution_active_minutes']) }}</td>
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
