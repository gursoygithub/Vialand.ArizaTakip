@php
    /**
     * Renders the full-width timeline by delegating to ViewTicket::renderTimeline().
     * ViewEntry exposes the record via the $getRecord() callable injected into
     * its blade context — not as a $record variable.
     * Wrapping in a width:100% block escapes any prose / column constraints
     * from the parent infolist styling.
     */
    $record = $getRecord();
    $html = $record
        ? \App\Filament\Resources\TicketResource\Pages\ViewTicket::renderTimelinePublic($record)
        : '';
@endphp

<div style="width:100%;max-width:none;display:block;">
    {!! $html !!}
</div>
