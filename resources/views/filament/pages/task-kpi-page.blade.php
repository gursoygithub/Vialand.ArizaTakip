<x-filament-panels::page>
    @php
        $kpi = $this->getKpiData();
        $perf = $kpi['performance'];
    @endphp

    <x-filament::card>
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </x-filament::card>

    {{--
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <x-filament::card class="p-6 border-b-4 border-gray-400">
            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">{{ __('ui.total_tasks') }}</p>
            <p class="text-3xl font-black mt-1">{{ $perf['total'] }}</p>
        </x-filament::card>

        <x-filament::card class="p-6 border-b-4 border-warning-500">
            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">{{ __('ui.pending') }}</p>
            <p class="text-3xl font-black mt-1 text-warning-600">{{ $perf['pending'] }}</p>
        </x-filament::card>

        <x-filament::card class="p-6 border-b-4 border-sky-500">
            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">Kış Bakım</p>
            <p class="text-3xl font-black mt-1 text-sky-600">{{ $perf['winter'] }}</p>
        </x-filament::card>

        <x-filament::card class="p-6 border-b-4 border-success-500">
            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">Bitirme Oranı</p>
            <p class="text-3xl font-black mt-1 text-success-600">%{{ $perf['completion_rate'] }}</p>
        </x-filament::card>

        <x-filament::card class="p-6 border-b-4 border-danger-500">
            <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">Ort. Arıza Ömrü</p>
            <p class="text-3xl font-black mt-1 text-danger-600">
                {{ $perf['avg_days'] }}
                <span class="text-sm">Gün</span>
            </p>
            <p class="text-[10px] text-gray-400 mt-2 italic">* Arıza anından kapanışa kadar geçen süre.</p>
        </x-filament::card>
    </div>
    --}}

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Durum / Öncelik Dağılımı - Justified Versiyon --}}
        <x-filament::card class="lg:col-span-3"> {{-- Genişliği istersen 3 yapıp en üste alabilirsin, ya da 1'de bırakabilirsin --}}
            <h3 class="text-lg font-bold mb-6 flex items-center gap-2">
                <x-heroicon-o-chart-pie class="w-5 h-5 text-gray-400"/>
                Durum / Öncelik Dağılımı
            </h3>

            {{-- justify-between ve flex-wrap ile kartları yan yana yayıyoruz --}}
            <div class="flex flex-wrap gap-4 items-stretch justify-start">
                @foreach($kpi['status_priority'] as $status => $items)
                    @php $statusEnum = \App\Enums\TaskStatusEnum::{$status}; @endphp

                    {{-- flex-1 yaparak her bir durum kutusunun eşit alan kaplamasını ve yayılmasını sağladık --}}
                    <div class="flex-1 min-w-[250px] p-4 rounded-xl bg-gray-50 border border-gray-100 flex flex-col justify-between">
                        <div class="flex items-center gap-2 mb-4">
                            <x-dynamic-component :component="$statusEnum->getIcon()" class="w-5 h-5 text-{{ $statusEnum->getColor() }}-600" />
                            <span class="font-bold text-base text-gray-700">{{ $statusEnum->getLabel() }}</span>
                        </div>

                        {{-- Öncelik rozetlerini de kendi içinde yayıyoruz --}}
                        <div class="flex flex-wrap gap-2">
                            @foreach($items as $item)
                                @php $priority = $item['priority']; @endphp
                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-bold bg-white border border-{{ $priority->getColor() }}-200 text-{{ $priority->getColor() }}-700 shadow-sm">
                            {{ $priority->getLabel() }}: {{ $item['count'] }}
                        </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::card>

        <x-filament::card class="lg:col-span-2">
            <h3 class="text-lg font-bold mb-6 flex items-center gap-2">
                <x-heroicon-o-chart-bar class="w-5 h-5 text-gray-400"/>
                Kapatma Trendi
            </h3>

            @php
                $dailyData = collect($kpi['daily'])->filter(fn($d) => $d['count'] > 0);
                $maxVal = $dailyData->max('count') ?: 1;
            @endphp

            {{-- 1. ADIM: overflow-x-auto ile kaydırma çubuğunu aktif ettik --}}
            <div class="w-full bg-gray-50 rounded-xl p-6 border border-gray-100 overflow-x-auto scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent">
                @if($dailyData->isEmpty())
                    <div class="h-48 flex items-center justify-center text-gray-400 italic">Veri bulunamadı.</div>
                @else
                    {{-- 2. ADIM: min-w-max vererek çubukların daralmasını engelledik, gap-6 ile aralarını açtık --}}
                    <div class="flex items-end justify-start h-64 px-2 gap-6 min-w-max pb-4">
                        @foreach($dailyData as $d)
                            @php $height = ($d['count'] / $maxVal) * 100; @endphp

                            {{-- flex-none diyerek w-10 genişliğini garantiye aldık --}}
                            <div class="flex flex-col items-center group flex-none w-10">

                                {{-- Değer Baloncuğu --}}
                                <span class="text-[10px] font-bold text-primary-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            {{ $d['count'] }}
                        </span>

                                {{-- Sevdiğin o dolgun çubuk yapısı --}}
                                <div class="w-full bg-primary-600 rounded-t-sm transition-all duration-300 group-hover:bg-primary-500 shadow-sm relative flex items-start justify-center"
                                     style="height: {{ max(($height * 1.5), 15) }}px;">
                                    <div class="text-[9px] text-white font-bold mt-1 leading-none">{{ $d['count'] }}</div>
                                </div>

                                {{-- Tarih --}}
                                <div class="mt-2 text-[10px] font-bold text-gray-500 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($d['date'])->translatedFormat('d M') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-filament::card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-filament::card>
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <x-heroicon-o-user-group class="w-5 h-5 text-primary-500"/>
                Atanan Personel
            </h3>
            <div class="space-y-3">
                @forelse($kpi['by_employee'] as $i => $emp)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100">
                        <span class="font-bold text-gray-700 text-sm">#{{ $i+1 }} {{ $emp['name'] }}</span>
                        <span class="px-3 py-1 bg-primary-50 text-primary-700 rounded-full font-black text-[10px]">{{ $emp['count'] }} GÖREV</span>
                    </div>
                @empty
                    <p class="text-center py-4 text-gray-400 italic">Veri bulunamadı.</p>
                @endforelse
            </div>
        </x-filament::card>

        <x-filament::card>
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <x-heroicon-o-check-badge class="w-5 h-5 text-success-500"/>
                Kapatan Personel
            </h3>
            <div class="space-y-3">
                @forelse($kpi['by_closer'] as $i => $closer)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100">
                        <span class="font-bold text-gray-700 text-sm">#{{ $i+1 }} {{ $closer['name'] }}</span>
                        <span class="px-3 py-1 bg-success-50 text-success-700 rounded-full font-black text-[10px]">{{ $closer['count'] }} ONAY</span>
                    </div>
                @empty
                    <p class="text-center py-4 text-gray-400 italic">Veri bulunamadı.</p>
                @endforelse
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>