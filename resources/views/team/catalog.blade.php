<x-app-layout title="{{ __('Kamus daftar tugas') }}">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-3xl text-slate-900">{{ __('Kamus daftar tugas') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Semua tugas aktif tiap jabatan. Urutkan dengan mengetuk judul kolom.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('manage-tasks')
                <a href="{{ route('admin.tasks.index') }}" class="btn-secondary">{{ __('Ubah atau urutkan tugas') }}</a>
            @endcan
            @can('manage-team-tasks')
                <a href="{{ route('team.catalog.export') }}" class="btn-secondary !w-11 !px-0" aria-label="{{ __('Unduh Excel') }}" title="{{ __('Unduh Excel') }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                </a>
                {{-- Membuka popup impor di halaman ini; tautan tetap ke halaman penuh bila JavaScript mati. --}}
                <a href="{{ route('team.catalog.import') }}" class="btn-secondary !w-11 !px-0" aria-label="{{ __('Impor Excel') }}" title="{{ __('Impor Excel') }}" data-import-open>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                </a>
            @endcan
            @if ($catalog->flatMap->positions->isNotEmpty())
                <button type="button" class="btn-primary" data-open-task-dialog>{{ __('+ Tambah tugas') }}</button>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 p-4">
            <div class="mb-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="catalog-department" class="label">{{ __('Departemen') }}</label>
                    <select id="catalog-department" class="input" data-dept-select>
                        <option value="">{{ __('Semua departemen') }}</option>
                        @foreach ($catalog->filter(fn ($department) => $department->positions->isNotEmpty()) as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="catalog-position" class="label">{{ __('Jabatan') }}</label>
                    <select id="catalog-position" class="input" data-position-select>
                        <option value="">{{ __('Semua jabatan') }}</option>
                        @foreach ($catalog as $department)
                            @foreach ($department->positions as $option)
                                <option value="{{ $option->id }}" data-department="{{ $department->id }}">{{ $option->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
            </div>
            <label for="catalog-search" class="label">{{ __('Cari tugas') }}</label>
            <input id="catalog-search" type="search" placeholder="{{ __('Nama tugas, jabatan, jenis, atau departemen') }}" class="input" data-catalog-search>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr>
                        @foreach (['department' => __('Departemen'), 'position' => __('Jabatan'), 'section' => __('Jenis'), 'title' => __('Nama tugas')] as $key => $label)
                            <th class="px-4 py-3" data-sort-th="{{ $key }}" aria-sort="{{ $key === 'department' ? 'ascending' : 'none' }}">
                                <button type="button" class="inline-flex items-center gap-1.5 hover:text-slate-900" data-sort="{{ $key }}">
                                    {{ $label }}
                                    <span class="text-[10px] {{ $key === 'department' ? 'text-gold-700' : 'text-slate-300' }}" aria-hidden="true" data-sort-icon>{{ $key === 'department' ? '▲' : '↕' }}</span>
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3">{{ __('Standar') }}</th>
                        <th class="px-4 py-3"><span class="sr-only">{{ __('Aksi') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" data-catalog-body>
                    @foreach ($tasks as $task)
                        <tr data-catalog-row
                            data-department="{{ $task->position->department->name }}"
                            data-department-id="{{ $task->position->department_id }}"
                            data-position="{{ $task->position->name }}"
                            data-position-id="{{ $task->position_id }}"
                            data-section="{{ $task->section ?? '' }}"
                            data-title="{{ $task->title }}"
                            data-search="{{ mb_strtolower(implode(' ', array_filter([$task->position->department->name, $task->position->name, $task->section, $task->title, $task->standard]))) }}">
                            <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $task->position->department->name }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-800">{{ $task->position->name }}</td>
                            <td class="px-4 py-2.5 text-slate-500">{{ $task->section ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-900">{{ $task->title }}</td>
                            <td class="max-w-xs px-4 py-2.5 text-slate-500">{{ $task->standard ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[#f6efdf] hover:text-gold-700"
                                    data-edit-task data-id="{{ $task->id }}" data-url="{{ route('team.catalog.tasks.update', $task) }}"
                                    data-title="{{ $task->title }}" data-section="{{ $task->section }}" data-standard="{{ $task->standard }}" data-position="{{ $task->position->name }}" data-position-id="{{ $task->position_id }}" data-parameters="{{ json_encode($task->parameters->map(fn ($p) => $p->only(['name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option']))->values()) }}"
                                    aria-label="{{ __('Ubah tugas') }}" title="{{ __('Ubah tugas') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </button>
                                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-rose-50 hover:text-rose-700"
                                    data-delete-task data-url="{{ route('team.catalog.tasks.destroy', $task) }}" data-title="{{ $task->title }}" data-position="{{ $task->position->name }}"
                                    aria-label="{{ __('Hapus tugas') }}" title="{{ __('Hapus tugas') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <tr class="hidden" data-catalog-empty><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('Tidak ada tugas yang cocok dengan pencarian.') }}</td></tr>
                </tbody>
            </table>
            @if ($tasks->isEmpty())
                <p class="px-4 py-10 text-center text-slate-500">{{ __('Belum ada tugas.') }}</p>
            @endif
        </div>

        {{-- Baris per halaman, keterangan halaman, dan Sebelumnya / Berikutnya (diisi browser, tanpa memuat ulang). --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label class="flex items-center gap-2" for="catalog-per-page">
                    {{ __('Tampilkan') }}
                    <select id="catalog-per-page" class="input !w-20 !py-1.5" data-per-page>
                        @foreach ([10, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($option === 25)>{{ $option }}</option>
                        @endforeach
                    </select>
                    {{ __('baris') }}
                </label>
                <p data-catalog-info></p>
            </div>
            <nav class="flex items-center gap-1.5" aria-label="{{ __('Halaman') }}" data-catalog-pager></nav>
        </div>
    </div>

    @if ($catalog->flatMap->positions->isNotEmpty())
        <dialog id="task-dialog" aria-labelledby="task-dialog-title" class="m-auto w-[min(42rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
            <form method="POST" action="{{ route('team.tasks.store') }}" class="space-y-4 p-6">
                @csrf
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="task-dialog-title" class="font-display text-2xl text-slate-900">{{ __('Tambah tugas') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('Tugas baru masuk ke checklist yang dibuat sesudahnya.') }}</p>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup') }}" data-close-task-dialog>{{ __('×') }}</button>
                </div>

                <div>
                    <label for="dialog-position" class="label">{{ __('Jabatan') }}</label>
                    <select id="dialog-position" name="position" required class="input">
                        <option value="">{{ __('Pilih jabatan') }}</option>
                        @foreach ($catalog as $department)
                            @if ($department->positions->isNotEmpty())
                                <optgroup label="{{ $department->name }}">
                                    @foreach ($department->positions as $option)
                                        <option value="{{ $option->id }}" @selected((string) old('position') === (string) $option->id)>{{ $option->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="dialog-title" class="label">{{ __('Nama tugas') }}</label>
                    <input id="dialog-title" name="title" value="{{ old('title') }}" required maxlength="500" class="input" placeholder="{{ __('Contoh: Cek minibar kamar tamu') }}" autofocus>
                </div>

                <div>
                    <label for="dialog-section" class="label">{{ __('Jenis') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                    <input id="dialog-section" name="section" list="task-sections" value="{{ old('section') }}" maxlength="255" class="input" placeholder="{{ __('Contoh: Operasional') }}">
                </div>

                <div>
                    <label for="dialog-standard" class="label">{{ __('Standar') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                    <textarea id="dialog-standard" name="standard" rows="6" maxlength="2000" class="input min-h-40 leading-relaxed" placeholder="{{ __('Cara atau standar yang diharapkan, misalnya langkah dan batas waktu') }}">{{ old('standard') }}</textarea>
                </div>

                <div>
                    <p class="label">{{ __('Parameter isian') }}</p>
                    @include('team.partials.parameter-editor', ['rows' => old('parameters') ?? [['name' => 'Status', 'type' => 'status']], 'prefix' => 'dialog'])
                </div>

                @if ($errors->any())
                    <p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>
                @endif

                <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary" data-close-task-dialog>{{ __('Batal') }}</button>
                    <button class="btn-primary">{{ __('Simpan tugas') }}</button>
                </div>
            </form>
        </dialog>

        <datalist id="task-sections">
            @foreach ($sections as $existing)
                <option value="{{ $existing }}">
            @endforeach
        </datalist>

        <dialog id="edit-task-dialog" aria-labelledby="edit-task-title" class="m-auto w-[min(42rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
            <form method="POST" id="edit-task-form" action="" class="space-y-4 p-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="editing" id="edit-task-id" value="{{ old('editing') }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="edit-task-title" class="font-display text-2xl text-slate-900">{{ __('Ubah tugas') }}</h2>
                        <p class="text-sm text-slate-500" id="edit-task-position"></p>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup') }}" data-close-edit-dialog>{{ __('×') }}</button>
                </div>

                <div>
                    <label for="edit-position" class="label">{{ __('Jabatan') }}</label>
                    <select id="edit-position" name="position" required class="input">
                        @foreach ($catalog as $department)
                            @if ($department->positions->isNotEmpty())
                                <optgroup label="{{ $department->name }}">
                                    @foreach ($department->positions as $option)
                                        <option value="{{ $option->id }}" @selected((string) ($errors->editTask->any() ? old('position') : '') === (string) $option->id)>{{ $option->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="edit-title" class="label">{{ __('Nama tugas') }}</label>
                    <input id="edit-title" name="title" value="{{ $errors->editTask->any() ? old('title') : '' }}" required maxlength="500" class="input" autofocus>
                </div>
                <div>
                    <label for="edit-section" class="label">{{ __('Jenis') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                    <input id="edit-section" name="section" list="task-sections" value="{{ $errors->editTask->any() ? old('section') : '' }}" maxlength="255" class="input">
                </div>

                <div>
                    <label for="edit-standard" class="label">{{ __('Standar') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                    <textarea id="edit-standard" name="standard" rows="6" maxlength="2000" class="input min-h-40 leading-relaxed">{{ $errors->editTask->any() ? old('standard') : '' }}</textarea>
                </div>

                <div>
                    <p class="label">{{ __('Parameter isian') }}</p>
                    @include('team.partials.parameter-editor', ['rows' => $errors->editTask->any() ? (old('parameters') ?? []) : [], 'prefix' => 'edit'])
                </div>

                @if ($errors->editTask->any())
                    <p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->editTask->first() }}</p>
                @endif

                <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary" data-close-edit-dialog>{{ __('Batal') }}</button>
                    <button class="btn-primary">{{ __('Simpan perubahan') }}</button>
                </div>
            </form>
        </dialog>

        <dialog id="delete-task-dialog" aria-labelledby="delete-task-title" class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
            <form method="POST" id="delete-task-form" action="" class="space-y-4 p-6">
                @csrf
                @method('DELETE')
                <h2 id="delete-task-title" class="font-display text-2xl text-slate-900">{{ __('Hapus tugas?') }}</h2>
                <p class="text-sm text-slate-600">"<span id="delete-task-name" class="font-semibold text-slate-900"></span>{{ __('" di') }} <span id="delete-task-position"></span> {{ __('akan dihapus dari daftar. Checklist yang sudah dibuat tidak berubah.') }}</p>
                <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary" data-close-delete-dialog autofocus>{{ __('Batal') }}</button>
                    <button class="btn-danger">{{ __('Hapus tugas') }}</button>
                </div>
            </form>
        </dialog>
    @endif

    <script>
        (() => {
            // Tabel: sortir, cari, dan halaman dikerjakan di browser (semua tugas sudah dimuat).
            const body = document.querySelector('[data-catalog-body]');
            const rows = [...body.querySelectorAll('[data-catalog-row]')];
            const search = document.querySelector('[data-catalog-search]');
            const perPage = document.querySelector('[data-per-page]');
            const info = document.querySelector('[data-catalog-info]');
            const pager = document.querySelector('[data-catalog-pager]');
            const empty = document.querySelector('[data-catalog-empty]');
            const headers = [...document.querySelectorAll('[data-sort-th]')];
            const deptSelect = document.querySelector('[data-dept-select]');
            const positionSelect = document.querySelector('[data-position-select]');
            const positionOptions = [...positionSelect.querySelectorAll('option[data-department]')];
            let department = '';
            let position = '';
            let sortKey = 'department';
            let direction = 1;
            let page = 1;

            const compare = (a, b) => a.dataset[sortKey].localeCompare(b.dataset[sortKey], 'id', { numeric: true, sensitivity: 'base' }) * direction;

            const pageButton = (label, target, { current = false, disabled = false } = {}) => {
                if (current) {
                    const span = document.createElement('span');
                    span.textContent = label;
                    span.setAttribute('aria-current', 'page');
                    span.className = 'flex h-9 min-w-9 items-center justify-center rounded-full bg-ink px-3 font-semibold text-white';
                    return span;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:border-gold-500 disabled:border-slate-200 disabled:text-slate-300';
                button.addEventListener('click', () => {
                    page = target;
                    render();
                });
                return button;
            };

            const render = () => {
                const query = search.value.trim().toLowerCase();
                const matched = rows.filter((row) => (department === '' || row.dataset.departmentId === department) && (position === '' || row.dataset.positionId === position) && (query === '' || row.dataset.search.includes(query)));
                const size = Number(perPage.value);
                const pages = Math.max(1, Math.ceil(matched.length / size));
                page = Math.min(page, pages);
                const from = (page - 1) * size;
                const shown = new Set(matched.slice(from, from + size));

                body.append(...rows); // urutan tampilan mengikuti sortir
                rows.forEach((row) => { row.hidden = !shown.has(row); });
                empty.classList.toggle('hidden', matched.length > 0 || rows.length === 0);

                const first = matched.length ? from + 1 : 0;
                const last = Math.min(from + size, matched.length);
                info.innerHTML = `${first}–${last} ${window.t('dari')} <strong class="text-slate-900">${matched.length}</strong> ${window.t('tugas')} · ${window.t('Halaman')} <strong class="text-slate-900">${page}</strong> {{ __('dari') }} <strong class="text-slate-900">${pages}</strong>`;

                pager.replaceChildren(pageButton('← Sebelumnya', page - 1, { disabled: page === 1 }));
                for (let n = Math.max(1, page - 2); n <= Math.min(pages, page + 2); n++) {
                    pager.append(pageButton(String(n), n, { current: n === page }));
                }
                pager.append(pageButton('Berikutnya →', page + 1, { disabled: page === pages }));

                headers.forEach((th) => {
                    const icon = th.querySelector('[data-sort-icon]');
                    const active = th.dataset.sortTh === sortKey;
                    th.setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                    icon.textContent = active ? (direction === 1 ? '▲' : '▼') : '↕';
                    icon.className = `text-[10px] ${active ? 'text-gold-700' : 'text-slate-300'}`;
                });
            };

            // Jabatan mengikuti departemen yang dipilih; ganti departemen mengosongkan pilihan jabatan yang tidak cocok.
            deptSelect.addEventListener('change', () => {
                department = deptSelect.value;
                positionOptions.forEach((option) => { option.hidden = department !== '' && option.dataset.department !== department; });
                if (position !== '' && positionSelect.querySelector(`option[value="${position}"]`)?.hidden) position = '';
                positionSelect.value = position;
                page = 1;
                render();
            });
            positionSelect.addEventListener('change', () => { position = positionSelect.value; page = 1; render(); });
            search.addEventListener('input', () => { page = 1; render(); });
            perPage.addEventListener('change', () => { page = 1; render(); });
            headers.forEach((th) => th.querySelector('[data-sort]').addEventListener('click', () => {
                const key = th.dataset.sortTh;
                direction = sortKey === key ? -direction : 1;
                sortKey = key;
                rows.sort(compare);
                page = 1;
                render();
            }));

            render();
        })();
    </script>

    @if ($catalog->flatMap->positions->isNotEmpty())
        <script>
            (() => {
                const addDialog = document.getElementById('task-dialog');
                const editDialog = document.getElementById('edit-task-dialog');
                const deleteDialog = document.getElementById('delete-task-dialog');
                const editForm = document.getElementById('edit-task-form');
                const deleteForm = document.getElementById('delete-task-form');

                const openEdit = (button, keepOld) => {
                    editForm.action = button.dataset.url;
                    document.getElementById('edit-task-id').value = button.dataset.id;
                    document.getElementById('edit-task-position').textContent = button.dataset.position;
                    if (!keepOld) {
                        document.getElementById('edit-position').value = button.dataset.positionId;
                        document.getElementById('edit-title').value = button.dataset.title;
                        document.getElementById('edit-section').value = button.dataset.section;
                        document.getElementById('edit-standard').value = button.dataset.standard;
                        document.getElementById('edit-editor').paramEditor.setRows(JSON.parse(button.dataset.parameters || '[]'));
                    }
                    editDialog.showModal();
                };

                document.querySelectorAll('[data-open-task-dialog]').forEach((button) => button.addEventListener('click', () => addDialog.showModal()));
                addDialog.querySelectorAll('[data-close-task-dialog]').forEach((button) => button.addEventListener('click', () => addDialog.close()));

                document.querySelectorAll('[data-edit-task]').forEach((button) => button.addEventListener('click', () => openEdit(button, false)));
                editDialog.querySelectorAll('[data-close-edit-dialog]').forEach((button) => button.addEventListener('click', () => editDialog.close()));

                document.querySelectorAll('[data-delete-task]').forEach((button) => button.addEventListener('click', () => {
                    deleteForm.action = button.dataset.url;
                    document.getElementById('delete-task-name').textContent = button.dataset.title;
                    document.getElementById('delete-task-position').textContent = button.dataset.position;
                    deleteDialog.showModal();
                }));
                deleteDialog.querySelectorAll('[data-close-delete-dialog]').forEach((button) => button.addEventListener('click', () => deleteDialog.close()));

                @if ($errors->any())
                    addDialog.showModal();
                @elseif ($errors->editTask->any() && old('editing'))
                    openEdit(document.querySelector('[data-edit-task][data-id="{{ old('editing') }}"]'), true);
                @endif
            })();
        </script>
    @endif

    @can('manage-team-tasks')
        <dialog id="import-dialog" aria-labelledby="import-dialog-title" class="m-auto w-[min(56rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
            <div class="flex max-h-[92vh] flex-col">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-6 pb-4 pt-6">
                    <div>
                        <h2 id="import-dialog-title" class="font-display text-2xl text-slate-900">{{ __('Impor kamus daftar tugas') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('Unduh daftar dari Excel, ubah isinya, lalu unggah lagi. Periksa dulu sebelum disimpan.') }}</p>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup') }}" data-close-import-dialog>{{ __('×') }}</button>
                </div>
                <div class="overflow-y-auto px-6 pb-6 pt-4" data-import-body aria-live="polite">
                    <p class="text-sm text-slate-500">{{ __('Memuat…') }}</p>
                </div>
            </div>
        </dialog>

        <script>
            (() => {
                const dialog = document.getElementById('import-dialog');
                const body = dialog.querySelector('[data-import-body]');
                const panelUrl = @json(route('team.catalog.import'));

                // Setiap langkah (buka, periksa berkas, simpan, batalkan) menjawab JSON: isi panel baru, atau alamat tujuan sesudah tersimpan.
                const load = async (url, options = {}) => {
                    body.setAttribute('aria-busy', 'true');
                    body.querySelectorAll('button').forEach((button) => (button.disabled = true));
                    try {
                        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, ...options });
                        if (response.status === 401 || response.status === 419) return window.location.reload();
                        const data = await response.json();
                        if (data.redirect) return (window.location.href = data.redirect);
                        body.innerHTML = data.html;
                    } catch (error) {
                        body.innerHTML = `<p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">${window.t('Gagal memuat. Coba lagi.')}</p>`;
                    } finally {
                        body.removeAttribute('aria-busy');
                    }
                };

                document.querySelectorAll('[data-import-open]').forEach((trigger) => trigger.addEventListener('click', (event) => {
                    event.preventDefault();
                    dialog.showModal();
                    load(panelUrl);
                }));
                dialog.querySelectorAll('[data-close-import-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));
                dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });

                body.addEventListener('submit', (event) => {
                    const form = event.target.closest('[data-import-form]');
                    if (!form) return;
                    event.preventDefault();
                    load(form.action, { method: 'POST', body: new FormData(form) });
                });
            })();
        </script>
    @endcan
</x-app-layout>
