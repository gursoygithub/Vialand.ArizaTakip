@php
    /** @var array $areas */
    $areas = $areas ?? [];
@endphp

@if (empty($areas))
    <div class="text-sm text-gray-500">Bu şirket için henüz bölge tanımlı değil. Yukarıdaki "Yeni Bölge Ekle" butonu ile başlayın.</div>
@else
    <div class="space-y-3">
        @foreach ($areas as $area)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-map class="w-5 h-5 text-primary-600" />
                        <div>
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $area['name'] }}</div>
                            <div class="text-xs">
                                @if ($area['status'] == 1)
                                    <span class="text-green-600">● Aktif</span>
                                @else
                                    <span class="text-gray-500">● Pasif</span>
                                @endif
                                <span class="text-gray-500">— {{ count($area['sub_areas']) }} lokasyon</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        {{ ($this->editAreaAction)(['area_id' => $area['id']]) }}
                        {{ ($this->addSubAreaAction)(['area_id' => $area['id']]) }}
                        {{ ($this->deleteAreaAction)(['area_id' => $area['id']]) }}
                    </div>
                </div>

                @if (!empty($area['sub_areas']))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($area['sub_areas'] as $sub)
                            <div class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 text-xs">
                                <span>{{ $sub['name'] }}</span>
                                <button
                                    type="button"
                                    wire:click="mountAction('deleteSubArea', { sub_area_id: {{ $sub['id'] }} })"
                                    class="ml-1 text-red-500 hover:text-red-700"
                                    title="Sil"
                                >
                                    ×
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
