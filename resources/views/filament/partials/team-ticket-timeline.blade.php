{{--
    Status history of a team ticket, newest first. Notes are escaped.
    Inline styles on purpose: Filament's prebuilt CSS does not scan this
    partial, so arbitrary Tailwind classes would be missing (see app/Filament/CLAUDE.md).
--}}
<div style="display:flex;flex-direction:column;gap:14px;border-left:2px solid rgba(148,163,184,.35);padding-left:18px;">
    @forelse ($histories as $history)
        <div style="position:relative;" wire:key="history-{{ $history->id }}">
            <span style="position:absolute;left:-25px;top:5px;width:10px;height:10px;border-radius:9999px;background:#3b82f6;"></span>
            <div style="font-size:.875rem;line-height:1.4;">
                <strong>
                    @if ($history->from_status === null)
                        {{ __('ui.history_created') }}
                    @elseif ($history->to_status && $history->from_status !== $history->to_status)
                        {{ $history->from_status->getLabel() }} → {{ $history->to_status->getLabel() }}
                    @else
                        {{ __('ui.history_note') }}
                    @endif
                </strong>
                <span style="opacity:.7;">
                    · {{ $history->changedBy?->name ?? __('ui.system') }} · {{ $history->created_at?->translatedFormat('d M Y H:i') }}
                </span>
            </div>
            @if (filled($history->note))
                <div style="margin-top:4px;font-size:.875rem;white-space:pre-line;opacity:.9;">{{ $history->note }}</div>
            @endif
        </div>
    @empty
        <div style="font-size:.875rem;opacity:.7;">—</div>
    @endforelse
</div>
