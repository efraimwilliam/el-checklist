<x-tugas-page tab="pegawai" :title="$member->name">
    @php
        $total = $groups->sum(fn ($group) => $group->tasks->count());
        $chosen = count($selected);
        $initials = collect(explode(' ', $member->name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    @endphp

    <a href="{{ route('team.index') }}" class="link-back"><span aria-hidden="true">‹</span> {{ __('Pegawai') }}</a>
    @can('manage-tasks')
        <a href="{{ route('team.account.edit', $member) }}" class="ml-4 text-sm font-semibold text-sky-700 hover:underline">{{ __('Ubah akun Sistem Absensi') }}</a>
    @endcan

    {{-- Kartu pegawai: jabatan checklist diatur di sini juga (disimpan bersama tugas lewat formulir di bawah, atribut form). --}}
    <div class="card mb-4 mt-2 p-5">
        <div class="flex flex-wrap items-center gap-4">
            <span class="hod-avatar !h-14 !w-14 !text-lg" aria-hidden="true">{{ $initials }}</span>
            <div class="min-w-0 flex-1">
                <h2 class="text-section">{{ $member->name }}</h2>
                <p class="text-sm text-slate-500">{{ $member->department->name }}</p>
                <p class="text-xs text-slate-500">{{ __('Absensi') }}: {{ $member->position_title ?? __('belum diisi') }}</p>
            </div>
            <div class="max-sm:flex max-sm:basis-full max-sm:items-center max-sm:gap-3 sm:text-right">
                @if ($member->custom_tasks)
                    <span class="pill bg-sky-100 text-sky-800">{{ __('Disesuaikan') }}</span>
                @else
                    <span class="pill-neutral">{{ __('Tugas bawaan') }}</span>
                @endif
                <p class="text-sm text-slate-600 sm:mt-1"><strong class="tabular-nums text-slate-900" data-chosen>{{ $chosen }}</strong> {{ __('dari :n tugas dipilih', ['n' => $total]) }}</p>
            </div>
        </div>

        <div class="mt-4 border-t border-slate-100 pt-4">
            <label for="member-position" class="label">{{ __('Jabatan checklist') }}</label>
            <select id="member-position" name="position" form="team-tasks" class="input w-full sm:max-w-sm" data-position-select
                    @error('position') aria-invalid="true" aria-describedby="member-position-error" @enderror>
                <option value="">{{ __('— Belum diatur —') }}</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected((int) old('position', $member->checklist_position_id) === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
            @error('position') <p id="member-position-error" class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
            <p class="mt-1 text-[13px] text-slate-500">{{ __('Dipakai saat checklist pegawai ini dibuatkan sekaligus. Tugas dari jabatan ini tampil paling atas.') }}</p>

            @if ($review)
                <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    @if ($review['type'] === 'title')
                        {{ __('Perlu dicek: jabatan di Absensi berubah dari ":from" menjadi ":to".', ['from' => $review['from'], 'to' => $review['to'] ?? __('(kosong)')]) }}
                    @else
                        {{ __('Perlu dicek: jabatan checklist saat ini (:position) bukan dari departemen pegawai ini.', ['position' => $review['position']]) }}
                    @endif
                </p>
                @if ($member->checklist_position_id && $groups->contains('id', $member->checklist_position_id))
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="confirm_position" value="1" form="team-tasks"> {{ __('Jabatan checklist tetap benar') }}
                    </label>
                @endif
            @endif
            @if ($suggested)
                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-amber-800">
                    <span>{{ __('Saran dari Absensi: :name', ['name' => $suggested->name]) }}</span>
                    <button type="button" class="btn-secondary btn-sm" data-use-suggestion="{{ $suggested->id }}">{{ __('Pakai saran') }}</button>
                </div>
            @endif
        </div>
    </div>

    <p class="mb-4 text-sm text-slate-600">
        {{ $member->custom_tasks ? __('Tugas sudah disesuaikan: hanya tugas yang dicentang yang masuk checklist pegawai ini.') : __('Tugas bawaan: semua tugas departemen tercentang. Hapus centang untuk menyesuaikan.') }}
        {{ __('Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.') }}
    </p>

    @error('tasks') <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <div class="card mb-4 p-4">
        <label for="task-search" class="label">{{ __('Cari tugas') }}</label>
        <div class="flex flex-wrap items-center gap-3">
            <input id="task-search" type="search" placeholder="{{ __('Ketik nama tugas') }}" class="input min-w-0 flex-1" data-task-search>
            <button type="button" class="btn-secondary shrink-0" data-groups-toggle>{{ __('Buka semua') }}</button>
        </div>
    </div>

    <form id="team-tasks" method="POST" action="{{ route('team.tasks.update', $member) }}" class="pb-24">
        @csrf
        @method('PUT')

        @forelse ($groups as $group)
            {{-- Kartu per jabatan tertutup; klik judulnya untuk membuka daftar tugas. Isian tetap ikut tersimpan walau tertutup. --}}
            <details class="group card mb-4 overflow-hidden" data-group data-position-id="{{ $group->id }}" @if ($group->id === $member->checklist_position_id) open @endif>
                <summary class="flex cursor-pointer list-none select-none items-center justify-between gap-3 border-slate-100 bg-gold-50 px-4 py-3 group-open:border-b [&::-webkit-details-marker]:hidden">
                    <h2 class="text-section">{{ $group->name }} <span class="ml-1 align-middle text-xs font-semibold uppercase tracking-[0.08em] text-gold-700" data-own-position @if ($group->id !== $member->checklist_position_id) hidden @endif>{{ __('Jabatan checklist') }}</span></h2>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-sm tabular-nums text-slate-500" data-group-count></span>
                        <svg class="h-5 w-5 text-slate-500 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </summary>

                @foreach ($group->tasks->groupBy(fn ($task) => $task->section ?: __('Tanpa jenis')) as $section => $tasks)
                    <div class="px-4 pb-2 pt-3" data-section>
                        <div class="flex items-center justify-between gap-3">
                            <p class="eyebrow !text-[11px]">{{ $section }}</p>
                            <button type="button" class="text-sm font-semibold text-sky-700 hover:underline" data-section-toggle>{{ __('Pilih semua') }}</button>
                        </div>
                        <ul class="mt-1 divide-y divide-slate-100">
                            @foreach ($tasks as $task)
                                <li data-task data-title="{{ \Illuminate\Support\Str::lower($task->title) }}">
                                    <label class="flex cursor-pointer items-center gap-3 py-3">
                                        <input type="checkbox" name="tasks[]" value="{{ $task->id }}" class="h-5 w-5 shrink-0 rounded border-slate-300 accent-gold-600" @checked(in_array($task->id, $selected))>
                                        <span class="text-base text-slate-800">{{ $task->title }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </details>
        @empty
            <p class="card p-6 text-sm text-slate-500">{{ __('Jabatan di departemen ini belum punya tugas aktif.') }}</p>
        @endforelse

        <div data-bottom-bar class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3">
                <p class="text-sm text-slate-600"><strong class="tabular-nums text-slate-900" data-chosen>{{ $chosen }}</strong> {{ __('dari :n tugas dipilih', ['n' => $total]) }}</p>
                <div class="flex gap-2">
                    <a href="{{ route('team.index') }}" class="btn-secondary">{{ __('Batal') }}</a>
                    <button class="btn-primary">{{ __('Simpan') }}</button>
                </div>
            </div>
        </div>
    </form>

    @if ($member->custom_tasks)
        <form method="POST" action="{{ route('team.tasks.reset', $member) }}" class="mt-4 text-right">
            @csrf
            @method('DELETE')
            <button class="text-sm text-slate-600 underline hover:text-rose-700" data-confirm-button="Kembalikan ke semua tugas jabatan?">{{ __('Kembalikan ke bawaan jabatan') }}</button>
        </form>
    @endif

    <script>
        (() => {
            const form = document.getElementById('team-tasks');
            const search = document.querySelector('[data-task-search]');
            const boxesIn = (node) => [...node.querySelectorAll('input[name="tasks[]"]')];
            const wasOpen = new WeakMap();

            const refresh = () => {
                const all = boxesIn(form);
                const chosen = all.filter((box) => box.checked).length;
                document.querySelectorAll('[data-chosen]').forEach((node) => (node.textContent = chosen));
                form.querySelectorAll('[data-group]').forEach((group) => {
                    const boxes = boxesIn(group);
                    const picked = boxes.filter((box) => box.checked).length;
                    const count = group.querySelector('[data-group-count]');
                    count.textContent = `${picked} / ${boxes.length} dipilih`;
                    count.classList.toggle('font-semibold', picked > 0);
                    count.classList.toggle('text-slate-900', picked > 0);
                    count.classList.toggle('text-slate-500', picked === 0);
                });
                form.querySelectorAll('[data-section]').forEach((section) => {
                    const boxes = boxesIn(section);
                    section.querySelector('[data-section-toggle]').textContent = boxes.every((box) => box.checked) ? window.t('Kosongkan') : window.t('Pilih semua');
                });
            };

            form.addEventListener('change', refresh);
            form.addEventListener('click', (event) => {
                const toggle = event.target.closest('[data-section-toggle]');
                if (!toggle) return;
                const boxes = boxesIn(toggle.closest('[data-section]'));
                const allChecked = boxes.every((box) => box.checked);
                boxes.forEach((box) => (box.checked = !allChecked));
                refresh();
            });

            // Pencarian hanya menyembunyikan baris; pilihan yang tersembunyi tetap ikut tersimpan.
            search?.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                form.querySelectorAll('[data-task]').forEach((row) => {
                    row.hidden = query !== '' && !row.dataset.title.includes(query);
                });
                form.querySelectorAll('[data-section]').forEach((section) => {
                    section.hidden = [...section.querySelectorAll('[data-task]')].every((row) => row.hidden);
                });
                // Saat mencari, kartu yang cocok terbuka sendiri; setelah pencarian dikosongkan, kembali seperti semula.
                form.querySelectorAll('[data-group]').forEach((group) => {
                    group.hidden = [...group.querySelectorAll('[data-section]')].every((section) => section.hidden);
                    if (query !== '') {
                        if (!wasOpen.has(group)) wasOpen.set(group, group.open);
                        group.open = !group.hidden;
                    } else if (wasOpen.has(group)) {
                        group.open = wasOpen.get(group);
                        wasOpen.delete(group);
                    }
                });
            });

            // Buka semua / Tutup semua untuk kartu yang sedang tampil; teksnya mengikuti keadaan kartu.
            const toggleAll = document.querySelector('[data-groups-toggle]');
            const visibleGroups = () => [...form.querySelectorAll('[data-group]')].filter((group) => !group.hidden);
            const syncToggleAll = () => {
                if (toggleAll) toggleAll.textContent = window.t(visibleGroups().every((group) => group.open) ? 'Tutup semua' : 'Buka semua');
            };
            toggleAll?.addEventListener('click', () => {
                const open = visibleGroups().some((group) => !group.open);
                visibleGroups().forEach((group) => (group.open = open));
                syncToggleAll();
            });
            form.addEventListener('toggle', syncToggleAll, true);

            // Jabatan checklist diganti: kartu jabatan itu naik ke atas dan terbuka, supaya tugasnya langsung terlihat.
            const positionSelect = document.querySelector('[data-position-select]');
            const showPosition = () => {
                const group = form.querySelector(`[data-group][data-position-id="${positionSelect.value}"]`);
                form.querySelectorAll('[data-own-position]').forEach((badge) => (badge.hidden = badge.closest('[data-group]') !== group));
                if (!group) return;
                form.querySelector('[data-group]')?.before(group);
                group.open = true;
                syncToggleAll();
            };
            positionSelect?.addEventListener('change', showPosition);
            document.querySelectorAll('[data-use-suggestion]').forEach((button) => button.addEventListener('click', () => {
                positionSelect.value = button.dataset.useSuggestion;
                showPosition();
            }));

            refresh();
            syncToggleAll();
        })();
    </script>
</x-tugas-page>
