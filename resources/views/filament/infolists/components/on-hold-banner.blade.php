@props(['since', 'duration'])

<div role="alert"
     class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-700/60 dark:bg-amber-950/40 ring-1 ring-amber-200/60 dark:ring-amber-800/40">

    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
         class="mt-0.5 h-6 w-6 flex-none text-amber-600 dark:text-amber-400" aria-hidden="true">
        <path fill-rule="evenodd"
              d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM10.5 8.25a.75.75 0 0 0-1.5 0v7.5a.75.75 0 0 0 1.5 0v-7.5Zm4.5 0a.75.75 0 0 0-1.5 0v7.5a.75.75 0 0 0 1.5 0v-7.5Z"
              clip-rule="evenodd" />
    </svg>

    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
            Bu talep beklemededir
        </p>
        <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-200">
            SLA sayacı durduruldu.
            @if($since)
                <span class="font-medium">{{ $since }}</span> tarihinden beri
                <span class="font-medium">({{ $duration }})</span>
            @endif
        </p>
    </div>
</div>
