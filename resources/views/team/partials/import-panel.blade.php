{{-- Isi impor kamus daftar tugas: dipakai halaman penuh (/tim/daftar/impor) dan popup di Kamus daftar tugas. Formulir bertanda data-import-form dikirim lewat fetch di popup. --}}
@php
    $uploadError = $uploadError ?? $errors->first('berkas');
@endphp
<div class="card mb-4 space-y-3 p-5">
    <form method="POST" action="{{ route('team.catalog.import.preview') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" data-import-form>
        @csrf
        <div>
            <label for="berkas" class="label">{{ __('Berkas Excel (.xlsx)') }}</label>
            <input id="berkas" name="berkas" type="file" accept=".xlsx" required class="input">
        </div>
        <button class="btn-primary">{{ __('Periksa berkas') }}</button>
    </form>
    @if ($uploadError)<p class="text-sm text-rose-700">{{ $uploadError }}</p>@endif
    <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li>{{ __('Kolom ID diisi dari unduhan. Kosongkan untuk tugas baru (Departemen dan Jabatan harus sudah ada).') }}</li>
        <li>{{ __('Tugas yang dihapus dari berkas tidak ikut dihapus; hapus lewat Jabatan dan tugas.') }}</li>
    </ul>
</div>

@if ($error)
    <div class="card mb-4 border-rose-200 p-5 text-sm text-rose-700">{{ $error }}</div>
@endif

@if ($plan)
    @php
        $changes = count($plan['create']) + count($plan['update']);
    @endphp
    <div class="card mb-4 p-5">
        <p class="text-sm text-slate-500">{{ __('Berkas: :file', ['file' => $file]) }}</p>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <span class="pill bg-emerald-100 text-emerald-800">{{ __('Tugas baru: :n', ['n' => count($plan['create'])]) }}</span>
            <span class="pill bg-sky-100 text-sky-800">{{ __('Diubah: :n', ['n' => count($plan['update'])]) }}</span>
            <span class="pill-neutral">{{ __('Tidak berubah: :n', ['n' => $plan['same']]) }}</span>
            <span class="pill {{ $plan['errors'] ? 'bg-rose-100 text-rose-800' : 'pill-neutral' }}">{{ __('Kesalahan: :n', ['n' => count($plan['errors'])]) }}</span>
        </div>
    </div>

    @if ($plan['errors'])
        <div class="card mb-4 border-rose-200 p-5">
            <h2 class="mb-2 font-semibold text-rose-800">{{ __('Perbaiki kesalahan ini dulu, lalu unggah lagi:') }}</h2>
            <ul class="space-y-1 text-sm text-rose-700">
                @foreach ($plan['errors'] as $item)
                    <li>{{ __('Baris :n: :message', ['n' => $item['line'], 'message' => $item['message']]) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($changes)
        <div class="card mb-4 overflow-x-auto p-0">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Baris') }}</th>
                        <th class="px-4 py-3">{{ __('Aksi') }}</th>
                        <th class="px-4 py-3">{{ __('Jabatan') }}</th>
                        <th class="px-4 py-3">{{ __('Nama tugas') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($plan['create'] as $item)
                        <tr>
                            <td class="px-4 py-2.5 tabular-nums text-slate-500">{{ $item['line'] }}</td>
                            <td class="px-4 py-2.5"><span class="pill bg-emerald-100 text-emerald-800">{{ __('Tambah') }}</span></td>
                            <td class="px-4 py-2.5">{{ $item['position'] }}</td>
                            <td class="px-4 py-2.5">{{ $item['title'] }}</td>
                        </tr>
                    @endforeach
                    @foreach ($plan['update'] as $item)
                        <tr>
                            <td class="px-4 py-2.5 tabular-nums text-slate-500">{{ $item['line'] }}</td>
                            <td class="px-4 py-2.5"><span class="pill bg-sky-100 text-sky-800">{{ __('Ubah') }}</span></td>
                            <td class="px-4 py-2.5 text-slate-400">—</td>
                            <td class="px-4 py-2.5">{{ $item['title'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <form method="POST" action="{{ route('team.catalog.import.apply') }}" data-import-form>
            @csrf
            <button class="btn-primary" @disabled($plan['errors'] || ! $changes)>{{ __('Simpan perubahan') }}</button>
        </form>
        <form method="POST" action="{{ route('team.catalog.import.cancel') }}" data-import-form>
            @csrf
            <button class="btn-secondary">{{ __('Batalkan') }}</button>
        </form>
    </div>
@endif
