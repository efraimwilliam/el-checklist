<x-app-layout :title="'Tugas '.$member->name">
    @php
        $total = $groups->sum(fn ($group) => $group->tasks->count());
        $chosen = count($selected);
        $initials = collect(explode(' ', $member->name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    @endphp

    <a href="{{ route('team.index') }}" class="text-sm font-semibold text-sky-700 hover:underline">← Atur tugas</a>

    <div class="card mb-4 mt-2 flex flex-wrap items-center gap-4 p-5">
        <span class="hod-avatar !h-14 !w-14 !text-lg" aria-hidden="true">{{ $initials }}</span>
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl leading-tight text-slate-900 sm:text-3xl">{{ $member->name }}</h1>
            <p class="text-sm text-slate-500">{{ $member->department->name }} · {{ $member->position_title ?? 'Jabatan di Absensi belum diisi' }}</p>
        </div>
        <div class="text-right">
            @if ($member->custom_tasks)
                <span class="pill bg-sky-100 text-sky-800">Disesuaikan</span>
            @else
                <span class="pill-neutral">Bawaan jabatan</span>
            @endif
            <p class="mt-1 text-sm text-slate-600"><strong class="tabular-nums text-slate-900">{{ $chosen }}</strong> dari {{ $total }} tugas dipilih</p>
        </div>
    </div>

    <p class="mb-4 text-sm text-slate-600">
        {{ $member->custom_tasks ? 'Tugas sudah disesuaikan: hanya tugas yang dicentang yang masuk checklist pegawai ini.' : 'Memakai semua tugas jabatan yang dipilih saat mengisi.' }}
        Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.
    </p>

    @error('tasks') <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <div class="card mb-4 p-4">
        <label for="task-search" class="label">Cari tugas</label>
        <input id="task-search" type="search" placeholder="Ketik nama tugas" class="input" data-task-search>
    </div>

    <form id="team-tasks" method="POST" action="{{ route('team.tasks.update', $member) }}" class="pb-24">
        @csrf
        @method('PUT')

        @forelse ($groups as $group)
            <section class="card mb-4 overflow-hidden" data-group>
                <header class="flex items-center justify-between gap-3 border-b border-slate-100 bg-[#fbf6ea] px-4 py-3">
                    <h2 class="font-display text-xl text-slate-900">{{ $group->name }}</h2>
                    <span class="text-sm tabular-nums text-slate-500" data-group-count></span>
                </header>

                @foreach ($group->tasks->groupBy(fn ($task) => $task->section ?: 'Tanpa jenis') as $section => $tasks)
                    <div class="px-4 pb-2 pt-3" data-section>
                        <div class="flex items-center justify-between gap-3">
                            <p class="eyebrow !text-[11px]">{{ $section }}</p>
                            <button type="button" class="text-sm font-semibold text-sky-700 hover:underline" data-section-toggle>Pilih semua</button>
                        </div>
                        <ul class="mt-1 divide-y divide-slate-100">
                            @foreach ($tasks as $task)
                                <li data-task data-title="{{ \Illuminate\Support\Str::lower($task->title) }}">
                                    <label class="flex cursor-pointer items-center gap-3 py-3">
                                        <input type="checkbox" name="tasks[]" value="{{ $task->id }}" class="h-5 w-5 shrink-0 rounded border-slate-300 accent-[#b08a3e]" @checked(in_array($task->id, $selected))>
                                        <span class="text-base text-slate-800">{{ $task->title }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>
        @empty
            <p class="card p-6 text-sm text-slate-500">Jabatan di departemen ini belum punya tugas aktif.</p>
        @endforelse

        <div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3">
                <p class="text-sm text-slate-600"><strong class="tabular-nums text-slate-900" data-chosen>{{ $chosen }}</strong> dari {{ $total }} tugas dipilih</p>
                <div class="flex gap-2">
                    <a href="{{ route('team.index') }}" class="btn-secondary">Batal</a>
                    <button class="btn-primary">Simpan tugas</button>
                </div>
            </div>
        </div>
    </form>

    @if ($member->custom_tasks)
        <form method="POST" action="{{ route('team.tasks.reset', $member) }}" class="mt-4 text-right">
            @csrf
            @method('DELETE')
            <button class="text-sm text-slate-600 underline hover:text-rose-700" onclick="return confirm('Kembalikan ke semua tugas jabatan?')">Kembalikan ke bawaan jabatan</button>
        </form>
    @endif

    <script>
        (() => {
            const form = document.getElementById('team-tasks');
            const search = document.querySelector('[data-task-search]');
            const boxesIn = (node) => [...node.querySelectorAll('input[name="tasks[]"]')];

            const refresh = () => {
                const all = boxesIn(form);
                const chosen = all.filter((box) => box.checked).length;
                form.querySelectorAll('[data-chosen]').forEach((node) => (node.textContent = chosen));
                form.querySelectorAll('[data-group]').forEach((group) => {
                    const boxes = boxesIn(group);
                    group.querySelector('[data-group-count]').textContent = `${boxes.filter((box) => box.checked).length} / ${boxes.length} dipilih`;
                });
                form.querySelectorAll('[data-section]').forEach((section) => {
                    const boxes = boxesIn(section);
                    section.querySelector('[data-section-toggle]').textContent = boxes.every((box) => box.checked) ? 'Kosongkan' : 'Pilih semua';
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
                form.querySelectorAll('[data-group]').forEach((group) => {
                    group.hidden = [...group.querySelectorAll('[data-section]')].every((section) => section.hidden);
                });
            });

            refresh();
        })();
    </script>
</x-app-layout>
