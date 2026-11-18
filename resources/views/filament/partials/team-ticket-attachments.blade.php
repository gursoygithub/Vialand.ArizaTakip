{{--
    Attachments of a team ticket as fixed-size tiles: images are cropped
    thumbnails, PDFs a file card; both open the original in a new tab.
    Inline styles on purpose: Filament's prebuilt CSS does not scan this
    partial, so arbitrary Tailwind classes would be missing (see app/Filament/CLAUDE.md).
--}}
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
    @foreach ($media as $file)
        @php
            $url = $urlFor($file);
            $isImage = str_starts_with((string) $file->mime_type, 'image/');
        @endphp
        <a href="{{ $url }}" target="_blank" rel="noopener" title="{{ $file->file_name }}" wire:key="attachment-{{ $file->id }}"
           style="display:flex;flex-direction:column;overflow:hidden;border-radius:10px;border:1px solid rgba(148,163,184,.35);text-decoration:none;color:inherit;">
            @if ($isImage)
                <img src="{{ $url }}" alt="{{ $file->file_name }}" loading="lazy"
                     style="display:block;width:100%;height:140px;object-fit:cover;background:rgba(148,163,184,.12);">
            @else
                <div style="display:flex;align-items:center;justify-content:center;height:140px;background:rgba(148,163,184,.12);font-size:.875rem;font-weight:600;letter-spacing:.05em;">
                    {{ strtoupper(pathinfo($file->file_name, PATHINFO_EXTENSION) ?: 'DOSYA') }}
                </div>
            @endif
            <div style="padding:6px 8px;font-size:.75rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.85;">
                {{ $file->file_name }}
            </div>
        </a>
    @endforeach
</div>
