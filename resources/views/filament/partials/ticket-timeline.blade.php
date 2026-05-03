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

    // Bottom-of-timeline "Not Ekle" button — mirrors the visibility rule
    // from the add_comment header action (creator on any state, or
    // anyone with ticket.assign on a non-terminal ticket).
    $viewer = auth()->user();
    $canAddComment = false;
    if ($record && $viewer) {
        $isCreator = (int) $record->created_by === (int) $viewer->id;
        $isTerminal = in_array($record->status, [
            \App\Enums\TaskStatusEnum::RESOLVED,
            \App\Enums\TaskStatusEnum::CLOSED,
            \App\Enums\TaskStatusEnum::COMPLETED,
            \App\Enums\TaskStatusEnum::CANCELLED,
        ], true);
        $canAddComment = ($isCreator || !$isTerminal)
            && ($isCreator || $viewer->can('ticket.assign'));
    }
@endphp

<div style="width:100%;max-width:none;display:block;">
    {!! $html !!}

    @if($canAddComment)
        <div class="mt-4 flex justify-end">
            <button
                type="button"
                wire:click="mountAction('add_comment')"
                class="fi-btn fi-btn-color-gray fi-color-gray fi-btn-size-md inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium shadow-sm ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
            >
                <x-heroicon-o-chat-bubble-left class="w-4 h-4" />
                Not Ekle
            </button>
        </div>
    @endif
</div>
