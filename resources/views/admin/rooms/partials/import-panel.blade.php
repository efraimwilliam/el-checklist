{{-- Isi impor daftar kamar: dipakai halaman penuh (/admin/kamar/impor) dan popup di Data kamar. Formulir bertanda data-import-form dikirim lewat fetch di popup. --}}
@php
    $uploadError = $uploadError ?? $errors->first('berkas');
    $plan = $plan ?? null;
@endphp
<div class="card mb-4 space-y-3 p-5">
    <form method="POST" action="{{ route('admin.rooms.import.preview') }}" enctype="multipart/form-data" class="space-y-3" data-import-form>
        @csrf
        <label for="berkas" class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed border-gold-300 bg-[#fbf7ee] px-6 py-8 text-center transition hover:bg-[#f6efdf]" data-dropzone>
            <span class="font-semibold text-slate-800">{{ __('Seret berkas Excel ke sini') }}</span>
            <span class="text-sm text-slate-500">{{ __('atau klik untuk memilih (.xlsx)') }}</span>
            <span class="text-sm font-medium text-slate-700" data-dropzone-name></span>
            <input id="berkas" name="berkas" type="file" accept=".xlsx" required class="sr-only">
        </label>
        <div class="flex flex-wrap items-center justify-end gap-3">
            <a href="{{ route('admin.rooms.template') }}" class="btn-secondary">{{ __('Unduh contoh berkas') }}</a>
            <button class="btn-primary">{{ __('Periksa berkas') }}</button>
        </div>
    </form>
    @if ($uploadError)<p class="text-sm text-rose-700">{{ $uploadError }}</p>@endif
    <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>{{ __('Baris pertama berisi judul kolom: Nomor kamar (wajib), Lantai, Gedung, Tipe.') }}</li>
        <li>{{ __('Bila Lantai kosong, lantai ditebak dari nomor kamar (305 menjadi lantai 3).') }}</li>
    </ul>
</div>

@if ($plan)
    <section class="card mb-4 p-5">
        <h2 class="font-display text-xl text-slate-900">{{ __('Hasil pemeriksaan') }}</h2>
        @if (! empty($file))<p class="text-sm text-slate-500">{{ __('Berkas: :file', ['file' => $file]) }}</p>@endif
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
                <form method="POST" action="{{ route('admin.rooms.import.apply') }}" data-import-form>@csrf<button class="btn-primary">{{ __('Simpan') }}</button></form>
            @elseif (! $plan['errors'])
                <p class="text-sm text-slate-500">{{ __('Tidak ada yang perlu disimpan.') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.rooms.import.cancel') }}" data-import-form>@csrf @method('DELETE')<button class="btn-secondary">{{ $plan['errors'] ? __('Unggah ulang') : __('Batal') }}</button></form>
        </div>
    </section>
@endif
