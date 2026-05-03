@php
    /** @var array $stats */
    $stats = $stats ?? [];
@endphp

@if (empty($stats))
    <div class="text-sm text-gray-500">Şirket seçilmedi.</div>
@else
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4">
        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">
            {{ $stats['company_name'] }}
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
            <div class="rounded-lg bg-white dark:bg-gray-800 p-3 border border-gray-100 dark:border-gray-700">
                <div class="text-xs text-gray-500">Toplam Personel</div>
                <div class="text-lg font-bold text-primary-600">{{ number_format($stats['employee_count']) }}</div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 p-3 border border-gray-100 dark:border-gray-700">
                <div class="text-xs text-gray-500">Mevcut Bölge</div>
                <div class="text-lg font-bold text-primary-600">{{ number_format($stats['area_count']) }}</div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 p-3 border border-gray-100 dark:border-gray-700">
                <div class="text-xs text-gray-500">SLA Politikaları</div>
                <div class="text-lg font-bold text-primary-600">{{ number_format($stats['sla_count']) }}</div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 p-3 border border-gray-100 dark:border-gray-700">
                <div class="text-xs text-gray-500">Mevcut Grup</div>
                <div class="text-lg font-bold text-primary-600">{{ number_format($stats['group_count']) }}</div>
            </div>
            <div class="rounded-lg p-3 border
                {{ $stats['missing_sla_combos'] > 0 ? 'bg-orange-50 dark:bg-orange-900/30 border-orange-200 dark:border-orange-800' : 'bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800' }}">
                <div class="text-xs {{ $stats['missing_sla_combos'] > 0 ? 'text-orange-700 dark:text-orange-300' : 'text-green-700 dark:text-green-300' }}">Tanımlanmamış</div>
                <div class="text-lg font-bold {{ $stats['missing_sla_combos'] > 0 ? 'text-orange-700 dark:text-orange-300' : 'text-green-700 dark:text-green-300' }}">
                    {{ number_format($stats['missing_sla_combos']) }} kombinasyon
                </div>
            </div>
        </div>
    </div>
@endif
