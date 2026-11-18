{{--
    Attachments of a team ticket as equal, centred tiles: images are shown
    whole (letterboxed), PDFs as a file card; both open the original in a
    new tab. Inline styles on purpose: Filament's prebuilt CSS does not scan
    this partial, so arbitrary Tailwind classes would be missing (see app/Filament/CLAUDE.md).
--}}
<div style="display:flex;flex-wrap:wrap;justify-content:center;gap:16px;">
    @foreach ($media as $file)
        @php
            $url = $urlFor($file);
            $isImage = str_starts_with((string) $file->mime_type, 'image/');
        @endphp
        <a href="{{ $url }}" target="_blank" rel="noopener" title="{{ $file->file_name }}" wire:key="attachment-{{ $file->id }}"
           style="display:flex;flex-direction:column;width:280px;max-width:100%;overflow:hidden;border-radius:12px;border:1px solid rgba(148,163,184,.35);text-decoration:none;color:inherit;">
            @if ($isImage)
                <img src="{{ $url }}" alt="{{ $file->file_name }}" loading="lazy"
                     style="display:block;width:100%;height:220px;object-fit:contain;background:rgba(148,163,184,.12);">
            @else
                <div style="display:flex;align-items:center;justify-content:center;height:220px;background:rgba(148,163,184,.12);font-size:1rem;font-weight:600;letter-spacing:.05em;">
                    {{ strtoupper(pathinfo($file->file_name, PATHINFO_EXTENSION) ?: 'DOSYA') }}
                </div>
            @endif
            <div style="padding:8px 10px;font-size:.8rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.85;">
                {{ $file->file_name }}
            </div>
        </a>
    @endforeach
</div>
