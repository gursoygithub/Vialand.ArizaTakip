<x-filament-panels::page>
    @php
        $kpi = $this->getKpiData();
        $counts = $kpi['daily']->pluck('count')->toArray();
        $max = !empty($counts) ? max($counts) : 0;
        $displayMax = $max > 0 ? $max : 1;
    @endphp

    {{-- 1. Filtre --}}
    <x-filament::card>
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </x-filament::card>

    {{-- 2. KPI Kartları --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <x-filament::card class="flex items-center gap-4 p-6">
            <x-heroicon-o-list-bullet class="w-8 h-8 text-gray-400"/>
            <div>
                <p class="text-sm text-gray-500">{{ __('ui.total_tasks') }}</p>
                <p class="text-2xl font-bold">{{ $kpi['performance']['total'] }}</p>
            </div>
        </x-filament::card>

        <x-filament::card class="flex items-center gap-4 p-6">
            <x-heroicon-o-check-circle class="w-8 h-8 text-success-600"/>
            <div>
                <p class="text-sm text-gray-500">{{ __('ui.completed') }}</p>
                <p class="text-2xl font-bold">{{ $kpi['performance']['completed'] }}</p>
            </div>
        </x-filament::card>

        <x-filament::card class="flex items-center gap-4 p-6">
            <x-heroicon-o-clock class="w-8 h-8 text-warning-600"/>
            <div>
                <p class="text-sm text-gray-500">{{ __('ui.pending') }}</p>
                <p class="text-2xl font-bold">{{ $kpi['performance']['pending'] }}</p>
            </div>
        </x-filament::card>

        <x-filament::card class="flex items-center gap-4 p-6">
            <x-heroicon-o-lifebuoy class="w-8 h-8 text-primary-600"/>
            <div>
                <p class="text-sm text-gray-500">{{ __('ui.winter_maintenance') }}</p>
                <p class="text-2xl font-bold">{{ $kpi['performance']['winter'] }}</p>
            </div>
        </x-filament::card>


    </div>

    {{-- 3. Durum / Öncelik --}}
    <x-filament::card>
        <h3 class="text-lg font-bold mb-6">Durum / Öncelik Dağılımı</h3><br>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($kpi['status_priority'] as $status => $items)
                @php
                    $statusEnum = \App\Enums\TaskStatusEnum::{$status};
                @endphp

                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <x-dynamic-component
                                :component="$statusEnum->getIcon()"
                                class="w-5 h-5 text-{{ $statusEnum->getColor() }}-600"
                        />
                        <span class="font-semibold text-base">
                            {{ $statusEnum->getLabel() }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        @foreach($items as $item)
                            @php $priority = $item['priority']; @endphp

                            <span
                                    class="
                                    inline-flex items-center gap-2
                                    px-4 py-2
                                    rounded-xl
                                    text-sm font-bold
                                    bg-{{ $priority->getColor() }}-100
                                    text-{{ $priority->getColor() }}-700
                                    ring-1 ring-inset ring-{{ $priority->getColor() }}-300
                                ">
                                <x-dynamic-component
                                        :component="$priority->getIcon()"
                                        class="w-4 h-4"
                                />

                                {{ $priority->getLabel() }}

                                <span
                                        class="
                                        ml-1 px-2 py-0.5
                                        rounded-full
                                        text-xs font-bold
                                        bg-white/70
                                        text-{{ $priority->getColor() }}-800
                                    "
                                >
                                    {{ $item['count'] }}
                                </span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::card>

    {{-- 4. Alt Bölüm --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Günlük Trend --}}
        <x-filament::card class="lg:col-span-2">
            <h3 class="text-lg font-bold mb-4">Günlük Kapatılan Görev Trendi</h3>

            <div class="flex gap-4">
                {{-- Sol ölçek --}}
                <div class="flex flex-col justify-between h-[200px] text-xs text-gray-500">
                    @foreach([1, .75, .5, .25, 0] as $r)
                        <div>{{ round($displayMax * $r) }}</div>
                    @endforeach
                </div>

                {{-- Grafik --}}
                <div class="flex-1 overflow-x-auto">
                    @php
                        $barCount = count($kpi['daily']);
                        $needsScroll = $barCount > 14; // kritik eşik
                    @endphp

                    <div
                            class="flex items-end gap-2 h-[200px] w-full"
                            @if($needsScroll)
                                style="min-width: {{ $barCount * 40 }}px;"
                            @endif
                    >
                        @foreach($kpi['daily'] as $d)
                            @php
                                $height = ($d['count'] / $displayMax) * 160;
                            @endphp

                            <div class="flex-1 flex flex-col items-center">
                                <div
                                        class="w-full bg-primary-600 rounded-t transition-all"
                                        style="height: {{ max($height, 4) }}px"
                                ></div>

                                <div class="text-[10px] mt-1 text-gray-500">
                                    {{ \Carbon\Carbon::parse($d['date'])->format('d/m') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

        </x-filament::card>

        {{-- Lider --}}
        <x-filament::card>
            <h3 class="text-lg font-bold mb-4">{{__('ui.closers')}}</h3>

            <div class="space-y-3">
                @forelse($kpi['by_closer'] as $i => $closer)
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center font-bold">
                            {{ $i + 1 }}
                        </span>
                        <div>
                            <div class="font-semibold">{{ $closer['name'] }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $closer['count'] }} görev
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-gray-500">Veri bulunamadı.</p>
                @endforelse
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>
