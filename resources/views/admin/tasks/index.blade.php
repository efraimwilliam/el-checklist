<x-app-layout title="Kelola tugas">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">Kelola tugas</h1>
        <p class="text-sm text-slate-500">Pilih jabatan untuk mengubah daftar tugasnya. Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.</p>
    </div>

    @error('name') <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <div class="space-y-4">
        @foreach ($departments as $department)
            <section class="card p-4">
                <h2 class="mb-2 font-display text-xl">{{ $department->name }}</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($department->positions as $position)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('admin.tasks.show', $position) }}" class="min-w-0 truncate text-sky-700 hover:underline {{ $position->is_active ? '' : 'text-slate-400 line-through' }}">{{ $position->name }}</a>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="hidden text-slate-500 sm:inline">{{ $position->active_tasks_count }} tugas aktif{{ $position->is_active ? '' : ' · nonaktif' }}</span>
                                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[#f6efdf] hover:text-gold-700"
                                    data-edit-position data-url="{{ route('admin.positions.update', $position) }}" data-name="{{ $position->name }}" data-active="{{ $position->is_active ? '1' : '0' }}"
                                    aria-label="Ubah jabatan" title="Ubah jabatan">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </button>
                                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
                                    data-delete-position data-url="{{ route('admin.positions.destroy', $position) }}" data-name="{{ $position->name }}" data-used="{{ $position->checklists_count }}"
                                    @disabled($position->checklists_count > 0)
                                    aria-label="Hapus jabatan" title="{{ $position->checklists_count > 0 ? 'Sudah dipakai checklist, nonaktifkan saja' : 'Hapus jabatan' }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                                </button>
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">Belum ada jabatan.</li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('admin.positions.store') }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <input type="hidden" name="department_id" value="{{ $department->id }}">
                    <input name="name" placeholder="Nama jabatan baru" required maxlength="255" class="input max-w-xs">
                    <button class="btn-secondary">Tambah jabatan</button>
                </form>
            </section>
        @endforeach
    </div>

    <dialog id="edit-position-dialog" aria-labelledby="edit-position-title" class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" id="edit-position-form" action="" class="space-y-4 p-6">
            @csrf
            @method('PUT')
            <div class="flex items-start justify-between gap-3">
                <h2 id="edit-position-title" class="font-display text-2xl text-slate-900">Ubah jabatan</h2>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="Tutup" data-close-edit-position>×</button>
            </div>
            <div>
                <label for="edit-position-name" class="label">Nama jabatan</label>
                <input id="edit-position-name" name="name" required maxlength="255" class="input" autofocus>
            </div>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" id="edit-position-active" class="h-4 w-4 accent-[#b08a3e]">
                Jabatan aktif (muncul di pilihan checklist)
            </label>
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-edit-position>Batal</button>
                <button class="btn-primary">Simpan jabatan</button>
            </div>
        </form>
    </dialog>

    <dialog id="delete-position-dialog" aria-labelledby="delete-position-title" class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" id="delete-position-form" action="" class="space-y-4 p-6">
            @csrf
            @method('DELETE')
            <h2 id="delete-position-title" class="font-display text-2xl text-slate-900">Hapus jabatan?</h2>
            <p class="text-sm text-slate-600">"<span id="delete-position-name" class="font-semibold text-slate-900"></span>" beserta semua tugasnya akan dihapus. Pegawai yang memakai jabatan ini dilepas dari jabatan checklist-nya.</p>
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-delete-position autofocus>Batal</button>
                <button class="btn-danger">Hapus jabatan</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const editDialog = document.getElementById('edit-position-dialog');
            const editForm = document.getElementById('edit-position-form');
            const deleteDialog = document.getElementById('delete-position-dialog');
            const deleteForm = document.getElementById('delete-position-form');

            document.querySelectorAll('[data-edit-position]').forEach((button) => button.addEventListener('click', () => {
                editForm.action = button.dataset.url;
                document.getElementById('edit-position-name').value = button.dataset.name;
                document.getElementById('edit-position-active').checked = button.dataset.active === '1';
                editDialog.showModal();
            }));
            editDialog.querySelectorAll('[data-close-edit-position]').forEach((button) => button.addEventListener('click', () => editDialog.close()));

            document.querySelectorAll('[data-delete-position]').forEach((button) => button.addEventListener('click', () => {
                deleteForm.action = button.dataset.url;
                document.getElementById('delete-position-name').textContent = button.dataset.name;
                deleteDialog.showModal();
            }));
            deleteDialog.querySelectorAll('[data-close-delete-position]').forEach((button) => button.addEventListener('click', () => deleteDialog.close()));
        })();
    </script>
</x-app-layout>
