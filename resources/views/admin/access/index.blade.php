<x-app-layout title="Akses pengguna">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-3xl text-slate-900">Akses pengguna</h1>
            <p class="text-sm text-slate-500">
                Data pegawai dari Sistem Absensi.
                @if ($lastSync)
                    Sinkron terakhir {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->format('d M Y H:i') }}.
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('admin.sync') }}">
            @csrf
            <button class="btn-secondary">Sinkron dari absensi</button>
        </form>
    </div>
    @error('sync') <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input name="q" value="{{ $search }}" placeholder="Cari nama / username" class="input max-w-xs">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="with_access" value="1" @checked(request()->boolean('with_access'))> Hanya yang punya akses</label>
        <button class="btn-secondary">Cari</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Pegawai</th>
                    <th class="px-4 py-2 font-medium">Departemen</th>
                    <th class="px-4 py-2 font-medium">Peran</th>
                    <th class="px-4 py-2 font-medium">Jabatan checklist</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($employees as $employee)
                    <tr class="{{ $employee->is_active ? '' : 'text-slate-400' }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.access.edit', $employee) }}" class="font-medium text-sky-700 hover:underline">{{ $employee->name }}</a>
                            <span class="block text-xs text-slate-500">{{ $employee->username }}{{ $employee->is_active ? '' : ' · nonaktif di absensi' }}</span>
                        </td>
                        <td class="px-4 py-2">{{ $employee->department?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($employee->user?->is_active)
                                {{ $employee->user->role->label() }}
                                @if ($employee->user->departments->isNotEmpty())
                                    <span class="block text-xs text-slate-500">{{ $employee->user->departments->pluck('code')->implode(', ') }}</span>
                                @endif
                            @else
                                <span class="text-slate-400">Tanpa akses</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $employee->user?->position?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $employees->links() }}</div>
</x-app-layout>
