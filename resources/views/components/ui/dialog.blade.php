{{-- Jendela dialog (<dialog>, dibuka lewat showModal()). size: sm 26rem | md 32rem | lg 42rem | xl 56rem.
     Tombol × menutup tanpa JavaScript (form method=dialog). Slot "footer" = baris tombol di kanan bawah. --}}
@props(['size' => 'md', 'title' => null, 'titleId' => null])
@php($width = ['sm' => 'w-[min(26rem,calc(100vw-2rem))]', 'md' => 'w-[min(32rem,calc(100vw-2rem))]', 'lg' => 'w-[min(42rem,calc(100vw-2rem))]', 'xl' => 'w-[min(56rem,calc(100vw-2rem))]'][$size] ?? $size)
@php($titleId ??= 'dialog-title-'.\Illuminate\Support\Str::random(6))
<dialog @if ($title) aria-labelledby="{{ $titleId }}" @endif {{ $attributes->class("dialog-panel {$width}") }}>
    @if ($title)
        <div class="flex items-start justify-between gap-3 px-6 pt-5">
            <h2 id="{{ $titleId }}" class="text-section">{{ $title }}</h2>
            <form method="dialog"><button class="dialog-close -mr-2 -mt-1" aria-label="{{ __('Tutup') }}">×</button></form>
        </div>
    @endif
    <div class="px-6 py-4">{{ $slot }}</div>
    @isset($footer)
        <div class="flex flex-col-reverse gap-2 px-6 pb-6 sm:flex-row sm:justify-end">{{ $footer }}</div>
    @endisset
</dialog>
