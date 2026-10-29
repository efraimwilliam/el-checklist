<x-app-layout title="Atur tugas tim">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">Atur tugas tim</h1>
        <p class="text-sm text-slate-500">Pilih tugas apa saja yang masuk checklist tiap pegawai (data dari Absensi, tidak perlu punya akun). Perubahan berlaku untuk checklist yang dibuat sesudahnya.</p>
    </div>

    <div class="space-y-4">
        @forelse ($membersByDepartment as $department => $members)
            <section class="card overflow-x-auto">
                <h2 class="border-b border-slate-100 px-4 py-2 font-display text-xl">{{ $department }}</h2>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('team.tasks.edit', $member) }}" class="font-medium text-sky-700 hover:underline">{{ $member->name }}</a>
                                    <span class="block text-xs text-slate-500">{{ $member->position_title ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    @if ($member->custom_tasks)
                                        <span class="pill bg-sky-100 text-sky-800">Disesuaikan · {{ $member->assigned_tasks_count }} tugas</span>
                                    @else
                                        Bawaan jabatan
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right"><a href="{{ route('team.tasks.edit', $member) }}" class="btn-secondary">Atur tugas</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @empty
            <p class="card p-6 text-sm text-slate-500">Belum ada pegawai di departemen Anda. Data pegawai datang dari Absensi (Administrator bisa menekan "Sinkron dari absensi").</p>
        @endforelse
    </div>
</x-app-layout>
