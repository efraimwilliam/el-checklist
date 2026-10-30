<x-app-layout :title="__('Atur tugas hari ini')">
    <a href="{{ route('checklists.fill', $checklist) }}" class="text-sm font-semibold text-sky-700 hover:underline">{{ __('← Kembali ke checklist') }}</a>
    <h1 class="mb-1 mt-2 text-3xl text-slate-900">{{ __('Atur tugas hari ini') }}</h1>
    <p class="text-sm text-slate-500">
        {{ $checklist->employee->name }} · {{ $checklist->position_name }} · {{ $checklist->date->translatedFormat('d M Y') }}
    </p>
    <p class="mb-5 mt-3 max-w-2xl text-sm text-slate-600">
        {{ __('Tugas awal diambil dari Atur tugas pegawai. Hilangkan centang untuk tugas yang tidak dikerjakan hari ini, atau centang untuk menambahkannya. Tugas yang sudah diisi tidak bisa dihapus.') }}
    </p>

    <form method="POST" action="{{ route('checklists.tasks.update', $checklist) }}" class="space-y-5 pb-24">
        @csrf
        @method('PUT')

        @forelse ($groups as $section => $rows)
            <section class="card overflow-hidden p-0">
                <h2 class="border-b border-slate-100 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">{{ $section !== '' ? $section : __('Tugas') }}</h2>
                <ul class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <li class="px-5 py-3">
                            <label class="flex cursor-pointer items-start gap-3 {{ $row['locked'] ? 'cursor-not-allowed text-slate-500' : '' }}">
                                @if ($row['locked'])
                                    <input type="hidden" name="tasks[]" value="{{ $row['id'] }}">
                                    <input type="checkbox" checked disabled class="mt-1 h-5 w-5 accent-[#b08a3e]">
                                @else
                                    <input type="checkbox" name="tasks[]" value="{{ $row['id'] }}" @checked($row['checked']) class="mt-1 h-5 w-5 accent-[#b08a3e]">
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="block text-base text-slate-800">{{ $row['title'] }}</span>
                                    @if ($row['locked'])
                                        <span class="text-xs text-slate-500">{{ __('Sudah diisi, tidak bisa dihapus') }}</span>
                                    @endif
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="card p-6 text-sm text-slate-500">{{ __('Jabatan checklist pegawai ini belum punya tugas.') }}</p>
        @endforelse

        @if ($others->isNotEmpty())
            <section class="space-y-3">
                <h2 class="px-1 text-sm font-semibold text-slate-700">{{ __('Tugas dari jabatan lain') }}</h2>
                @foreach ($others as $position => $rows)
                    <details class="card group overflow-hidden p-0" @if ($rows->contains(fn ($row) => $row['checked'])) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 [&::-webkit-details-marker]:hidden">
                            <span class="font-semibold text-slate-800">{{ $position }}</span>
                            <span class="flex items-center gap-2 text-xs text-slate-500">
                                {{ __(':n tugas', ['n' => $rows->count()]) }}
                                <span class="text-slate-400 transition group-open:rotate-90" aria-hidden="true">›</span>
                            </span>
                        </summary>
                        <ul class="divide-y divide-slate-100 border-t border-slate-100">
                            @foreach ($rows as $row)
                                <li class="px-5 py-3">
                                    <label class="flex cursor-pointer items-start gap-3 {{ $row['locked'] ? 'cursor-not-allowed text-slate-500' : '' }}">
                                        @if ($row['locked'])
                                            <input type="hidden" name="tasks[]" value="{{ $row['id'] }}">
                                            <input type="checkbox" checked disabled class="mt-1 h-5 w-5 accent-[#b08a3e]">
                                        @else
                                            <input type="checkbox" name="tasks[]" value="{{ $row['id'] }}" @checked($row['checked']) class="mt-1 h-5 w-5 accent-[#b08a3e]">
                                        @endif
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-base text-slate-800">{{ $row['title'] }}</span>
                                            @if ($row['locked'])
                                                <span class="text-xs text-slate-500">{{ __('Sudah diisi, tidak bisa dihapus') }}</span>
                                            @endif
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </section>
        @endif

        <div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3">
                <span class="text-sm text-slate-500">{{ __('Perubahan langsung berlaku pada checklist ini.') }}</span>
                <button class="btn-primary">{{ __('Simpan tugas hari ini') }}</button>
            </div>
        </div>
    </form>
</x-app-layout>
