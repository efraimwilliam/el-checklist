{{-- Daftar obrolan (panel kiri). Dimuat ulang lewat chat.list tiap beberapa detik. --}}
@php
    $me = auth()->user();
    $tones = ['bg-[#f3e6c4] text-[#8a6a22]', 'bg-sky-100 text-sky-800', 'bg-emerald-100 text-emerald-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-orange-100 text-orange-800'];
    $when = function ($time) {
        if (! $time) {
            return '';
        }
        $time = $time->copy()->timezone(config('app.timezone'));
        return match (true) {
            $time->isToday() => $time->format('H:i'),
            $time->isYesterday() => __('Kemarin'),
            $time->gt(now()->subDays(6)->startOfDay()) => $time->locale(app()->getLocale())->translatedFormat('l'),
            default => $time->format('d/m/y'),
        };
    };
@endphp
@php
    $pinnedItems = $viewingAll ? collect() : $threads->filter(fn ($t) => $t->pin_order !== null)->values();
    $otherItems = $viewingAll ? $threads : $threads->filter(fn ($t) => $t->pin_order === null)->values();
@endphp
@if ($pinnedItems->isNotEmpty())
    <div class="flex items-center justify-between px-4 pb-1 pt-2">
        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#8a6a22]">📌 {{ __('Disematkan') }}</p>
        @if ($pinnedItems->count() > 1)
            <button type="button" data-pin-reorder class="rounded-full px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-slate-100" data-label-edit="{{ __('Urutkan') }}" data-label-done="{{ __('Selesai') }}">{{ __('Urutkan') }}</button>
        @endif
    </div>
    <div data-pin-group>
        @foreach ($pinnedItems as $item)
            <div data-thread data-name="{{ mb_strtolower($item->title(auth()->user())) }}" data-pinned-row="{{ $item->id }}" draggable="false" class="group/row relative overflow-hidden"
                 data-pin-url="{{ route('chat.pin', $item) }}" data-mark-url="{{ route('chat.mark', $item) }}" data-unread="{{ (int) $item->unread_count > 0 || $item->marked_unread ? 1 : 0 }}" data-pinned="1">
                @include('chat.partials.list-actions')
                @include('chat.partials.list-row', ['pinnedRow' => true])
                <span hidden data-reorder-controls class="absolute inset-y-0 right-2 z-[3] flex items-center gap-1 bg-gradient-to-l from-[#fffdf9] from-70% to-transparent pl-6">
                    <button type="button" data-move="-1" class="grid h-8 w-8 place-items-center rounded-full bg-white text-slate-600 shadow ring-1 ring-black/10 hover:bg-slate-50" aria-label="{{ __('Naikkan') }}">▲</button>
                    <button type="button" data-move="1" class="grid h-8 w-8 place-items-center rounded-full bg-white text-slate-600 shadow ring-1 ring-black/10 hover:bg-slate-50" aria-label="{{ __('Turunkan') }}">▼</button>
                </span>
            </div>
        @endforeach
    </div>
    @if ($otherItems->isNotEmpty())
        <p class="border-t border-slate-100 px-4 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">{{ __('Semua obrolan') }}</p>
    @endif
@endif
@forelse ($otherItems as $item)
    <div data-thread data-name="{{ mb_strtolower($item->title(auth()->user())) }}" class="group/row relative overflow-hidden"
         @unless ($viewingAll) data-pin-url="{{ route('chat.pin', $item) }}" data-mark-url="{{ route('chat.mark', $item) }}" data-unread="{{ (int) $item->unread_count > 0 || $item->marked_unread ? 1 : 0 }}" data-pinned="0" @endunless>
        @include('chat.partials.list-actions')
        @include('chat.partials.list-row', ['pinnedRow' => false])
    </div>
@empty
    <div class="px-6 py-12 text-center text-sm text-slate-500">
        @if ($viewingAll)
            {{ __('Belum ada percakapan.') }}
        @else
            <p>{{ __('Belum ada obrolan.') }}</p>
            <p class="mt-1">{{ __('Tekan tombol pensil untuk chat dengan rekan atau membuat grup.') }}</p>
        @endif
    </div>
@endforelse
<p class="hidden px-6 py-8 text-center text-sm text-slate-500" data-thread-empty>{{ __('Tidak ada obrolan yang cocok.') }}</p>
