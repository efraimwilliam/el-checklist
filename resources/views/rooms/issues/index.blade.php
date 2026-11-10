<x-app-layout title="{{ __('Kendala') }}">
    @php
        $statusLabels = ['open' => __('Belum selesai'), 'all' => __('Semua'), ...\App\Models\RoomIssue::statusLabels()];
        $tones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800'];
        $labels = \App\Models\RoomIssue::statusLabels();
        $me = auth()->user();
        $placeType = old('place_type', $rooms->isEmpty() ? 'location' : 'room');
        $targetType = old('target_type', 'department');
        $segment = 'cursor-pointer rounded-full px-4 py-1.5 text-sm font-medium text-slate-600 transition has-checked:bg-white has-checked:text-slate-900 has-checked:shadow-sm has-focus-visible:ring-2 has-focus-visible:ring-gold-500';
    @endphp

    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl text-slate-900">{{ __('Kendala') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Catat kendala di kamar atau lokasi lain, teruskan ke departemen, jabatan, atau pegawai yang menangani, lalu catat pekerjaannya sampai selesai.') }}</p>
        </div>
        <button type="button" class="btn-primary" data-open-issue-dialog>{{ __('+ Catat kendala') }}</button>
    </div>

    @if ($newForMe && $status !== 'baru')
        <a href="{{ route('kamar.issues.index', ['status' => 'baru']) }}" class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 transition hover:bg-rose-100">
            <span class="text-sm text-rose-800"><span class="font-display text-2xl tabular-nums">{{ $newForMe }}</span> {{ __('kendala baru menunggu Anda') }}</span>
            <span class="text-sm font-semibold text-rose-800">{{ __('Lihat yang baru') }} &rarr;</span>
        </a>
    @endif

    {{-- Penyaring untuk atasan (HOD, HR, GM, Administrator). Pegawai (Staff) hanya mengisi kendala yang menjadi tugasnya, jadi tidak perlu. --}}
    @if ($me->role !== \App\Enums\Role::Staff)
    <form method="GET" action="{{ route('kamar.issues.index') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto] lg:items-end">
        <div>
            <label for="f-status" class="label">{{ __('Status') }}</label>
            <select id="f-status" name="status" class="input w-full" onchange="this.form.submit()">
                @foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-department" class="label">{{ __('Departemen penanggung jawab') }}</label>
            <select id="f-department" name="department_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-category" class="label">{{ __('Kategori') }}</label>
            <select id="f-category" name="category_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-search" class="label">{{ __('Lokasi') }}</label>
            <input id="f-search" type="search" name="q" value="{{ $search }}" maxlength="30" class="input w-full" placeholder="{{ __('mis. 305 atau Lobby') }}">
        </div>
        <button class="btn-primary">{{ __('Cari') }}</button>
    </form>
    @endif

    <section class="card mb-6 overflow-hidden">
        @forelse ($issues as $issue)
            @php
                $forMe = $issue->status === 'baru' && $issue->isForUser($me);
                $forMeLabel = $issue->assigned_employee_id !== null && $issue->assigned_employee_id === $me->employee_id ? __('Untuk Anda') : ($issue->assigned_position_id !== null && $issue->assigned_position_id === $me->employee?->checklist_position_id ? __('Untuk jabatan Anda') : __('Untuk departemen Anda'));
            @endphp
            <a href="{{ route('kamar.issues.show', $issue) }}" class="flex flex-col gap-2 border-b border-slate-100 p-5 transition last:border-0 hover:bg-amber-50/50 sm:flex-row sm:items-start sm:justify-between {{ $forMe ? 'border-l-4 border-l-rose-500 bg-rose-50/40' : '' }}">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="font-display text-xl text-slate-900">{{ $issue->placeTitle() }}</span>
                        @if ($issue->room?->floor)<span class="text-sm text-slate-500">{{ __('Lantai') }} {{ $issue->room->floor }}</span>@endif
                        @if (! $issue->room_id)<span class="rounded-full bg-sky-50 px-2.5 py-0.5 text-xs text-sky-800">{{ __('Lokasi lain') }}</span>@endif
                        @if ($issue->category)<span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700">{{ $issue->category->name }}</span>@endif
                        @if ($issue->recurrence > 1)<span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-900">{{ __('Berulang ke-:n', ['n' => $issue->recurrence]) }}</span>@endif
                        @if ($issue->priority === 'mendesak')<span class="rounded-full bg-rose-600 px-2.5 py-0.5 text-xs font-semibold text-white">{{ __('Mendesak') }}</span>@endif
                        @if ($forMe)<span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">{{ $forMeLabel }}</span>@endif
                    </p>
                    <p class="mt-1 text-sm text-slate-700">{{ \Illuminate\Support\Str::limit($issue->description, 140) }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $issue->created_at->translatedFormat('d M Y H:i') }} · {{ $issue->reporter?->employee?->name ?? __('Pengguna dihapus') }}
                        · {{ $issue->targetLabel() }}
                        @if ($issue->claimer) · <span class="font-medium text-sky-800">{{ __('Diambil') }} {{ $issue->claimer->name() }}</span>@elseif (! $issue->isDone()) · {{ __('Belum diambil') }}@endif
                    </p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$issue->status] }}">{{ $labels[$issue->status] }}</span>
            </a>
        @empty
            <div class="p-10 text-center">
                <p class="font-medium text-slate-800">{{ __('Tidak ada kendala.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ ($search || $departmentId || $categoryId || $status !== 'open') ? __('Tidak ada yang cocok dengan filter ini. Ubah filter untuk melihat yang lain.') : __('Belum ada kendala yang belum selesai.') }}</p>
            </div>
        @endforelse

        @if ($issues->hasPages())
            <div class="flex items-center justify-between border-t border-slate-100 p-4 text-sm">
                <span class="text-slate-500">{{ __('Halaman') }} {{ $issues->currentPage() }} / {{ $issues->lastPage() }}</span>
                <span class="flex gap-2">
                    @if ($issues->previousPageUrl())<a class="btn-secondary" href="{{ $issues->previousPageUrl() }}">&larr; {{ __('Sebelumnya') }}</a>@endif
                    @if ($issues->hasMorePages())<a class="btn-secondary" href="{{ $issues->nextPageUrl() }}">{{ __('Berikutnya') }} &rarr;</a>@endif
                </span>
            </div>
        @endif
    </section>

    <dialog id="issue-dialog" aria-labelledby="issue-dialog-title" class="m-auto max-h-[calc(100dvh-2rem)] w-[min(40rem,calc(100vw-2rem))] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="{{ route('kamar.issues.store') }}" class="space-y-5 p-6">
            @csrf
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="issue-dialog-title" class="font-display text-2xl text-slate-900">{{ __('Catat kendala') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Pilih lokasi, kategori, ke siapa diteruskan, lalu tulis kendalanya.') }}</p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-issue-dialog>×</button>
            </div>

            {{-- Lokasi: kamar (pilih nomor) atau lokasi lain (pilih saran atau ketik sendiri). --}}
            <fieldset class="space-y-3">
                <legend class="label">{{ __('Lokasi') }}</legend>
                <div class="inline-flex rounded-full bg-[#f6efdf] p-1" role="group">
                    <label class="{{ $segment }}"><input type="radio" name="place_type" value="room" class="sr-only" @checked($placeType === 'room') @disabled($rooms->isEmpty())> {{ __('Kamar') }}</label>
                    <label class="{{ $segment }}"><input type="radio" name="place_type" value="location" class="sr-only" @checked($placeType === 'location')> {{ __('Lokasi lain') }}</label>
                </div>
                <div data-place="room">
                    <label for="issue-room-input" class="sr-only">{{ __('Kamar') }}</label>
                    {{-- Ketik nomor kamar atau pilih dari daftar; nomor yang cocok mengisi room_id. --}}
                    <input id="issue-room-input" list="issue-room-list" autocomplete="off" inputmode="numeric" required class="input w-full" placeholder="{{ __('Ketik atau pilih kamar') }}" value="{{ $rooms->firstWhere('id', (int) old('room_id'))?->number }}" data-room-input>
                    <input type="hidden" name="room_id" value="{{ old('room_id') }}" data-room-id>
                    <datalist id="issue-room-list">
                        @foreach ($rooms as $room)<option value="{{ $room->number }}">{{ ($room->floor ? __('Lantai').' '.$room->floor : '').($room->type ? ' · '.$room->type : '') }}</option>@endforeach
                    </datalist>
                    <p class="mt-1 hidden text-xs text-slate-500" data-room-hint></p>
                </div>
                <div data-place="location">
                    <label for="issue-location" class="sr-only">{{ __('Lokasi lain') }}</label>
                    <input id="issue-location" name="location" list="issue-location-list" autocomplete="off" required maxlength="120" class="input w-full" placeholder="{{ __('Pilih atau ketik lokasi, mis. Lobby') }}" value="{{ old('location') }}" data-location-input>
                    <datalist id="issue-location-list">
                        @foreach ($locations as $name)<option value="{{ $name }}"></option>@endforeach
                    </datalist>
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="issue-category" class="label">{{ __('Kategori') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                    <select id="issue-category" name="category_id" class="input w-full">
                        <option value="">{{ __('Pilih kategori') }}</option>
                        @foreach ($categories as $category)<option value="{{ $category->id }}" data-department="{{ $category->department_id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="issue-source" class="label">{{ __('Sumber laporan') }}</label>
                    <select id="issue-source" name="source" class="input w-full">
                        @foreach ($sources as $value => $label)<option value="{{ $value }}" @selected(old('source', 'tamu') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>

            {{-- Diteruskan ke: satu departemen (bawaan dari kategori), satu jabatan, atau satu pegawai. --}}
            <fieldset class="space-y-3">
                <legend class="label">{{ __('Diteruskan ke') }}</legend>
                <div class="inline-flex rounded-full bg-[#f6efdf] p-1" role="group">
                    <label class="{{ $segment }}"><input type="radio" name="target_type" value="department" class="sr-only" @checked($targetType === 'department')> {{ __('Departemen') }}</label>
                    <label class="{{ $segment }}"><input type="radio" name="target_type" value="position" class="sr-only" @checked($targetType === 'position')> {{ __('Jabatan') }}</label>
                    <label class="{{ $segment }}"><input type="radio" name="target_type" value="employee" class="sr-only" @checked($targetType === 'employee')> {{ __('Pegawai') }}</label>
                </div>
                <div data-target="department">
                    <label for="issue-department" class="sr-only">{{ __('Departemen') }}</label>
                    <select id="issue-department" name="assigned_department_id" class="input w-full">
                        <option value="">{{ __('Sesuai kategori') }}</option>
                        @foreach ($departments as $department)<option value="{{ $department->id }}" @selected((string) old('assigned_department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Semua pegawai departemen itu bisa mengambilnya.') }}</p>
                </div>
                <div data-target="position">
                    <label for="issue-position" class="sr-only">{{ __('Jabatan') }}</label>
                    <select id="issue-position" name="assigned_position_id" class="input w-full" required>
                        <option value="">{{ __('Pilih jabatan') }}</option>
                        @foreach ($positions->groupBy(fn ($p) => $p->department->name) as $departmentName => $group)
                            <optgroup label="{{ $departmentName }}">
                                @foreach ($group as $position)<option value="{{ $position->id }}" @selected((string) old('assigned_position_id') === (string) $position->id)>{{ $position->name }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Pegawai dengan jabatan checklist itu yang bisa mengambilnya.') }}</p>
                </div>
                <div data-target="employee">
                    <label for="issue-employee" class="sr-only">{{ __('Pegawai') }}</label>
                    <select id="issue-employee" name="assigned_employee_id" class="input w-full" required>
                        <option value="">{{ __('Pilih pegawai') }}</option>
                        @foreach ($employees->groupBy(fn ($e) => $e->department->name) as $departmentName => $group)
                            <optgroup label="{{ $departmentName }}">
                                @foreach ($group as $employee)<option value="{{ $employee->id }}" @selected((string) old('assigned_employee_id') === (string) $employee->id)>{{ $employee->name }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Hanya pegawai ini (dan atasannya) yang bisa mengambilnya.') }}</p>
                </div>
            </fieldset>

            {{-- Kendala serupa yang belum selesai: tambahkan laporan ke kendala itu (disarankan) atau buat kendala baru. --}}
            <input type="hidden" name="merge_into" value="{{ old('merge_into') }}" data-merge-into>
            <input type="hidden" name="force_new" value="{{ old('force_new', 0) }}" data-force-new>
            <div class="hidden rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm" data-duplicate role="status">
                <p class="font-semibold text-amber-900">{{ __('Sudah ada kendala serupa yang belum selesai') }}</p>
                <p class="mt-1 text-amber-900" data-duplicate-text></p>
                <div class="mt-3 space-y-2">
                    <label class="flex cursor-pointer items-start gap-2 text-slate-800"><input type="radio" name="duplicate_choice" value="merge" class="mt-1" checked data-duplicate-choice> <span><span class="font-medium">{{ __('Tambahkan laporan ke kendala itu') }}</span> <span class="text-slate-500">({{ __('disarankan, tidak ada kendala ganda') }})</span></span></label>
                    <label class="flex cursor-pointer items-start gap-2 text-slate-800"><input type="radio" name="duplicate_choice" value="new" class="mt-1" data-duplicate-choice> <span class="font-medium">{{ __('Buat kendala baru') }}</span></label>
                </div>
            </div>

            <div>
                <label for="issue-description" class="label">{{ __('Kendala') }}</label>
                <textarea id="issue-description" name="description" rows="4" required maxlength="2000" class="input w-full" placeholder="{{ __('Contoh: AC tidak dingin, tamu minta segera dicek') }}">{{ old('description') }}</textarea>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="hidden" name="priority" value="biasa">
                <input type="checkbox" name="priority" value="mendesak" @checked(old('priority') === 'mendesak')> {{ __('Tandai mendesak') }}
            </label>

            @if ($errors->any() && ! $errors->has('duplicate'))
                <p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>
            @endif

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-issue-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary" data-issue-submit>{{ __('Simpan kendala') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const dialog = document.getElementById('issue-dialog');
            const form = dialog.querySelector('form');
            const category = document.getElementById('issue-category');
            const department = document.getElementById('issue-department');
            const roomInput = document.querySelector('[data-room-input]');
            const roomId = document.querySelector('[data-room-id]');
            const roomHint = document.querySelector('[data-room-hint]');
            const locationInput = document.querySelector('[data-location-input]');
            const placeRadios = [...form.querySelectorAll('[name="place_type"]')];
            const targetRadios = [...form.querySelectorAll('[name="target_type"]')];
            const roomMap = @json($rooms->mapWithKeys(fn ($room) => [mb_strtolower($room->number) => ['id' => $room->id, 'hint' => trim(($room->floor ? __('Lantai').' '.$room->floor : '').($room->type ? ' · '.$room->type : ''))]]));
            const checked = (radios, fallback) => radios.find((radio) => radio.checked)?.value ?? fallback;
            const currentPlace = () => checked(placeRadios, 'location');
            const currentTarget = () => checked(targetRadios, 'department');

            // Hanya blok lokasi / tujuan yang dipilih yang aktif; yang lain dinonaktifkan supaya tidak ikut terkirim.
            const showOnly = (selector, key, value) => form.querySelectorAll(selector).forEach((block) => {
                const on = block.dataset[key] === value;
                block.classList.toggle('hidden', !on);
                block.querySelectorAll('input, select').forEach((field) => { field.disabled = !on; });
            });

            // Nomor yang diketik dicocokkan ke daftar kamar; kalau tidak ada, form menolak dengan pesan yang jelas.
            const syncRoom = () => {
                const match = roomMap[roomInput.value.trim().toLowerCase()];
                roomId.value = match ? match.id : '';
                roomHint.textContent = match ? match.hint : '';
                roomHint.classList.toggle('hidden', !match || !match.hint);
                roomInput.setCustomValidity(match || roomInput.value.trim() === '' ? '' : @json(__('Kamar tidak ada di daftar. Pilih dari daftar yang muncul.')));
            };
            roomInput.addEventListener('input', syncRoom);
            roomInput.addEventListener('change', syncRoom);

            // Cek kendala serupa (lokasi + kategori sama, belum selesai) setiap lokasi atau kategori berubah.
            const duplicateBox = document.querySelector('[data-duplicate]');
            const duplicateText = document.querySelector('[data-duplicate-text]');
            const mergeInto = document.querySelector('[data-merge-into]');
            const forceNew = document.querySelector('[data-force-new]');
            const submit = document.querySelector('[data-issue-submit]');
            const choices = [...document.querySelectorAll('[data-duplicate-choice]')];
            const labels = { save: @json(__('Simpan kendala')), merge: @json(__('Tambahkan laporan')), status: @json(__('status')), by: @json(__('diambil oleh')), unclaimed: @json(__('belum diambil')) };
            let duplicateId = null;
            let lastKey = '';
            let timer = null;

            const placeQuery = () => {
                if (currentPlace() === 'room') return roomId.value ? `room_id=${roomId.value}` : '';
                const name = locationInput.value.trim();
                return name ? `location=${encodeURIComponent(name)}` : '';
            };
            const applyChoice = () => {
                const merge = duplicateId !== null && choices.find((choice) => choice.checked)?.value === 'merge';
                mergeInto.value = merge ? duplicateId : '';
                forceNew.value = duplicateId !== null && !merge ? '1' : '0';
                submit.textContent = merge ? labels.merge : labels.save;
            };
            const checkDuplicate = async () => {
                const place = placeQuery();
                const key = `${place}|${category.value}`;
                if (key === lastKey) return;
                lastKey = key;
                duplicateId = null;
                duplicateBox.classList.add('hidden');
                if (!place || !category.value) { applyChoice(); return; }
                try {
                    const response = await fetch(@json(route('kamar.issues.duplicate')) + `?${place}&category_id=${category.value}`, { headers: { Accept: 'application/json' } });
                    const data = response.ok ? await response.json() : { found: false };
                    if (key !== lastKey) return; // pilihan sudah berubah lagi
                    if (data.found) {
                        duplicateId = data.id;
                        duplicateText.textContent = `${data.room} · ${data.category} · ${labels.status} ${data.status} · ${data.claimer ? labels.by + ' ' + data.claimer : labels.unclaimed} · ${data.since}. "${data.description}"`;
                        choices.forEach((choice) => { choice.checked = choice.value === (forceNew.value === '1' ? 'new' : 'merge'); });
                        duplicateBox.classList.remove('hidden');
                    }
                } catch (error) { /* tanpa pengecekan, server tetap meminta pilihan bila ada kendala serupa */ }
                applyChoice();
            };
            const checkDuplicateSoon = () => { clearTimeout(timer); timer = setTimeout(checkDuplicate, 350); };

            choices.forEach((choice) => choice.addEventListener('change', applyChoice));
            category.addEventListener('change', checkDuplicate);
            roomInput.addEventListener('input', checkDuplicateSoon);
            roomInput.addEventListener('change', checkDuplicate);
            locationInput.addEventListener('input', checkDuplicateSoon);
            locationInput.addEventListener('change', checkDuplicate);

            const applyPlace = () => { showOnly('[data-place]', 'place', currentPlace()); checkDuplicate(); };
            const applyTarget = () => showOnly('[data-target]', 'target', currentTarget());
            placeRadios.forEach((radio) => radio.addEventListener('change', applyPlace));
            targetRadios.forEach((radio) => radio.addEventListener('change', applyTarget));

            // Kategori mengisi departemen penanggung jawab bawaannya (bila diteruskan ke departemen); tetap bisa diganti.
            category.addEventListener('change', () => {
                const target = category.selectedOptions[0]?.dataset.department;
                if (target && currentTarget() === 'department') department.value = target;
            });

            document.querySelectorAll('[data-open-issue-dialog]').forEach((button) => button.addEventListener('click', () => dialog.showModal()));
            dialog.querySelectorAll('[data-close-issue-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));

            syncRoom();
            applyPlace();
            applyTarget();
            @if ($errors->any()) dialog.showModal(); @endif
        })();
    </script>
</x-app-layout>
