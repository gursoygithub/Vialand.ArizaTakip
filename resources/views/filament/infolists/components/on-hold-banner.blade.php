@props(['since', 'duration'])

<div role="alert"
     class="flex items-start gap-3 rounded-lg px-4 py-3"
     style="background-color: #FEF3C7; border: 1px solid #F59E0B; box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.15);">

    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
         style="margin-top: 2px; height: 24px; width: 24px; flex: none; color: #B45309;"
         aria-hidden="true">
        <path fill-rule="evenodd"
              d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM10.5 8.25a.75.75 0 0 0-1.5 0v7.5a.75.75 0 0 0 1.5 0v-7.5Zm4.5 0a.75.75 0 0 0-1.5 0v7.5a.75.75 0 0 0 1.5 0v-7.5Z"
              clip-rule="evenodd" />
    </svg>

    <div style="flex: 1; min-width: 0;">
        <p style="font-size: 14px; font-weight: 600; color: #78350F; margin: 0;">
            Bu talep beklemededir
        </p>
        <p style="margin-top: 2px; font-size: 14px; color: #92400E;">
            SLA sayacı durduruldu.
            @if($since)
                <span style="font-weight: 500;">{{ $since }}</span> tarihinden beri
                <span style="font-weight: 500;">({{ $duration }})</span>
            @endif
        </p>
    </div>
</div>
