@props(['since' => null, 'duration' => null])

<div role="alert"
     class="flex items-start gap-3 rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 dark:border-warning-700/60 dark:bg-warning-950/40 ring-1 ring-warning-200/60 dark:ring-warning-800/40">

    <svg class="mt-0.5 h-5 w-5 flex-none text-warning-600 dark:text-warning-400"
         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd"
              d="M6.75 5.25a.75.75 0 0 1 .75.75V18a.75.75 0 0 1-1.5 0V6a.75.75 0 0 1 .75-.75Zm10.5 0a.75.75 0 0 1 .75.75V18a.75.75 0 0 1-1.5 0V6a.75.75 0 0 1 .75-.75Z"
              clip-rule="evenodd" />
    </svg>

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
