<x-app-layout :title="'Tugas '.$position->name">
    <a href="{{ route('admin.tasks.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Semua jabatan') }}</a>
    <h1 class="mt-1 text-3xl text-slate-900">{{ $position->name }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $position->department->name }}. Perubahan berlaku untuk checklist yang dibuat sesudahnya.</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">
            @foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.positions.update', $position) }}" class="card mb-6 flex flex-wrap items-end gap-3 p-4">
        @csrf
        @method('PUT')
        <div class="min-w-48 flex-1">
            <label for="position-name" class="label">{{ __('Nama jabatan') }}</label>
            <input id="position-name" name="name" value="{{ old('name', $position->name) }}" required maxlength="255" class="input">
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked($position->is_active)> {{ __('Jabatan aktif') }}
        </label>
        <button class="btn-secondary">{{ __('Simpan jabatan') }}</button>
    </form>

    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-display text-xl">Daftar tugas ({{ $position->tasks->where('is_active', true)->count() }} aktif)</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500" data-move-status aria-live="polite"></span>
            <button type="button" class="btn-primary" data-open-add>{{ __('+ Tambah tugas') }}</button>
        </div>
    </div>
    <div class="space-y-2" data-task-list>
        @forelse ($position->tasks as $task)
            <div class="card p-3 {{ $task->is_active ? '' : 'bg-slate-50' }}" data-task-item>
                <div class="flex items-start gap-3">
                    <div class="flex flex-col gap-1">
                        <button type="button" class="btn-secondary !px-2 !py-0.5" data-move="up" data-url="{{ route('admin.tasks.move', $task) }}" @disabled($loop->first) aria-label="{{ __('Naikkan') }}">↑</button>
                        <button type="button" class="btn-secondary !px-2 !py-0.5" data-move="down" data-url="{{ route('admin.tasks.move', $task) }}" @disabled($loop->last) aria-label="{{ __('Turunkan') }}">↓</button>
                    </div>
                    <div class="min-w-0 flex-1 {{ $task->is_active ? '' : 'text-slate-400' }}">
                        @if ($task->section) <p class="text-xs uppercase tracking-wide text-slate-500">{{ $task->section }}</p> @endif
                        <p class="font-medium">{{ $task->title }}@unless ($task->is_active) <span class="text-xs font-normal">{{ __('(nonaktif)') }}</span>@endunless</p>
                        @if ($task->standard) <p class="mt-1 text-sm text-slate-500">{{ $task->standard }}</p> @endif
                    </div>
                </div>

                <details class="mt-2 border-t border-slate-100 pt-2" @if ($errors->any() && old('_task') == $task->id) open @endif>
                    <summary class="cursor-pointer text-sm text-sky-700">{{ __('Ubah') }}</summary>
                    <form method="POST" action="{{ route('admin.tasks.update', $task) }}" class="mt-3 space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_task" value="{{ $task->id }}">
                        @include('admin.tasks.fields', ['task' => $task])
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($task->is_active)> {{ __('Tugas aktif (muncul di checklist baru)') }}
                        </label>
                        <div class="flex flex-wrap justify-between gap-2">
                            <button form="delete-{{ $task->id }}" class="btn-danger" onclick="return confirm('Hapus tugas ini? Checklist yang sudah dibuat tidak berubah. Untuk sementara, cukup nonaktifkan.')">{{ __('Hapus') }}</button>
                            <button class="btn-primary">{{ __('Simpan tugas') }}</button>
                        </div>
                    </form>
                    <form id="delete-{{ $task->id }}" method="POST" action="{{ route('admin.tasks.destroy', $task) }}" class="hidden">@csrf @method('DELETE')</form>
                </details>
            </div>
        @empty
            <p class="card p-4 text-sm text-slate-500" data-task-empty>{{ __('Belum ada tugas untuk jabatan ini.') }}</p>
        @endforelse
    </div>

    <dialog id="add-task-dialog" aria-labelledby="add-task-title" class="m-auto w-[min(42rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="{{ route('admin.tasks.store', $position) }}" class="space-y-4 p-6">
            @csrf
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="add-task-title" class="font-display text-2xl text-slate-900">{{ __('Tambah tugas') }}</h2>
                    <p class="text-sm text-slate-500">{{ $position->name }} · tugas baru masuk ke checklist yang dibuat sesudahnya.</p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup') }}" data-close-add>{{ __('×') }}</button>
            </div>
            @include('admin.tasks.fields', ['task' => null])
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-add>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Tambah tugas') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const list = document.querySelector('[data-task-list]');
            const status = document.querySelector('[data-move-status]');
            const addDialog = document.getElementById('add-task-dialog');
            let busy = false;

            // Tombol naik / turun: urutan berubah di layar, lalu disimpan otomatis tanpa memuat ulang halaman.
            const refreshArrows = () => {
                const items = [...list.querySelectorAll('[data-task-item]')];
                items.forEach((item, index) => {
                    item.querySelector('[data-move="up"]').disabled = index === 0;
                    item.querySelector('[data-move="down"]').disabled = index === items.length - 1;
                });
            };

            list.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-move]');
                if (!button || busy) return;
                const item = button.closest('[data-task-item]');
                const up = button.dataset.move === 'up';
                const neighbour = up ? item.previousElementSibling : item.nextElementSibling;
                if (!neighbour || !neighbour.matches('[data-task-item]')) return;

                up ? list.insertBefore(item, neighbour) : list.insertBefore(neighbour, item);
                refreshArrows();
                busy = true;
                status.textContent = window.t('Menyimpan…');

                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ direction: button.dataset.move }),
                    });
                    if (!response.ok) throw new Error(response.status);
                    status.textContent = __('Urutan tersimpan otomatis');
                } catch (error) {
                    // Gagal: kembalikan urutan di layar dan beri tahu pengguna (sesi habis bisa jadi sebabnya).
                    up ? list.insertBefore(neighbour, item) : list.insertBefore(item, neighbour);
                    refreshArrows();
                    status.textContent = error.message === '419' || error.message === '401' ? window.t('Sesi habis, silakan muat ulang halaman') : window.t('Gagal menyimpan urutan, coba lagi');
                } finally {
                    busy = false;
                }
            });

            document.querySelector('[data-open-add]')?.addEventListener('click', () => addDialog.showModal());
            addDialog.querySelectorAll('[data-close-add]').forEach((button) => button.addEventListener('click', () => addDialog.close()));

            @if ($errors->any() && ! old('_task'))
                addDialog.showModal();
            @endif
        })();
    </script>
</x-app-layout>
