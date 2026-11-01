<x-app-layout title="{{ __('Atur tugas pegawai') }}">
    <div class="mb-6">
        <h1 class="text-3xl text-slate-900">{{ __('Atur tugas pegawai') }}</h1>
        <p class="text-sm text-slate-500">Pilih tugas apa saja yang masuk checklist tiap pegawai. Perubahan berlaku untuk checklist yang dibuat sesudahnya.
            Daftar tugas tiap jabatan ada di menu Kamus daftar tugas.</p>
        <p class="mt-2 text-sm text-slate-500">Data pegawai dari Absensi; tidak perlu punya akun.
            <a href="{{ route('team.positions.edit') }}" class="font-semibold text-sky-700 hover:underline">{{ __('Atur jabatan checklist tiap staff') }}</a> {{ __('dipakai saat checklist dibuatkan sekaligus.') }}</p>
    </div>

    @if ($single)
        @foreach ($membersByDepartment as $department => $members)
            <section class="card overflow-hidden p-0">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="font-display text-2xl text-slate-900">{{ $department }}</h2>
                    <p class="text-sm text-slate-500">{{ __(':n pegawai · pilih nama untuk mengatur tugasnya', ['n' => $members->count()]) }}</p>
                </div>
                @include('team.partials.member-list', ['members' => $members])
            </section>
        @endforeach
    @else
    @forelse ($membersByDepartment as $department => $members)
        @php
            $dialogId = 'dept-'.$loop->index;
            $custom = $members->where('custom_tasks', true)->count();
            $initials = fn ($name) => collect(explode(' ', $name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
        @endphp
        <button type="button" data-open-dept="{{ $dialogId }}" class="card mb-4 flex w-full items-center gap-4 p-5 text-left transition hover:border-gold-500 hover:shadow-md">
            <span class="hod-avatar !h-14 !w-14 shrink-0 !text-lg" aria-hidden="true">{{ $initials($department) }}</span>
            <span class="min-w-0 flex-1">
                <span class="block font-display text-xl leading-snug text-slate-900">{{ $department }}</span>
                <span class="mt-1 block text-sm text-slate-500">{{ $members->count() }} pegawai · {{ $custom }} tugas disesuaikan</span>
            </span>
            <span class="text-2xl text-gold-700" aria-hidden="true">›</span>
        </button>

        <dialog id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 id="{{ $dialogId }}-title" class="font-display text-2xl text-slate-900">{{ $department }}</h2>
                    <p class="text-sm text-slate-500">{{ $members->count() }} pegawai · pilih nama untuk mengatur tugasnya</p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf] hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup') }}" data-close-dept>{{ __('×') }}</button>
            </div>
            <ul class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto">
                @foreach ($members as $member)
                    <li>
                        <a href="{{ route('team.tasks.edit', $member) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-[#fbf6ea]">
                            <span class="hod-avatar !h-10 !w-10 shrink-0 !text-sm" aria-hidden="true">{{ $initials($member->name) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-900">{{ $member->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $member->position_title ?? '—' }}</span>
                            </span>
                            @if ($member->custom_tasks)
                                <span class="pill bg-sky-100 text-sky-800">Disesuaikan · {{ $member->assigned_tasks_count }} tugas</span>
                            @else
                                <span class="pill-neutral">{{ __('Bawaan jabatan') }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </dialog>
    @empty
        <p class="card p-6 text-sm text-slate-500">Belum ada pegawai di departemen Anda. Data pegawai datang dari Absensi (Administrator bisa menekan "Sinkron dari absensi").</p>
    @endforelse
    @endif

    <script>
        (() => {
            document.querySelectorAll('[data-open-dept]').forEach((card) => card.addEventListener('click', () => document.getElementById(card.dataset.openDept).showModal()));
            document.querySelectorAll('[data-close-dept]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
        })();
    </script>
</x-app-layout>
