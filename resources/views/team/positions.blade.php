<x-app-layout title="Jabatan staff">
    <a href="{{ route('fill.create') }}" class="text-sm text-sky-700 hover:underline">← Isi checklist</a>
    <h1 class="mt-1 text-3xl text-slate-900">Jabatan staff</h1>
    <p class="mb-4 text-sm text-slate-500">Atur sekali jabatan checklist tiap staff. Jabatan inilah yang menentukan daftar tugas saat checklist dibuatkan sekaligus. Saran diambil dari jabatan di Absensi; periksa lalu simpan.</p>

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif

    <form method="POST" action="{{ route('team.positions.update') }}" class="space-y-4">
        @csrf
        @method('PUT')
        @forelse ($byDepartment as $department => $members)
            <section class="card overflow-x-auto">
                <h2 class="border-b border-slate-100 px-4 py-2 font-display text-xl">{{ $department }}</h2>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-slate-500"><tr><th class="px-4 py-2 font-medium">Staff</th><th class="px-4 py-2 font-medium">Jabatan di Absensi</th><th class="px-4 py-2 font-medium">Jabatan checklist</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            @php
                                $options = $positions->get($member->department_id, collect());
                                $suggested = $suggestions[$member->id] ?? null;
                                $selected = (int) old("positions.{$member->id}", $member->checklist_position_id ?? $suggested);
                            @endphp
                            <tr>
                                <td class="px-4 py-2 font-medium text-slate-800">{{ $member->name }}</td>
                                <td class="px-4 py-2 text-slate-500">{{ $member->position_title ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <select name="positions[{{ $member->id }}]" class="input" aria-label="Jabatan checklist {{ $member->name }}">
                                        <option value="">— Belum diatur —</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option->id }}" @selected($selected === $option->id)>{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                    @if (! $member->checklist_position_id && $suggested)
                                        <span class="mt-1 block text-xs text-amber-800">Saran dari Absensi, belum disimpan</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @empty
            <p class="card p-6 text-sm text-slate-500">Belum ada staff di departemen Anda.</p>
        @endforelse
        <div class="flex justify-end"><button class="btn-primary">Simpan jabatan</button></div>
    </form>
</x-app-layout>
