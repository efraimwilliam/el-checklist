{{-- Tombol aplikasi. variant: primary | secondary | danger | ghost; size: sm. Dengan href = tautan bergaya tombol. --}}
@props(['variant' => 'primary', 'size' => null, 'href' => null])
@php($class = 'btn-'.$variant.($size === 'sm' ? ' btn-sm' : ''))
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($class) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($class) }}>{{ $slot }}</button>
@endif
