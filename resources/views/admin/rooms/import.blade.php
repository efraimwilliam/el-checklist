<x-app-layout title="{{ __('Impor kamar') }}">
    <a href="{{ route('admin.rooms.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Data kamar') }}</a>
    <h1 class="mb-1 mt-1 text-3xl text-slate-900">{{ __('Impor daftar kamar') }}</h1>
    <p class="mb-5 text-sm text-slate-500">{{ __('Unggah berkas Excel (.xlsx). Baris pertama berisi judul kolom: Nomor kamar (wajib), Lantai, Gedung, Tipe. Bila Lantai kosong, lantai ditebak dari nomor kamar (305 menjadi lantai 3).') }}</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    @if ($plan === null)
        <form method="POST" action="{{ route('admin.rooms.import.preview') }}" enctype="multipart/form-data" class="card space-y-4 p-5">
            @csrf
            <div>
                <label for="berkas" class="label">{{ __('Berkas Excel') }}</label>
                <input id="berkas" type="file" name="berkas" accept=".xlsx" required class="input w-full">
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button class="btn-primary">{{ __('Periksa berkas') }}</button>
                <a href="{{ route('admin.rooms.template') }}" class="btn-secondary">{{ __('Unduh contoh berkas') }}</a>
            </div>
        </form>
    @else
        <section class="card mb-5 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Hasil pemeriksaan') }}</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">{{ __('Kamar baru') }}</dt><dd class="font-display text-2xl text-emerald-800">{{ count($plan['create']) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Diperbarui') }}</dt><dd class="font-display text-2xl text-slate-900">{{ count($plan['update']) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Tidak berubah') }}</dt><dd class="font-display text-2xl text-slate-900">{{ $plan['same'] }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Kesalahan') }}</dt><dd class="font-display text-2xl {{ $plan['errors'] ? 'text-rose-700' : 'text-slate-900' }}">{{ count($plan['errors']) }}</dd></div>
            </dl>

            @if ($plan['errors'])
                <ul class="mt-4 space-y-1 text-sm text-rose-700">
                    @foreach (array_slice($plan['errors'], 0, 30) as $error)<li>{{ __('Baris') }} {{ $error['line'] }}: {{ $error['message'] }}</li>@endforeach
                    @if (count($plan['errors']) > 30)<li>… {{ count($plan['errors']) - 30 }} {{ __('kesalahan lain') }}</li>@endif
                </ul>
            @endif

            <div class="mt-5 flex flex-wrap items-center gap-3">
                @if (! $plan['errors'] && (count($plan['create']) || count($plan['update'])))
                    <form method="POST" action="{{ route('admin.rooms.import.apply') }}">@csrf<button class="btn-primary">{{ __('Simpan') }}</button></form>
                @elseif (! $plan['errors'])
                    <p class="text-sm text-slate-500">{{ __('Tidak ada yang perlu disimpan.') }}</p>
                @endif
                <form method="POST" action="{{ route('admin.rooms.import.cancel') }}">@csrf @method('DELETE')<button class="btn-secondary">{{ $plan['errors'] ? __('Unggah ulang') : __('Batal') }}</button></form>
            </div>
        </section>
    @endif
</x-app-layout>
