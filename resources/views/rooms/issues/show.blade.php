<x-app-layout title="{{ $issue->placeTitle() }}">
    @php
        $tones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800'];
        $labels = \App\Models\RoomIssue::statusLabels();
        $kindTones = ['catatan' => 'bg-slate-300', 'selesai' => 'bg-emerald-500', 'ambil' => 'bg-sky-500', 'lepas' => 'bg-slate-400', 'tunda' => 'bg-amber-500', 'laporan' => 'bg-rose-500', 'berulang' => 'bg-amber-400'];
    @endphp

    <a href="{{ route('kamar.issues.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Semua kendala') }}</a>

    <div class="mb-5 mt-1 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-3xl text-slate-900">{{ $issue->placeTitle() }}</h1>
            <p class="text-sm text-slate-500">
                @if ($issue->room?->floor){{ __('Lantai') }} {{ $issue->room->floor }} · @endif{{ $issue->room?->type ? $issue->room->type.' · ' : '' }}{{ $issue->room_id ? '' : __('Lokasi lain').' · ' }}{{ $issue->category?->name ?? __('Tanpa kategori') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('kamar.issues.chronology', $issue) }}" class="btn-secondary !px-4 !py-1.5 text-sm">{{ __('Cetak riwayat') }}</a>
            <a href="{{ route('chronology.create', ['kendala' => $issue->id]) }}" class="btn-secondary !px-4 !py-1.5 text-sm">{{ __('Buat Chronology Report') }}</a>
            @if ($issue->recurrence > 1)<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">{{ __('Berulang ke-:n', ['n' => $issue->recurrence]) }}</span>@endif
            @if ($issue->priority === 'mendesak')<span class="rounded-full bg-rose-600 px-3 py-1 text-xs font-semibold text-white">{{ __('Mendesak') }}</span>@endif
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$issue->status] }}">{{ $labels[$issue->status] }}</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    <section class="card mb-6 p-5">
        <p class="text-slate-900">{{ $issue->description }}</p>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">{{ __('Sumber') }}</dt><dd class="font-medium text-slate-800">{{ $sources[$issue->source] ?? $issue->source }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Dicatat oleh') }}</dt><dd class="font-medium text-slate-800">{{ $issue->reporter?->employee?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Dicatat') }}</dt><dd class="font-medium text-slate-800">{{ $issue->created_at->translatedFormat('d M Y H:i') }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Diteruskan ke') }} <span class="text-xs">({{ __($issue->targetKind()) }})</span></dt><dd class="font-medium text-slate-800">{{ $issue->targetLabel() }}</dd></div>
        </dl>
        @if ($issue->isDone() && $issue->completed_at)
            <p class="mt-4 text-sm text-emerald-800">{{ __('Selesai') }} {{ $issue->completed_at->translatedFormat('d M Y H:i') }}
                · {{ __('dalam') }} {{ $issue->created_at->diffForHumans($issue->completed_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</p>
        @endif
    </section>

    {{-- Status pekerjaan di paling atas: yang mengambil langsung mengisinya. --}}
    @can('work', $issue)
        <section class="card mb-6 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Status pekerjaan') }}</h2>
            @if ($issue->claimer)
                <p class="mt-1 text-sm text-slate-500"><span class="font-semibold text-slate-700">{{ $issue->claimer->name() }}</span> {{ __('sedang mengerjakan kendala ini') }}@if ($issue->claimed_at) {{ __('sejak') }} {{ $issue->claimed_at->translatedFormat('d M Y H:i') }}@endif.</p>
            @endif
            <form method="POST" action="{{ route('kamar.issues.update', $issue) }}" enctype="multipart/form-data" class="mt-3 space-y-4" data-work-form>
                @csrf
                {{-- Tampilan sama dengan kartu checklist: tombol status bertumpuk (Done, Ongoing, Pending), lalu Kamera, Galeri, Catatan. Memakai kelas .cl-* yang sama. --}}
                <div data-view="card" class="space-y-4">
                    <fieldset>
                        <legend class="sr-only">{{ __('Status') }}</legend>
                        <div class="cl-seg">
                            @foreach (['done' => 'Done', 'ongoing' => 'Ongoing', 'pending' => 'Pending'] as $value => $label)
                                <label>
                                    <input type="radio" name="status" value="{{ $value }}" class="sr-only" @checked(old('status', $issue->status === 'tertunda' ? 'pending' : 'ongoing') === $value) required>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="cl-extras">
                        <div class="cl-actions">
                            <button type="button" class="cl-action" data-photo-pick="camera" aria-label="{{ __('Ambil foto dengan kamera') }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                <span>{{ __('Kamera') }}</span>
                            </button>
                            <button type="button" class="cl-action" data-photo-pick="gallery" aria-label="{{ __('Pilih foto dari galeri') }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m21 16-5-5-8 8"/></svg>
                                <span>{{ __('Galeri') }}</span>
                            </button>
                            <button type="button" class="cl-action {{ filled(old('body')) ? 'has-note' : '' }}" data-note-toggle aria-pressed="{{ old('body') ? 'true' : 'false' }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17l-1 3Z"/><path d="m14 7 3 3"/></svg>
                                <span>{{ __('Catatan') }}</span>
                            </button>
                        </div>
                        <input type="file" accept="image/*" capture="environment" class="hidden" data-photo-input="camera" tabindex="-1">
                        <input type="file" accept="image/*" multiple class="hidden" data-photo-input="gallery" tabindex="-1">
                        <input type="file" name="photos[]" multiple class="hidden" data-photo-files tabindex="-1">
                        <div class="cl-thumbs" data-photo-thumbs aria-live="polite"></div>
                        <div class="{{ old('body') ? '' : 'hidden' }}" data-note-box>
                            <label for="work-body" class="label">{{ __('Catatan pekerjaan') }} <span class="font-normal text-slate-400">{{ __('(wajib bila Pending)') }}</span></label>
                            <textarea id="work-body" name="body" rows="3" maxlength="2000" class="input w-full" placeholder="{{ __('Apa yang sudah dikerjakan di kamar ini, atau alasan Pending. mis. freon diisi, menunggu suku cadang') }}">{{ old('body') }}</textarea>
                        </div>
                    </div>
                </div>
                {{-- Simpan: tombol utama selebar kartu. Lepas jarang dipakai dan berisiko, jadi dibuat tenang di bawahnya (formulir terpisah lewat atribut form). --}}
                <div class="space-y-1 pt-1">
                    <button class="btn-primary w-full">{{ __('Simpan') }}</button>
                    @can('release', $issue)
                        <button type="submit" form="release-form" class="w-full rounded-full px-4 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-rose-50 hover:text-rose-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-gold-500" data-confirm-button="{{ __('Lepas pengambilan? Rekan lain bisa mengambil kendala ini.') }}">{{ __('Lepas pengambilan') }}</button>
                    @endcan
                </div>
            </form>
            @can('release', $issue)
                <form id="release-form" method="POST" action="{{ route('kamar.issues.release', $issue) }}" class="hidden">@csrf</form>
            @endcan
        </section>
        <script>
            (() => {
                const form = document.querySelector('[data-work-form]');
                if (!form) return;
                const MAX = {{ \App\Rooms\RoomIssuePhotoService::MAX_PER_UPDATE }};
                const EDGE = 1280;
                const store = form.querySelector('[data-photo-files]');
                const thumbs = form.querySelector('[data-photo-thumbs]');
                const noteBox = form.querySelector('[data-note-box]');
                const noteButton = form.querySelector('[data-note-toggle]');
                const body = form.querySelector('#work-body');
                const pickers = [...form.querySelectorAll('[data-photo-pick]')];
                const removeLabel = @json(__('Hapus foto'));
                let files = [];

                // Foto dikecilkan di perangkat (sisi terpanjang 1280 px, JPEG) supaya unggahan ringan; server mengolah ulang lagi.
                const compress = (file) => new Promise((resolve) => {
                    const url = URL.createObjectURL(file);
                    const image = new Image();
                    image.onload = () => {
                        const scale = Math.min(1, EDGE / Math.max(image.width, image.height));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.round(image.width * scale);
                        canvas.height = Math.round(image.height * scale);
                        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
                        URL.revokeObjectURL(url);
                        canvas.toBlob((blob) => resolve(blob ? new File([blob], 'foto.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.8);
                    };
                    image.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
                    image.src = url;
                });

                const render = () => {
                    const transfer = new DataTransfer();
                    files.forEach((file) => transfer.items.add(file));
                    store.files = transfer.files;
                    thumbs.replaceChildren(...files.map((file, index) => {
                        const wrap = document.createElement('div');
                        wrap.className = 'cl-photo';
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        img.alt = '';
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.setAttribute('aria-label', removeLabel);
                        remove.textContent = '×';
                        remove.addEventListener('click', () => { files.splice(index, 1); render(); });
                        wrap.append(img, remove);
                        return wrap;
                    }));
                    pickers.forEach((button) => button.classList.toggle('hidden', files.length >= MAX));
                };

                const add = async (list) => {
                    for (const file of list) {
                        if (files.length >= MAX) break;
                        if (file.type.startsWith('image/')) files.push(await compress(file));
                    }
                    render();
                };

                pickers.forEach((button) => button.addEventListener('click', () => form.querySelector(`[data-photo-input="${button.dataset.photoPick}"]`).click()));
                form.querySelectorAll('[data-photo-input]').forEach((input) => input.addEventListener('change', async () => { await add([...input.files]); input.value = ''; }));

                noteButton.addEventListener('click', () => {
                    noteBox.classList.toggle('hidden');
                    noteButton.setAttribute('aria-pressed', String(!noteBox.classList.contains('hidden')));
                    if (!noteBox.classList.contains('hidden')) body.focus();
                });
                body.addEventListener('input', () => noteButton.classList.toggle('has-note', body.value.trim() !== ''));

                // Pending wajib beralasan: kolom catatan terbuka otomatis.
                const syncStatus = () => {
                    const pending = form.querySelector('input[name="status"]:checked')?.value === 'pending';
                    body.required = pending;
                    if (pending) { noteBox.classList.remove('hidden'); noteButton.setAttribute('aria-pressed', 'true'); }
                };
                form.querySelectorAll('input[name="status"]').forEach((radio) => radio.addEventListener('change', syncStatus));
                syncStatus();
            })();
        </script>
    @endcan

    {{-- Pengambil: satu orang per kendala, supaya tidak ada dua pegawai yang mengerjakan hal yang sama. --}}
    @if (! $issue->isDone())
        @if ($issue->claimer)
            @cannot('work', $issue)
                <section class="card mb-6 border-sky-200 bg-sky-50/50 p-5">
                    <p class="text-sm text-sky-900"><span class="font-semibold">{{ $issue->claimer->name() }}</span> {{ __('sedang mengerjakan kendala ini') }}@if ($issue->claimed_at) {{ __('sejak') }} {{ $issue->claimed_at->translatedFormat('d M Y H:i') }}@endif.</p>
                </section>
            @endcannot
        @else
            @can('claim', $issue)
                <section class="card mb-6 flex flex-wrap items-center justify-between gap-3 p-5">
                    <p class="text-sm text-slate-700">{{ __('Belum ada yang mengambil kendala ini. Ambil bila Anda yang akan mengerjakannya, supaya rekan lain tahu.') }}</p>
                    <form method="POST" action="{{ route('kamar.issues.claim', $issue) }}">
                        @csrf
                        <button class="btn-primary" data-confirm-button="{{ __('Anda yang akan mengerjakan kendala di :place ini? Rekan lain akan melihat nama Anda.', ['place' => $issue->placeTitle()]) }}">{{ __('Saya ambil') }}</button>
                    </form>
                </section>
            @endcan
        @endif
    @endif

    <section class="card mb-6 overflow-hidden">
        <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ __('Pekerjaan yang sudah dilakukan') }}</h2>
        <ol class="divide-y divide-slate-100">
            @forelse ($issue->updates as $update)
                <li class="flex gap-3 p-5">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $kindTones[$update->kind] ?? 'bg-slate-300' }}" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-800">{{ $update->body }}@if ($update->related_issue_id && isset($related[$update->related_issue_id]))@can('view', $related[$update->related_issue_id]) <a class="underline" href="{{ route('kamar.issues.show', $update->related_issue_id) }}">{{ __('Lihat') }}</a>@endcan @endif</p>
                        @if ($update->photos->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2">@foreach ($update->photos as $photo)<a href="{{ route('kamar.issues.photo', [$issue, $photo]) }}" target="_blank" rel="noopener"><img src="{{ route('kamar.issues.photo', [$issue, $photo]) }}" alt="{{ __('Foto pekerjaan') }}" loading="lazy" class="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200"></a>@endforeach</div>
                        @endif
                        <p class="mt-0.5 text-xs text-slate-500">{{ $update->user?->employee?->name ?? __('Pengguna dihapus') }}@if ($update->department) · {{ $update->department->name }}@endif · {{ $update->created_at->translatedFormat('d M Y H:i') }}</p>
                    </div>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('Belum ada pekerjaan yang dicatat.') }}</li>
            @endforelse
        </ol>
    </section>

    {{-- Track record kamar, dipisah supaya tidak tercampur. Dilihat sebelum mengambil kendala; setelah diambil, pengambil fokus ke pekerjaannya. --}}
    @if ($issue->claimed_by !== auth()->id())
        <section class="card mb-6 overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-display text-xl text-slate-900">{{ __('Track record :place', ['place' => $issue->placeTitle()]) }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Cek dulu apakah masalah ini pernah terjadi dan apa yang sudah dikerjakan, sebelum mengambil.') }}</p>
                </div>
                <a href="{{ $issue->room_id ? route('kamar.room', $issue->room) : route('kamar.place', ['nama' => $issue->location]) }}" class="btn-secondary">{{ __('Seluruh track record') }}</a>
            </div>

            <div class="border-b border-slate-100 p-5">
                <p class="text-sm font-semibold text-slate-800">{{ __('Kendala sejenis') }}{{ $issue->category ? ' · '.$issue->category->name : '' }} <span class="font-normal text-slate-500">({{ $record['same']->count() }})</span></p>
                @if ($record['same']->isNotEmpty())
                    <ul class="mt-2 space-y-2">@foreach ($record['same'] as $past) @include('rooms.partials.record-item', ['past' => $past]) @endforeach</ul>
                @else
                    <p class="mt-2 text-sm text-slate-500">{{ __('Belum pernah ada kendala sejenis di lokasi ini.') }}</p>
                @endif
            </div>

            @if ($record['ownDepartment']->isNotEmpty())
                <details class="border-b border-slate-100 p-5 last:border-0">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">{{ __('Pekerjaan departemen Anda di lokasi ini') }} <span class="font-normal text-slate-500">({{ $record['ownDepartment']->count() }}, {{ __('kategori lain') }})</span></summary>
                    <ul class="mt-2 space-y-2">@foreach ($record['ownDepartment'] as $past) @include('rooms.partials.record-item', ['past' => $past]) @endforeach</ul>
                </details>
            @endif
            @if ($record['others']->isNotEmpty())
                <details class="p-5">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">{{ __('Kendala departemen lain') }} <span class="font-normal text-slate-500">({{ $record['others']->count() }})</span></summary>
                    <ul class="mt-2 space-y-2">@foreach ($record['others'] as $past) @include('rooms.partials.record-item', ['past' => $past]) @endforeach</ul>
                </details>
            @endif
        </section>
    @else
        {{-- Setelah diambil: hanya track record yang dikerjakan departemen ini (mis. Engineering), tersembunyi sampai dibuka. --}}
        <details class="card group mb-6 overflow-hidden">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-5">
                <span class="font-display text-xl text-slate-900">{{ __('Track record :place', ['place' => $issue->placeTitle()]) }}
                    <span class="text-sm font-normal text-slate-500">({{ $record['department']->count() }})</span></span>
                <span class="text-slate-400 transition group-open:rotate-180" aria-hidden="true">▾</span>
            </summary>
            <div class="border-t border-slate-100 p-5">
                @if ($record['department']->isNotEmpty())
                    <ul class="space-y-2">@foreach ($record['department'] as $past) @include('rooms.partials.record-item', ['past' => $past]) @endforeach</ul>
                @else
                    <p class="text-sm text-slate-500">{{ __('Belum ada track record sebelumnya di lokasi ini.') }}</p>
                @endif
                <a class="mt-3 inline-block text-sm text-sky-700 hover:underline" href="{{ $issue->room_id ? route('kamar.room', ['room' => $issue->room_id, 'department_id' => $issue->assigned_department_id]) : route('kamar.place', ['nama' => $issue->location, 'department_id' => $issue->assigned_department_id]) }}">{{ __('Lihat semua di halaman lokasi') }} &rarr;</a>
            </div>
        </details>
    @endif

    @can('reopen', $issue)
        <form method="POST" action="{{ route('kamar.issues.reopen', $issue) }}" class="mb-6">
            @csrf
            <button class="btn-secondary">{{ __('Buka kembali') }}</button>
        </form>
    @endcan
</x-app-layout>
