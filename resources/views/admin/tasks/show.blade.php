<x-app-layout :title="'Tugas '.$position->name">
    <a href="{{ route('admin.tasks.index') }}" class="text-sm text-sky-700 hover:underline">← Semua jabatan</a>
    <h1 class="mt-1 text-xl font-semibold">{{ $position->name }}</h1>
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
            <label for="position-name" class="label">Nama jabatan</label>
            <input id="position-name" name="name" value="{{ old('name', $position->name) }}" required maxlength="255" class="input">
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked($position->is_active)> Jabatan aktif
        </label>
        <button class="btn-secondary">Simpan jabatan</button>
    </form>

    <h2 class="mb-2 font-semibold">Daftar tugas ({{ $position->tasks->where('is_active', true)->count() }} aktif)</h2>
    <div class="space-y-2">
        @forelse ($position->tasks as $task)
            <div class="card p-3 {{ $task->is_active ? '' : 'bg-slate-50' }}">
                <div class="flex items-start gap-3">
                    <div class="flex flex-col gap-1">
                        <form method="POST" action="{{ route('admin.tasks.move', $task) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn-secondary !px-2 !py-0.5" @disabled($loop->first) aria-label="Naikkan">↑</button></form>
                        <form method="POST" action="{{ route('admin.tasks.move', $task) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn-secondary !px-2 !py-0.5" @disabled($loop->last) aria-label="Turunkan">↓</button></form>
                    </div>
                    <div class="min-w-0 flex-1 {{ $task->is_active ? '' : 'text-slate-400' }}">
                        @if ($task->section) <p class="text-xs uppercase tracking-wide text-slate-500">{{ $task->section }}</p> @endif
                        <p class="font-medium">{{ $task->title }}@unless ($task->is_active) <span class="text-xs font-normal">(nonaktif)</span>@endunless</p>
                        @if ($task->standard) <p class="mt-1 text-sm text-slate-500">{{ $task->standard }}</p> @endif
                    </div>
                </div>

                <details class="mt-2 border-t border-slate-100 pt-2" @if ($errors->any() && old('_task') == $task->id) open @endif>
                    <summary class="cursor-pointer text-sm text-sky-700">Ubah</summary>
                    <form method="POST" action="{{ route('admin.tasks.update', $task) }}" class="mt-3 space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_task" value="{{ $task->id }}">
                        @include('admin.tasks.fields', ['task' => $task])
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($task->is_active)> Tugas aktif (muncul di checklist baru)
                        </label>
                        <div class="flex flex-wrap justify-between gap-2">
                            <button form="delete-{{ $task->id }}" class="btn-danger" onclick="return confirm('Hapus tugas ini? Checklist yang sudah dibuat tidak berubah. Untuk sementara, cukup nonaktifkan.')">Hapus</button>
                            <button class="btn-primary">Simpan tugas</button>
                        </div>
                    </form>
                    <form id="delete-{{ $task->id }}" method="POST" action="{{ route('admin.tasks.destroy', $task) }}" class="hidden">@csrf @method('DELETE')</form>
                </details>
            </div>
        @empty
            <p class="card p-4 text-sm text-slate-500">Belum ada tugas untuk jabatan ini.</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.tasks.store', $position) }}" class="card mt-6 space-y-3 p-4">
        @csrf
        <h2 class="font-semibold">Tambah tugas</h2>
        @include('admin.tasks.fields', ['task' => null])
        <div class="flex justify-end"><button class="btn-primary">Tambah tugas</button></div>
    </form>
</x-app-layout>
