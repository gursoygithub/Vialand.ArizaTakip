@php
    /** @var int $companyId */
    /** @var array $stats */
    $stats = $stats ?? [];
@endphp

@if (empty($stats))
    <div class="text-sm text-gray-500">Şirket seçilmedi.</div>
@else
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
            <h3 class="text-lg font-bold mb-4">Kurulum Özeti</h3>

            <ul class="space-y-2 text-sm">
                <li class="flex items-center gap-2">
                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-600" />
                    <strong>Şirket:</strong> {{ $stats['company_name'] }}
                </li>
                <li class="flex items-center gap-2">
                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-600" />
                    <strong>Bölgeler:</strong> {{ number_format($stats['area_count']) }} bölge,
                    {{ number_format($stats['sub_area_count'] ?? 0) }} lokasyon
                </li>
                <li class="flex items-center gap-2">
                    @if (($stats['missing_total'] ?? 0) > 0)
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-orange-600" />
                    @else
                        <x-heroicon-o-check-circle class="w-5 h-5 text-green-600" />
                    @endif
                    <strong>SLA Politikaları:</strong>
                    {{ number_format($stats['sla_count']) }} /
                    {{ number_format($stats['sla_count'] + ($stats['missing_total'] ?? 0)) }}
                    kombinasyon tanımlı
                    @if (($stats['missing_total'] ?? 0) > 0)
                        <span class="text-orange-600 ml-1">({{ $stats['missing_total'] }} eksik)</span>
                    @endif
                </li>
                <li class="flex items-center gap-2">
                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-600" />
                    <strong>Gruplar:</strong> {{ number_format($stats['group_count']) }} grup,
                    {{ number_format($stats['group_member_count'] ?? 0) }} üye
                </li>
            </ul>

            @if (!empty($stats['missing_list']))
                <div class="mt-5 p-3 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800">
                    <div class="text-sm font-semibold text-orange-800 dark:text-orange-200 mb-2">
                        Eksik SLA Kombinasyonları
                        @if (($stats['missing_total'] ?? 0) > count($stats['missing_list']))
                            <span class="text-xs font-normal">(ilk {{ count($stats['missing_list']) }} gösteriliyor)</span>
                        @endif
                    </div>
                    <ul class="text-xs text-orange-700 dark:text-orange-300 space-y-0.5 max-h-48 overflow-y-auto">
                        @foreach ($stats['missing_list'] as $combo)
                            <li>• {{ $combo }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <button
                    type="button"
                    wire:click="resetWizard"
                    class="fi-btn fi-btn-color-gray inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    <x-heroicon-o-arrow-path class="w-4 h-4 mr-1.5" />
                    Başka Şirket Kur
                </button>
            </div>
        </div>
    </div>
@endif
