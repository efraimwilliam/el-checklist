{{-- Keadaan kosong: ikon, judul, keterangan, dan tindakan (slot) supaya pengguna tahu langkah berikutnya. --}}
@props(['title', 'text' => null])
<div {{ $attributes->class('rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center') }}>
    <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-gold-100 text-gold-700" aria-hidden="true">
        @isset($icon){{ $icon }}@else<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>@endisset
    </div>
    <p class="font-semibold text-slate-900">{{ $title }}</p>
    @if ($text)
        <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ $text }}</p>
    @endif
    @if (trim($slot))
        <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
