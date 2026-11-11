{{-- Media, tautan, dan dokumen di Info obrolan (dimuat saat dialog dibuka). --}}
<div data-media-tabs>
    <div class="flex border-b border-slate-200 text-sm" role="tablist">
        @foreach (['media' => __('Media').' ('.$photos->count().')', 'links' => __('Tautan').' ('.$links->count().')', 'docs' => __('Dokumen').' ('.$documents->count().')'] as $key => $label)
            <button type="button" role="tab" data-media-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    class="flex-1 border-b-2 border-transparent px-2 py-2.5 font-medium text-slate-500 aria-selected:border-[#b08a3e] aria-selected:text-slate-900">{{ $label }}</button>
        @endforeach
    </div>

    <div data-media-panel="media" class="p-3">
        @if ($photos->isEmpty())
            <p class="py-6 text-center text-sm text-slate-500">{{ __('Belum ada foto.') }}</p>
        @else
            <div class="grid grid-cols-3 gap-1">
                @foreach ($photos as $m)
                    <a href="{{ route('chat.photo', [$thread, $m]) }}" data-photo-open data-alt="{{ __('Foto') }}" class="block aspect-square overflow-hidden rounded-md bg-slate-100">
                        <img src="{{ route('chat.photo', [$thread, $m]) }}" alt="{{ __('Foto dari :name', ['name' => $m->user?->name()]) }}" loading="lazy" class="h-full w-full object-cover">
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div data-media-panel="links" hidden class="divide-y divide-slate-100">
        @forelse ($links as $link)
            <div class="px-4 py-2.5">
                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="block break-all text-sm text-sky-700 underline">{{ $link['url'] }}</a>
                <p class="mt-0.5 flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span class="truncate">{{ $link['message']->user?->name() }}</span>
                    <button type="button" data-goto="{{ $link['message']->id }}" class="shrink-0 hover:underline">{{ $link['message']->created_at->copy()->timezone(config('app.timezone'))->format('d/m/y H:i') }} ›</button>
                </p>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-slate-500">{{ __('Belum ada tautan.') }}</p>
        @endforelse
    </div>

    <div data-media-panel="docs" hidden class="divide-y divide-slate-100">
        @forelse ($documents as $m)
            <div class="flex items-center gap-3 px-4 py-2.5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-[10px] font-bold text-rose-700">{{ $m->fileExtension() }}</span>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('chat.file', [$thread, $m]) }}" target="_blank" rel="noopener" class="block truncate text-sm font-medium text-slate-900 hover:underline">{{ $m->file_name }}</a>
                    <p class="truncate text-xs text-slate-500">{{ $m->fileSizeLabel() }} · {{ $m->user?->name() }} · {{ $m->created_at->copy()->timezone(config('app.timezone'))->format('d/m/y') }}</p>
                </div>
                <button type="button" data-goto="{{ $m->id }}" class="shrink-0 rounded-full px-2 py-1 text-xs text-slate-500 hover:bg-slate-100" aria-label="{{ __('Lihat di obrolan') }}">›</button>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-slate-500">{{ __('Belum ada dokumen.') }}</p>
        @endforelse
    </div>
</div>
