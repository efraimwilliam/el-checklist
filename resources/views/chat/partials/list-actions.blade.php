{{-- Aksi cepat satu baris obrolan: tersembunyi di balik baris, muncul saat digeser ke kanan (HP) atau lewat tombol ⌄ / klik kanan (komputer).
     Butuh: $item, $viewingAll. --}}
@unless ($viewingAll)
    @php
        $isUnread = (int) $item->unread_count > 0 || (bool) $item->marked_unread;
        $isPinned = $item->pin_order !== null;
    @endphp
    <span data-row-actions class="invisible absolute inset-y-0 left-0 flex w-36">
        <button type="button" data-row-action="unread" class="flex flex-1 flex-col items-center justify-center gap-1 bg-sky-600 text-[11px] font-medium text-white">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                @if ($isUnread)<path d="M3 12.5l5 5 13-13" stroke-linecap="round" stroke-linejoin="round"/>@else<circle cx="12" cy="12" r="4.5" fill="currentColor"/><circle cx="12" cy="12" r="9"/>@endif
            </svg>
            <span data-row-label>{{ $isUnread ? __('Sudah dibaca') : __('Belum dibaca') }}</span>
        </button>
        <button type="button" data-row-action="pin" class="flex flex-1 flex-col items-center justify-center gap-1 bg-amber-500 text-[11px] font-medium text-white">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 3h6l-1 6 3 3v2H7v-2l3-3-1-6zM12 14v7" stroke-linecap="round" stroke-linejoin="round"/>@if ($isPinned)<path d="M4 4l16 16" stroke-linecap="round"/>@endif</svg>
            <span data-row-label>{{ $isPinned ? __('Lepas') : __('Sematkan') }}</span>
        </button>
    </span>
    <button type="button" data-row-more aria-haspopup="menu" aria-label="{{ __('Menu obrolan') }}"
            class="absolute bottom-1.5 right-2 z-[2] hidden h-6 w-6 place-items-center rounded-full bg-white/95 text-slate-500 shadow ring-1 ring-black/10 hover:text-slate-800 md:group-hover/row:grid md:focus:grid">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5.5 7.5l4.5 4.5 4.5-4.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
@endunless
