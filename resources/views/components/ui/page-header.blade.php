{{-- Kepala halaman: tautan kembali (opsional, pengganti breadcrumb), judul, subjudul, dan tombol aksi (slot "actions"). --}}
@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => null])
<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-x-4 gap-y-3') }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="link-back mb-1"><span aria-hidden="true">‹</span> {{ $backLabel ?? __('Kembali') }}</a>
        @endif
        <h1 class="text-page">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
