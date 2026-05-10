@props(['since' => null, 'duration' => null])

<div role="alert"
     class="flex items-start gap-3 rounded-lg border border-warning-400 bg-warning-100 px-4 py-3 dark:border-warning-700 dark:bg-warning-950/50 ring-1 ring-warning-300/50 dark:ring-warning-800/40">

    @svg('heroicon-m-pause-circle', 'mt-0.5 h-6 w-6 flex-none text-warning-600 dark:text-warning-400')

    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-warning-900 dark:text-warning-100">
            Bu talep beklemededir
        </p>
        <p class="mt-0.5 text-sm text-warning-800 dark:text-warning-200">
            SLA sayacı durduruldu.
            @if($since)
                <span class="font-medium">{{ $since }}</span> tarihinden beri
                <span class="font-medium">({{ $duration }})</span>
            @endif
        </p>
    </div>
</div>
