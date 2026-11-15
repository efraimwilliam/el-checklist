{{-- Angka merah kecil (belum dibaca / baru). Tidak tampil bila 0; di atas 99 jadi "99+". --}}
@props(['n' => 0])
@if ($n > 0)
    <span {{ $attributes->class('inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-semibold leading-5 text-white') }}>{{ $n > 99 ? '99+' : $n }}</span>
@endif
