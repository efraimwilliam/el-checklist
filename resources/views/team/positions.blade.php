<x-app-layout title="{{ __('Jabatan staff') }}">
    <a href="{{ route('fill.create') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Isi checklist') }}</a>
    <h1 class="mt-1 text-3xl text-slate-900">{{ __('Jabatan staff') }}</h1>
    <p class="mb-4 text-sm text-slate-500">Atur sekali jabatan checklist tiap staff. Jabatan inilah yang menentukan daftar tugas saat checklist dibuatkan sekaligus. Saran diambil dari jabatan di Absensi; periksa lalu simpan.</p>

    @if ($reviews->isNotEmpty())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>{{ trans_choice(':n staff perlu dicek|:n staff perlu dicek', $reviews->count(), ['n' => $reviews->count()]) }}</strong>
            {{ __('Jabatan mereka di Absensi berubah (atau mereka pindah departemen) sejak jabatan checklist diatur. Daftar tugas tidak berubah sendiri: pilih jabatan yang benar lalu simpan, atau centang "Jabatan checklist tetap benar".') }}
        </div>
    @endif

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
                    <thead class="text-left text-xs text-slate-500"><tr><th class="px-4 py-2 font-medium">{{ __('Staff') }}</th><th class="px-4 py-2 font-medium">{{ __('Jabatan di Absensi') }}</th><th class="px-4 py-2 font-medium">{{ __('Jabatan checklist') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            @php
                                $options = $positions->get($member->department_id, collect());
                                $suggested = $suggestions[$member->id] ?? null;
                                $review = $reviews[$member->id] ?? null;
                                $selected = (int) old("positions.{$member->id}", $review && $suggested ? $suggested : ($member->checklist_position_id ?? $suggested));
                            @endphp
                            <tr @class(['bg-amber-50/60' => $review])>
                                <td class="px-4 py-2 font-medium text-slate-800">{{ $member->name }}</td>
                                <td class="px-4 py-2 text-slate-500">{{ $member->position_title ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <select name="positions[{{ $member->id }}]" class="input" aria-label="Jabatan checklist {{ $member->name }}">
                                        <option value="">{{ __('— Belum diatur —') }}</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option->id }}" @selected($selected === $option->id)>{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($review)
                                        <span class="mt-1 block text-xs font-semibold text-amber-900">
                                            @if ($review['type'] === 'title')
                                                {{ __('Perlu dicek: jabatan di Absensi berubah dari ":from" menjadi ":to".', ['from' => $review['from'], 'to' => $review['to'] ?? __('(kosong)')]) }}
                                            @else
                                                {{ __('Perlu dicek: jabatan checklist saat ini (:position) bukan dari departemen pegawai ini.', ['position' => $review['position']]) }}
                                            @endif
                                        </span>
                                        @if ($suggested)
                                            <span class="block text-xs text-amber-800">{{ __('Saran dari Absensi, belum disimpan') }}</span>
                                        @endif
                                        @if ($member->checklist_position_id && $options->contains('id', $member->checklist_position_id))
                                            <label class="mt-1 flex items-center gap-2 text-xs text-slate-700">
                                                <input type="checkbox" name="confirm[]" value="{{ $member->id }}" class="h-4 w-4 rounded border-slate-300 accent-[#b08a3e]">
                                                {{ __('Jabatan checklist tetap benar') }}
                                            </label>
                                        @endif
                                    @elseif (! $member->checklist_position_id && $suggested)
                                        <span class="mt-1 block text-xs text-amber-800">{{ __('Saran dari Absensi, belum disimpan') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @empty
            <p class="card p-6 text-sm text-slate-500">{{ __('Belum ada staff di departemen Anda.') }}</p>
        @endforelse
        <div class="flex justify-end"><button class="btn-primary">{{ __('Simpan jabatan') }}</button></div>
    </form>
</x-app-layout>
