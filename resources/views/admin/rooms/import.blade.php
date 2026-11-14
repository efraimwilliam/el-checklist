<x-app-layout title="{{ __('Impor kamar') }}">
    <a href="{{ route('admin.rooms.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Data kamar') }}</a>
    <h1 class="mb-1 mt-1 text-3xl text-slate-900">{{ __('Impor daftar kamar') }}</h1>
    <p class="mb-5 text-sm text-slate-500">{{ __('Unggah berkas Excel (.xlsx), periksa hasilnya, lalu simpan.') }}</p>

    @include('admin.rooms.partials.import-panel', ['plan' => $plan, 'file' => null])
</x-app-layout>
