@php
    /** @var array $areas */
    /** @var array $units */
    /** @var array $matrix */
    /** @var int $defined */
    /** @var int $missing */
    /** @var int|null $scope_sub_area_id */
    $areas = $areas ?? [];
    $units = $units ?? [];
    $matrix = $matrix ?? [];
    $scopeSubAreaId = $scope_sub_area_id ?? null;
    // For Livewire's mountAction we need a JSON-safe value: integer or `null`.
    $scopeArg = $scopeSubAreaId === null ? 'null' : (int) $scopeSubAreaId;
@endphp

<div class="text-xs text-gray-600 dark:text-gray-400 mb-2">
    @if ($scopeSubAreaId === null)
        Düzenlenen kapsam: <strong>Varsayılan (tüm lokasyonlar)</strong>. Boş hücreler için bu bölge geneli SLA tanımlanmamış demektir.
    @else
        Düzenlenen kapsam: <strong>Belirli lokasyon (override)</strong>. Sadece ilgili bölge listelenir; boş hücrelerde varsayılana geri düşer.
    @endif
</div>

@if (empty($areas))
    @if ($scopeSubAreaId !== null)
        <div class="text-sm text-gray-500">Seçili lokasyon için bölge bulunamadı.</div>
    @else
        <div class="text-sm text-gray-500">Önce bu şirket için en az bir bölge tanımlayın (Adım 2).</div>
    @endif
@elseif (empty($units))
    <div class="text-sm text-gray-500">Sistemde tanımlı birim yok. UnitSeeder'ı çalıştırın.</div>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold sticky left-0 bg-gray-50 dark:bg-gray-800 z-10">Bölge \ Birim</th>
                    @foreach ($units as $unit)
                        <th class="px-3 py-2 text-left font-semibold whitespace-nowrap">{{ $unit->name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($areas as $area)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-3 font-medium sticky left-0 bg-white dark:bg-gray-800 z-10">{{ $area->name }}</td>
                        @foreach ($units as $unit)
                            @php
                                $cell = $matrix[$area->id][$unit->id] ?? null;
                                $isEmpty = $cell['is_empty'] ?? true;
                            @endphp
                            <td class="px-2 py-2 align-top {{ $isEmpty ? 'bg-orange-50 dark:bg-orange-900/20' : '' }}">
                                <button
                                    type="button"
                                    wire:click="mountAction('setSla', { area_id: {{ $area->id }}, unit_id: {{ $unit->id }}, sub_area_id: {{ $scopeArg }} })"
                                    class="w-full text-left rounded p-2 hover:bg-primary-50 dark:hover:bg-primary-900/20 cursor-pointer transition"
                                >
                                    @if ($isEmpty)
                                        <div class="text-xs text-orange-700 dark:text-orange-300 font-semibold">Tanımlanmamış</div>
                                        <div class="text-[10px] text-orange-600 dark:text-orange-400 mt-1">Düzenlemek için tıklayın</div>
                                    @else
                                        <div class="space-y-0.5 text-xs">
                                            @foreach ($cell['priorities'] as $priorityValue => $priority)
                                                @php
                                                    $color = $priority['color'] ?? 'gray';
                                                    $colorClass = match ($color) {
                                                        'success' => 'text-green-600',
                                                        'primary' => 'text-blue-600',
                                                        'warning' => 'text-orange-600',
                                                        'danger'  => 'text-red-600',
                                                        default   => 'text-gray-500',
                                                    };
                                                @endphp
                                                <div class="flex justify-between gap-2">
                                                    <span class="{{ $colorClass }} font-medium">{{ $priority['label'] }}</span>
                                                    <span class="text-gray-600 dark:text-gray-300">
                                                        @if ($priority['minutes'])
                                                            {{ $priority['minutes'] }} dk
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3 text-sm flex flex-wrap gap-4">
        <span class="text-green-700 dark:text-green-300">
            <strong>{{ number_format($defined) }}</strong> kombinasyon tanımlı
        </span>
        @if ($missing > 0)
            <span class="text-orange-700 dark:text-orange-300">
                <strong>{{ number_format($missing) }}</strong> kombinasyon eksik
            </span>
        @endif
    </div>
@endif
