<x-app-layout title="{{ __('Pengaturan') }}">
    @php
        $canPhotos = Gate::allows('manage-access');
        $canAuto = Gate::allows('manage-access') || Gate::allows('manage-team-tasks');
        $photoHelp = [
            'optional' => __('Foto boleh ditambahkan kapan saja dan tidak menghalangi checklist menjadi lengkap.'),
            'pending' => __('Tugas Pending harus punya alasan dan minimal satu foto.'),
            'all' => __('Setiap tugas harus punya minimal satu foto, apa pun statusnya.'),
        ];
        $firstTab = $canPhotos ? 'foto' : 'otomatis';
        $healthTone = [
            'ok' => 'bg-emerald-100 text-emerald-800',
            'info' => 'bg-slate-100 text-slate-600',
            'warn' => 'bg-amber-100 text-amber-800',
            'bad' => 'bg-rose-100 text-rose-800',
        ];
        $healthLabel = ['ok' => __('Baik'), 'info' => __('Info'), 'warn' => __('Perlu dicek'), 'bad' => __('Gagal')];
    @endphp

    <div class="mb-6">
        <h1 class="text-3xl text-slate-900">{{ __('Pengaturan') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('Pembuatan otomatis tiap hari, dan (Administrator) foto bukti untuk semua departemen.') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-5 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]" data-settings-tabs data-first="{{ $firstTab }}">
        <nav class="h-fit rounded-2xl border border-slate-200 bg-white p-2 shadow-sm lg:sticky lg:top-6" aria-label="{{ __('Bagian pengaturan') }}">
            @can('manage-access')
                <a href="#aturan" data-tab-link="aturan" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    {{ __('Aturan checklist') }}
                </a>
            @endcan
            @can('manage-access')
                <a href="#foto" data-tab-link="foto" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    {{ __('Foto bukti') }}
                </a>
            @endcan
            @canany(['manage-access', 'manage-team-tasks'])
                <a href="#otomatis" data-tab-link="otomatis" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    {{ __('Pembuatan otomatis') }}
                </a>
            @endcanany
            @can('manage-access')
                <a href="#kesehatan" data-tab-link="kesehatan" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    {{ __('Kesehatan sistem') }}
                </a>
            @endcan
            @can('manage-access')
                <a href="{{ route('admin.logs.index') }}" class="mt-1 flex items-center justify-between gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <span class="flex items-center gap-3">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                        {{ __('Log aplikasi') }}
                    </span>
                    <span class="text-slate-400" aria-hidden="true">›</span>
                </a>
            @endcan
        </nav>

        <div class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @can('manage-access')
                <section id="aturan" data-tab-panel class="p-6 sm:p-8">
                    <form method="POST" action="{{ route('admin.settings.rules.update') }}">
                        @csrf
                        @method('PUT')
                        <header class="pb-5">
                            <h2 class="font-display text-2xl text-slate-900">{{ __('Aturan checklist') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Atur tugas untuk hari yang akan datang selalu boleh. Aturan di bawah hanya untuk mengisi checklist.') }}</p>
                        </header>
                        <div class="grid gap-4 border-t border-slate-100 py-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                            <div>
                                <p class="font-semibold text-slate-900">{{ __('Isi checklist hari berikutnya') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Kalau aktif, HOD dan staff bisa mengisi checklist untuk hari yang akan datang. Kalau mati, isian hanya untuk hari ini dan tanggal yang sudah lewat.') }}</p>
                            </div>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3.5 text-sm transition hover:border-slate-300 has-[:checked]:border-gold-500 has-[:checked]:bg-[#fbf6ea]">
                                <input type="checkbox" name="future_fill" value="1" class="mt-1 h-4 w-4 accent-[#b08a3e]" @checked($futureFill)>
                                <span>
                                    <span class="block font-semibold text-slate-900">{{ __('Izinkan isi checklist untuk hari berikutnya') }}</span>
                                    <span class="mt-0.5 block text-slate-500">{{ __('Mati secara bawaan.') }}</span>
                                </span>
                            </label>
                        </div>
                        <div class="grid gap-4 border-t border-slate-100 py-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                            <div>
                                <p class="font-semibold text-slate-900">{{ __('Obrolan pekerjaan') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Menu Obrolan untuk membahas pekerjaan, juga dari halaman kendala dan checklist. HR dan Administrator dapat membaca semua percakapan. Kalau dimatikan, menu hilang dan tidak ada yang bisa membuka obrolan; isinya tetap tersimpan.') }}</p>
                            </div>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3.5 text-sm transition hover:border-slate-300 has-[:checked]:border-gold-500 has-[:checked]:bg-[#fbf6ea]">
                                <input type="checkbox" name="chat_enabled" value="1" class="mt-1 h-4 w-4 accent-[#b08a3e]" @checked($chatEnabled)>
                                <span>
                                    <span class="block font-semibold text-slate-900">{{ __('Aktifkan obrolan pekerjaan') }}</span>
                                    <span class="mt-0.5 block text-slate-500">{{ __('Mati secara bawaan.') }}</span>
                                </span>
                            </label>
                        </div>
                        <div class="flex justify-end border-t border-slate-100 pt-5">
                            <button class="btn-primary">{{ __('Simpan bagian ini') }}</button>
                        </div>
                    </form>
                </section>
            @endcan

            @can('manage-access')
                <section id="foto" data-tab-panel class="p-6 sm:p-8">
                    <form method="POST" action="{{ route('admin.settings.update') }}">
                        @csrf
                        @method('PUT')
                        <header class="pb-5">
                            <h2 class="font-display text-2xl text-slate-900">{{ __('Foto bukti') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Foto ditambahkan per tugas dari kamera atau galeri. Checklist dianggap lengkap (dan baru bisa direview) kalau foto yang diwajibkan sudah ada.') }}</p>
                        </header>

                        <div class="grid gap-4 border-t border-slate-100 py-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                            <div>
                                <p class="font-semibold text-slate-900">{{ __('Foto wajib') }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Berlaku untuk semua departemen.') }}</p>
                            </div>
                            <div class="space-y-2">
                                @foreach ($requirements as $value => $label)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3.5 text-sm transition hover:border-slate-300 has-[:checked]:border-gold-500 has-[:checked]:bg-[#fbf6ea]">
                                        <input type="radio" name="photo_requirement" value="{{ $value }}" class="mt-1 h-4 w-4 accent-[#b08a3e]" @checked(old('photo_requirement', $requirement) === $value)>
                                        <span>
                                            <span class="block font-semibold text-slate-900">{{ __($label) }}</span>
                                            <span class="mt-0.5 block text-slate-500">{{ $photoHelp[$value] ?? '' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid gap-4 border-t border-slate-100 py-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                            <div>
                                <label for="retention" class="font-semibold text-slate-900">{{ __('Masa simpan foto (hari)') }}</label>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Foto dihapus otomatis setelah lewat masa ini. Isian checklist tetap ada, hanya fotonya yang hilang.') }}</p>
                            </div>
                            <div>
                                <input id="retention" type="number" name="photo_retention_days" value="{{ old('photo_retention_days', $retention) }}" min="0" max="3650" required class="input w-40">
                                <p class="mt-2 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">{{ __('Isi 0 supaya foto tidak pernah dihapus (ruang server akan terus bertambah).') }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end border-t border-slate-100 pt-5">
                            <button class="btn-primary">{{ __('Simpan bagian ini') }}</button>
                        </div>
                    </form>
                </section>
            @endcan

            @can('manage-access')
                <section id="kesehatan" data-tab-panel class="p-6 sm:p-8">
                    <header class="flex flex-wrap items-start justify-between gap-4 pb-5">
                        <div>
                            <h2 class="font-display text-2xl text-slate-900">{{ __('Kesehatan sistem') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Diperiksa :time.', ['time' => $checkedAt->format('d M Y H:i')]) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            @php($problems = $healthSummary['warn'] + $healthSummary['bad'])
                            @if ($problems === 0)
                                <span class="rounded-full bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-800">{{ __('Semua pemeriksaan baik') }}</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800">{{ __(':n pemeriksaan perlu dicek', ['n' => $problems]) }}</span>
                            @endif
                            <a href="{{ route('admin.settings.edit') }}#kesehatan" class="btn-secondary">{{ __('Periksa ulang') }}</a>
                        </div>
                    </header>

                    <div class="grid gap-5 border-t border-slate-100 pt-6">
                        @foreach ($healthGroups as $group)
                            <div class="overflow-hidden rounded-2xl border border-slate-200">
                                <h3 class="border-b border-slate-100 bg-slate-50 px-5 py-3 font-display text-lg text-slate-900">{{ $group['title'] }}</h3>
                                <ul class="divide-y divide-slate-100">
                                    @foreach ($group['checks'] as $check)
                                        <li class="flex items-start gap-4 px-5 py-3.5">
                                            <span class="mt-0.5 inline-flex w-24 shrink-0 justify-center rounded-full px-3 py-1 text-xs font-semibold {{ $healthTone[$check['status']] }}">{{ $healthLabel[$check['status']] }}</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="font-medium text-slate-900">{{ $check['label'] }}</p>
                                                @if ($check['detail'])
                                                    <p class="mt-0.5 text-sm text-slate-500">{{ $check['detail'] }}</p>
                                                @endif
                                            </div>
                                            <p class="shrink-0 whitespace-nowrap text-right text-sm font-semibold tabular-nums text-slate-800">{{ $check['value'] }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endcan

            @canany(['manage-access', 'manage-team-tasks'])
                <section id="otomatis" data-tab-panel class="p-6 sm:p-8">
                    <header class="pb-5">
                        <h2 class="font-display text-2xl text-slate-900">{{ __('Pembuatan otomatis tiap hari') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Isi jam (WITA) supaya checklist dibuatkan sendiri setiap hari untuk staff yang masuk kerja menurut jadwal Absensi. Kosongkan untuk mematikan. Jadwal yang baru diisi sesudahnya bisa dibuatkan dengan tombol "Buat checklist hari ini".') }}</p>
                    </header>

                    <div class="divide-y divide-slate-100 border-t border-slate-100">
                        @forelse ($departments as $option)
                            <form method="POST" action="{{ route('fill.auto-assign', $option) }}" class="grid gap-3 py-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                @csrf
                                @method('PUT')
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $option->name }}</p>
                                    <p class="mt-0.5 text-sm text-slate-500">
                                        @if ($option->auto_assigned_on)
                                            {{ __('Terakhir dibuat :date', ['date' => $option->auto_assigned_on->translatedFormat('d M')]) }}
                                        @else
                                            {{ __('Belum pernah berjalan otomatis') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="time" name="auto_assign_at" value="{{ $option->auto_assign_at ? substr($option->auto_assign_at, 0, 5) : '' }}" class="input w-36" aria-label="{{ __('Jam otomatis :name', ['name' => $option->name]) }}">
                                    <button class="btn-secondary !px-4 !py-2">{{ __('Simpan') }}</button>
                                </div>
                            </form>
                        @empty
                            <p class="py-5 text-sm text-slate-500">{{ __('Tidak ada departemen yang bisa Anda atur.') }}</p>
                        @endforelse
                    </div>
                </section>
            @endcanany
        </div>
    </div>

    <script>
        (() => {
            const root = document.querySelector('[data-settings-tabs]');
            const links = [...root.querySelectorAll('[data-tab-link]')];
            const panels = [...root.querySelectorAll('[data-tab-panel]')];
            const activeClass = ['bg-ink', 'text-white', 'shadow-sm'];

            const show = (name) => {
                const target = panels.find((panel) => panel.id === name) ? name : root.dataset.first;
                panels.forEach((panel) => panel.classList.toggle('hidden', panel.id !== target));
                links.forEach((link) => {
                    const on = link.dataset.tabLink === target;
                    activeClass.forEach((c) => link.classList.toggle(c, on));
                    link.classList.toggle('text-slate-600', !on);
                    link.setAttribute('aria-current', on ? 'page' : 'false');
                });
                return target;
            };

            links.forEach((link) => link.addEventListener('click', (event) => {
                event.preventDefault();
                history.replaceState(null, '', '#' + link.dataset.tabLink);
                show(link.dataset.tabLink);
            }));

            show(location.hash.slice(1) || root.dataset.first);
        })();
    </script>
</x-app-layout>
