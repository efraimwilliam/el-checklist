<x-app-layout title="Kelola tugas">
    <div class="mb-4">
        <h1 class="text-xl font-semibold">Kelola tugas</h1>
        <p class="text-sm text-slate-500">Pilih jabatan untuk mengubah daftar tugasnya. Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.</p>
    </div>

    @error('name') <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <div class="space-y-4">
        @foreach ($departments as $department)
            <section class="card p-4">
                <h2 class="mb-2 font-semibold">{{ $department->name }}</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($department->positions as $position)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('admin.tasks.show', $position) }}" class="text-sky-700 hover:underline {{ $position->is_active ? '' : 'text-slate-400 line-through' }}">{{ $position->name }}</a>
                            <span class="text-slate-500">{{ $position->active_tasks_count }} tugas aktif{{ $position->is_active ? '' : ' · jabatan nonaktif' }}</span>
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
</x-app-layout>
