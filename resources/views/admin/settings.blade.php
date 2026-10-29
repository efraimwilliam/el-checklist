<x-app-layout title="{{ __('Pengaturan') }}">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">{{ __('Pengaturan') }}</h1>
        <p class="text-sm text-slate-500">{{ __('Pembuatan otomatis tiap hari, dan (Administrator) foto bukti untuk semua departemen.') }}</p>
    </div>

    @can('manage-access')
        <a href="{{ route('admin.logs.index') }}" class="card mb-6 flex max-w-2xl items-center justify-between gap-4 p-5 transition hover:border-gold-500">
            <span>
                <span class="block font-display text-xl text-slate-900">{{ __('Log aplikasi') }}</span>
                <span class="block text-sm text-slate-500">{{ __('Riwayat semua aktivitas: login, checklist, tugas, jabatan, akses, dan pengaturan. Hanya Administrator.') }}</span>
            </span>
            <span class="text-2xl text-gold-700" aria-hidden="true">›</span>
        </a>
    @endcan

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif

    @can('manage-access')
    <form method="POST" action="{{ route('admin.settings.update') }}" class="card max-w-2xl space-y-6 p-6">
        @csrf
        @method('PUT')

        <fieldset>
            <legend class="font-display text-xl">{{ __('Foto bukti') }}</legend>
            <p class="mb-3 text-sm text-slate-500">{{ __('Foto ditambahkan per tugas dari kamera atau galeri. Checklist dianggap lengkap (dan baru bisa direview) kalau foto yang diwajibkan sudah ada.') }}</p>
            <div class="space-y-2">
                @foreach ($requirements as $value => $label)
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm has-[:checked]:border-gold-500 has-[:checked]:bg-slate-50">
                        <input type="radio" name="photo_requirement" value="{{ $value }}" class="mt-1" @checked(old('photo_requirement', $requirement) === $value)>
                        <span>
                            <span class="block font-medium text-slate-800">{{ __($label) }}</span>
                            <span class="block text-slate-500">
                                {{ ['optional' => 'Foto boleh ditambahkan kapan saja dan tidak menghalangi checklist menjadi lengkap.', 'pending' => 'Tugas Pending harus punya alasan dan minimal satu foto.', 'all' => 'Setiap tugas harus punya minimal satu foto, apa pun statusnya.'][$value] }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label for="retention" class="label">{{ __('Masa simpan foto (hari)') }}</label>
            <input id="retention" type="number" name="photo_retention_days" value="{{ old('photo_retention_days', $retention) }}" min="0" max="3650" required class="input max-w-40">
            <p class="mt-1 text-sm text-slate-500">{{ __('Foto dihapus otomatis setelah lewat masa ini. Isian checklist tetap ada, hanya fotonya yang hilang. Isi 0 supaya foto tidak pernah dihapus (ruang server akan terus bertambah).') }}</p>
        </div>

        <div class="flex justify-end"><button class="btn-primary">{{ __('Simpan pengaturan') }}</button></div>
    </form>
    @endcan

    <div class="card mt-6 max-w-2xl p-6">
        <h2 class="font-display text-xl">{{ __('Pembuatan otomatis tiap hari') }}</h2>
        <p class="mb-3 mt-1 text-sm text-slate-500">{{ __('Isi jam (WITA) supaya checklist dibuatkan sendiri setiap hari untuk staff yang masuk kerja menurut jadwal Absensi. Kosongkan untuk mematikan. Jadwal yang baru diisi sesudahnya bisa disusul dengan tombol "Buat checklist" di halaman Isi checklist.') }}</p>
        <div class="divide-y divide-slate-100">
            @forelse ($departments as $option)
                <form method="POST" action="{{ route('fill.auto-assign', $option) }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    @csrf
                    @method('PUT')
                    <span class="font-medium text-slate-700">{{ $option->name }}@if ($option->auto_assigned_on) <span class="font-normal text-slate-500">· terakhir {{ $option->auto_assigned_on->translatedFormat('d M') }}</span>@endif</span>
                    <span class="flex items-center gap-2">
                        <input type="time" name="auto_assign_at" value="{{ $option->auto_assign_at ? substr($option->auto_assign_at, 0, 5) : '' }}" class="input !w-32" aria-label="Jam otomatis {{ $option->name }}">
                        <button class="btn-secondary !px-4 !py-1.5">{{ __('Simpan') }}</button>
                    </span>
                </form>
            @empty
                <p class="py-3 text-sm text-slate-500">{{ __('Tidak ada departemen yang bisa Anda atur.') }}</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
