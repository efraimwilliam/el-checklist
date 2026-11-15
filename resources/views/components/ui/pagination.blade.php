{{-- Paginasi sederhana (di kaki kartu daftar): "Halaman x / y · N unit" + Sebelumnya / Berikutnya. Tidak tampil bila hanya satu halaman. --}}
@props(['paginator', 'unit' => null])
@if ($paginator->hasPages())
    <nav {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-4 text-sm') }} aria-label="{{ __('Halaman') }}">
        <span class="text-slate-500">{{ __('Halaman') }} {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}@if ($unit && method_exists($paginator, 'total')) · {{ $paginator->total() }} {{ $unit }}@endif</span>
        <span class="flex gap-2">
            @if ($paginator->previousPageUrl())<a class="btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ {{ __('Sebelumnya') }}</a>@endif
            @if ($paginator->hasMorePages())<a class="btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Berikutnya') }} ›</a>@endif
        </span>
    </nav>
@endif
