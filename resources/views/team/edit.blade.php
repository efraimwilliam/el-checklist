<x-app-layout :title="'Tugas '.$member->name()">
    <a href="{{ route('team.index') }}" class="text-sm text-sky-700 hover:underline">← Tim</a>
    <h1 class="mt-1 text-xl font-semibold">{{ $member->name() }}</h1>
    <p class="text-sm text-slate-500">{{ $member->employee->department->name }} · {{ $member->position?->name ?? 'Jabatan checklist belum diatur' }}</p>
    <p class="mb-4 mt-1 text-sm {{ $member->custom_tasks ? 'text-sky-800' : 'text-slate-500' }}">
        {{ $member->custom_tasks ? 'Tugas sudah disesuaikan.' : 'Memakai semua tugas jabatan (bawaan).' }}
        Perubahan berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.
    </p>

    @error('tasks') <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <form method="POST" action="{{ route('team.tasks.update', $member) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @foreach ($groups as $group)
            <fieldset class="card p-4">
                <legend class="px-1 font-semibold">{{ $group->name }}@if ($group->id === $member->position_id) <span class="text-sm font-normal text-slate-500">(jabatan pegawai ini)</span>@endif</legend>
                <div class="mt-2 space-y-2">
                    @foreach ($group->tasks as $task)
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" name="tasks[]" value="{{ $task->id }}" class="mt-1" @checked(in_array($task->id, $selected))>
                            <span>
                                @if ($task->section) <span class="block text-xs uppercase tracking-wide text-slate-500">{{ $task->section }}</span> @endif
                                {{ $task->title }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
        <div class="flex justify-end gap-2">
            <a href="{{ route('team.index') }}" class="btn-secondary">Batal</a>
            <button class="btn-primary">Simpan tugas</button>
        </div>
    </form>

    @if ($member->custom_tasks)
        <form method="POST" action="{{ route('team.tasks.reset', $member) }}" class="mt-4 text-right">
            @csrf
            @method('DELETE')
            <button class="text-sm text-slate-600 underline hover:text-rose-700" onclick="return confirm('Kembalikan ke semua tugas jabatan?')">Kembalikan ke bawaan jabatan</button>
        </form>
    @endif
</x-app-layout>
