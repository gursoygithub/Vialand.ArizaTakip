@php
    $record = $getRecord();

    $inProgressAt = $record
        ?->statusHistories()
        ?->where('to_status', \App\Enums\TaskStatusEnum::IN_PROGRESS->value)
        ?->orderBy('created_at')
        ?->first()
        ?->created_at;

    $steps = [
        ['icon' => '📅', 'label' => 'Açıldı',         'date' => $record?->created_at],
        ['icon' => '👤', 'label' => 'Atandı',          'date' => $record?->assigned_at],
        ['icon' => '⚙️', 'label' => 'İşleme Alındı',  'date' => $inProgressAt],
        ['icon' => '✅', 'label' => 'Çözüldü',         'date' => $record?->resolved_at],
        ['icon' => '🔒', 'label' => 'Kapatıldı',       'date' => $record?->closed_at],
    ];
@endphp

<div class="flex items-start gap-2 flex-wrap py-2">
    @foreach($steps as $step)
        <div class="flex items-center gap-1">
            <div class="flex flex-col items-center min-w-[90px]">
                <span class="text-lg">{{ $step['icon'] }}</span>
                <span class="text-xs font-medium {{ $step['date'] ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400' }}">
                    {{ $step['label'] }}
                </span>
                <span class="text-xs {{ $step['date'] ? 'text-gray-600 dark:text-gray-400' : 'text-gray-300' }}">
                    {{ $step['date'] ? $step['date']->translatedFormat('d M H:i') : '—' }}
                </span>
            </div>
            @if(!$loop->last)
                <div class="w-8 h-px bg-gray-300 dark:bg-gray-600 mb-3"></div>
            @endif
        </div>
    @endforeach
</div>
