<x-app-layout title="{{ __('Impor kamar') }}">
    <a href="{{ route('admin.rooms.index') }}" class="link-back"><span aria-hidden="true">‹</span> {{ __('Data kamar') }}</a>
    <h1 class="mb-1 mt-1 text-page">{{ __('Impor daftar kamar') }}</h1>
    <p class="mb-5 text-sm text-slate-500">{{ __('Unggah berkas Excel (.xlsx), periksa hasilnya, lalu simpan.') }}</p>

    @include('admin.rooms.partials.import-panel', ['plan' => $plan, 'file' => null])
</x-app-layout>
