{{--
    Satu halaman "Tugas & jabatan" (menu Tugas): judul bersama + tab Pegawai (jabatan checklist + tugas tiap pegawai) dan Jabatan.
    Layar rincian (satu pegawai, satu jabatan, atur jabatan sekaligus) memakai pembungkus yang sama, jadi tab dan judul tetap ada dan tab
    induknya tetap menyala. $tab = pegawai | jabatan. Kamus daftar tugas (/tim/daftar) sengaja menu sendiri, bukan tab di sini.
--}}
@props(['tab', 'title' => null])
@php
    $tabs = array_filter([
        'pegawai' => Gate::allows('manage-team-tasks') ? ['label' => __('Pegawai'), 'hint' => __('Jabatan checklist dan tugas tiap pegawai'), 'route' => 'team.index'] : null,
        'jabatan' => Gate::allows('manage-tasks') ? ['label' => __('Jabatan'), 'hint' => __('Jabatan dan urutan tugasnya'), 'route' => 'admin.tasks.index'] : null,
    ]);
@endphp
<x-app-layout :title="$title ? $title.' · '.__('Tugas & jabatan') : __('Tugas & jabatan')">
    <x-ui.page-header :title="__('Tugas & jabatan')" :subtitle="__('Atur jabatan, daftar tugasnya, dan tugas tiap pegawai di satu tempat. Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.')" />

    <nav class="-mt-2 mb-6 overflow-x-auto" aria-label="{{ __('Bagian tugas dan jabatan') }}">
        <ul class="inline-flex min-w-full gap-1 rounded-2xl bg-gold-100 p-1 sm:min-w-0">
            @foreach ($tabs as $key => $item)
                <li class="min-w-0 flex-1 sm:flex-none">
                    <a href="{{ route($item['route']) }}" @if ($tab === $key) aria-current="page" @endif title="{{ $item['hint'] }}"
                       @class(['block whitespace-nowrap rounded-xl px-4 py-2 text-center text-sm font-semibold transition', 'bg-white text-slate-900 shadow-card' => $tab === $key, 'text-slate-600 hover:bg-white/60' => $tab !== $key])>{{ $item['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{ $slot }}
</x-app-layout>
