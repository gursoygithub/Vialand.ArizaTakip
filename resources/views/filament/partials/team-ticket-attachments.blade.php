{{--
    Attachments of a team ticket as equal, centred tiles. Images open in a
    lightbox on the same page (Esc / outside click closes, arrows step
    through the images); other files (PDF) open in a new tab. Inline styles
    on purpose: Filament's prebuilt CSS does not scan this partial, so
    arbitrary Tailwind classes would be missing (see app/Filament/CLAUDE.md).
--}}
@php
    $files = collect($media)->map(fn ($file) => [
        'id' => $file->id,
        'url' => $urlFor($file),
        'name' => $file->file_name,
        'image' => str_starts_with((string) $file->mime_type, 'image/'),
    ]);
    $images = $files->where('image', true)->values();
@endphp
<div
    x-data="{
        images: @js($images->map(fn ($f) => ['url' => $f['url'], 'name' => $f['name']])->all()),
        current: null,
        show(index) { this.current = index },
        close() { this.current = null },
        step(delta) { this.current = (this.current + delta + this.images.length) % this.images.length },
    }"
    x-on:keydown.escape.window="close()"
    x-on:keydown.arrow-right.window="current !== null && images.length > 1 && step(1)"
    x-on:keydown.arrow-left.window="current !== null && images.length > 1 && step(-1)"
>
    <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:16px;">
        @foreach ($files as $file)
            @php $imageIndex = $file['image'] ? $images->search(fn ($image) => $image['id'] === $file['id']) : null; @endphp
            <a href="{{ $file['url'] }}" title="{{ $file['name'] }}" wire:key="attachment-{{ $file['id'] }}"
               @if ($file['image'])
                   x-on:click.prevent="show({{ $imageIndex }})" role="button" aria-label="{{ __('ui.attachment_open', ['name' => $file['name']]) }}"
               @else
                   target="_blank" rel="noopener"
               @endif
               style="display:flex;flex-direction:column;width:280px;max-width:100%;overflow:hidden;border-radius:12px;border:1px solid rgba(148,163,184,.35);text-decoration:none;color:inherit;cursor:pointer;">
                @if ($file['image'])
                    <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" loading="lazy"
                         style="display:block;width:100%;height:220px;object-fit:contain;background:rgba(148,163,184,.12);">
                @else
                    <div style="display:flex;align-items:center;justify-content:center;height:220px;background:rgba(148,163,184,.12);font-size:1rem;font-weight:600;letter-spacing:.05em;">
                        {{ strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'DOSYA') }}
                    </div>
                @endif
                <div style="padding:8px 10px;font-size:.8rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:.85;">
                    {{ $file['name'] }}
                </div>
            </a>
        @endforeach
    </div>

    <template x-teleport="body">
        <div x-show="current !== null" x-transition.opacity x-cloak
             x-on:click.self="close()"
             role="dialog" aria-modal="true"
             style="position:fixed;inset:0;z-index:60;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:24px;background:rgba(15,23,42,.85);">
            <button type="button" x-on:click="close()" aria-label="{{ __('ui.close') }}"
                    style="position:absolute;top:16px;right:16px;width:40px;height:40px;border-radius:9999px;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:22px;line-height:1;cursor:pointer;">&times;</button>

            <template x-if="images.length > 1">
                <div>
                    <button type="button" x-on:click="step(-1)" aria-label="{{ __('ui.previous') }}"
                            style="position:absolute;left:16px;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:9999px;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:24px;cursor:pointer;">&#8249;</button>
                    <button type="button" x-on:click="step(1)" aria-label="{{ __('ui.next') }}"
                            style="position:absolute;right:16px;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:9999px;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:24px;cursor:pointer;">&#8250;</button>
                </div>
            </template>

            <img x-bind:src="current !== null ? images[current].url : ''" x-bind:alt="current !== null ? images[current].name : ''"
                 style="max-width:min(1200px,92vw);max-height:80vh;object-fit:contain;border-radius:8px;background:#fff;">
            <div style="color:#fff;font-size:.875rem;opacity:.9;" x-text="current !== null ? images[current].name + (images.length > 1 ? ' (' + (current + 1) + ' / ' + images.length + ')' : '') : ''"></div>
        </div>
    </template>
</div>
