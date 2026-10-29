<x-app-layout :title="'Akses '.$employee->name">
    <h1 class="text-3xl text-slate-900">{{ $employee->name }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $employee->username }} · {{ $employee->department?->name ?? 'Tanpa departemen' }} · {{ $employee->position_title ?? '—' }}</p>

    <form method="POST" action="{{ route('admin.access.update', $employee) }}" class="card max-w-2xl space-y-5 p-6">
        @csrf
        @method('PUT')
        @php($user = $employee->user)

        <label class="flex items-center gap-2 text-sm font-medium">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user?->is_active ?? true))>
            Boleh memakai aplikasi ini
        </label>

        <div>
            <label for="role" class="label">Peran</label>
            <select id="role" name="role" class="input">
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $user?->role->value ?? 'staff') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            @error('role') <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="position_id" class="label">Jabatan checklist (menentukan daftar tugas harian)</label>
            <select id="position_id" name="position_id" class="input">
                <option value="">— Tidak mengisi checklist —</option>
                @foreach ($positions->groupBy(fn ($p) => $p->department->name) as $departmentName => $group)
                    <optgroup label="{{ $departmentName }}">
                        @foreach ($group as $position)
                            <option value="{{ $position->id }}" @selected(old('position_id', $user?->position_id) == $position->id)>{{ $position->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>

        <fieldset>
            <legend class="label">Departemen yang direview (khusus HOD)</legend>
            <div class="grid gap-1 sm:grid-cols-2">
                @php($selected = old('departments', $user?->departments->pluck('id')->all() ?? []))
                @foreach ($departments as $department)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="departments[]" value="{{ $department->id }}" @checked(in_array($department->id, $selected))>
                        {{ $department->name }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.access.index') }}" class="btn-secondary">Batal</a>
            <button class="btn-primary">Simpan</button>
        </div>
    </form>
</x-app-layout>
