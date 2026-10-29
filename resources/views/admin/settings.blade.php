<x-app-layout title="Pengaturan">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">Pengaturan</h1>
        <p class="text-sm text-slate-500">Berlaku untuk semua departemen.</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}" class="card max-w-2xl space-y-6 p-6">
        @csrf
        @method('PUT')

        <fieldset>
            <legend class="font-display text-xl">Foto bukti</legend>
            <p class="mb-3 text-sm text-slate-500">Foto ditambahkan per tugas dari kamera atau galeri. Checklist dianggap lengkap (dan baru bisa direview) kalau foto yang diwajibkan sudah ada.</p>
            <div class="space-y-2">
                @foreach ($requirements as $value => $label)
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm has-[:checked]:border-gold-500 has-[:checked]:bg-slate-50">
                        <input type="radio" name="photo_requirement" value="{{ $value }}" class="mt-1" @checked(old('photo_requirement', $requirement) === $value)>
                        <span>
                            <span class="block font-medium text-slate-800">{{ $label }}</span>
                            <span class="block text-slate-500">
                                {{ ['optional' => 'Foto boleh ditambahkan kapan saja dan tidak menghalangi checklist menjadi lengkap.', 'pending' => 'Tugas Pending harus punya alasan dan minimal satu foto.', 'all' => 'Setiap tugas harus punya minimal satu foto, apa pun statusnya.'][$value] }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label for="retention" class="label">Masa simpan foto (hari)</label>
            <input id="retention" type="number" name="photo_retention_days" value="{{ old('photo_retention_days', $retention) }}" min="0" max="3650" required class="input max-w-40">
            <p class="mt-1 text-sm text-slate-500">Foto dihapus otomatis setelah lewat masa ini. Isian checklist tetap ada, hanya fotonya yang hilang. Isi 0 supaya foto tidak pernah dihapus (ruang server akan terus bertambah).</p>
        </div>

        <div class="flex justify-end"><button class="btn-primary">Simpan pengaturan</button></div>
    </form>
</x-app-layout>
