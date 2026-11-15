{{-- Tombol ikon 44 px. label wajib: jadi aria-label dan title (keterangan saat diarahkan kursor). Ikon = isi slot (svg). --}}
@props(['label', 'href' => null, 'tone' => null])
@php($class = 'btn-icon'.($tone === 'danger' ? ' text-rose-700 hover:text-rose-800' : ''))
@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->class($class) }}>{{ $slot }}</a>
@else
    <button aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['type' => 'button'])->class($class) }}>{{ $slot }}</button>
@endif
