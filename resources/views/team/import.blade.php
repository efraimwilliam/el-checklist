<x-app-layout :title="__('Impor kamus daftar tugas')">
    <a href="{{ route('team.catalog') }}" class="text-sm font-semibold text-sky-700 hover:underline">{{ __('← Kamus daftar tugas') }}</a>
    <h1 class="mb-1 mt-2 text-3xl text-slate-900">{{ __('Impor kamus daftar tugas') }}</h1>
    <p class="mb-5 text-sm text-slate-500">{{ __('Unduh daftar dari Excel, ubah isinya, lalu unggah lagi. Periksa dulu sebelum disimpan.') }}</p>

    @include('team.partials.import-panel')
</x-app-layout>
