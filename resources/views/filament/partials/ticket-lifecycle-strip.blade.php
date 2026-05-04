@php
    $record = $getRecord();

    $steps = [
        ['label' => 'Açıldı',         'date' => $record?->created_at],
        ['label' => 'Atandı',          'date' => $record?->assigned_at],
        ['label' => 'İşleme Alındı',  'date' => $inProgressAt ?? null],
        ['label' => 'Çözüldü',         'date' => $record?->resolved_at],
        ['label' => 'Kapatıldı',       'date' => $record?->closed_at],
    ];
@endphp

<div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm px-6 py-4 mb-2">
    <div class="flex items-start justify-between w-full">
        @foreach($steps as $i => $step)
            @php $done = (bool) $step['date']; @endphp

            <div class="flex flex-col items-center flex-shrink-0">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm
                    {{ $done
                        ? 'bg-primary-600 text-white font-bold'
                        : 'border-2 border-gray-300 dark:border-gray-600 text-gray-400 bg-white dark:bg-gray-800' }}">
                    {{ $i + 1 }}
                </div>
                <div class="text-xs font-medium mt-1 text-center
                    {{ $done ? 'text-gray-800 dark:text-gray-100' : 'text-gray-400' }}">
                    {{ $step['label'] }}
                </div>
                <div class="text-xs mt-0.5 text-center
                    {{ $done ? 'text-gray-500 dark:text-gray-400' : 'text-gray-300' }}">
                    {{ $done ? $step['date']->translatedFormat('d M Y H:i') : '—' }}
                </div>
            </div>

            @if(!$loop->last)
                <div class="flex-1 h-0.5 mt-4 mx-1
                    {{ $done ? 'bg-primary-500' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
            @endif
        @endforeach
    </div>
</div>
