<x-tugas-page tab="pegawai" :title="__('Pegawai')">
    @php
        $all = $membersByDepartment->flatten();
        $counts = [
            'all' => $all->count(),
            'none' => $all->where('position_state', 'none')->count(),
            'review' => $all->where('position_state', 'review')->count(),
            'custom' => $all->where('custom_tasks', true)->count(),
        ];
        $initials = fn ($name) => collect(explode(' ', $name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    @endphp

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-section">{{ __('Pegawai') }}</h2>
            <p class="max-w-2xl text-sm text-slate-500">{{ __('Ketuk nama pegawai untuk mengatur jabatan checklist dan tugasnya di satu layar. Data pegawai dari Absensi, tidak perlu punya akun.') }}</p>
        </div>
        <a href="{{ route('team.positions.edit') }}" class="btn-secondary btn-sm">{{ __('Atur jabatan sekaligus') }}</a>
    </div>

    @if ($counts['all'])
        <div class="card mb-4 space-y-3 p-4">
            <label class="block">
                <span class="sr-only">{{ __('Cari nama pegawai') }}</span>
                <input type="search" class="input w-full" placeholder="{{ __('Cari nama pegawai') }}" data-member-search>
            </label>
            <div class="flex flex-wrap gap-1 rounded-2xl bg-gold-100 p-1" role="group" aria-label="{{ __('Saring pegawai') }}">
                @foreach (['all' => __('Semua'), 'none' => __('Belum ada jabatan'), 'review' => __('Perlu dicek'), 'custom' => __('Tugas disesuaikan')] as $key => $label)
                    <button type="button" data-member-filter="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}"
                            class="rounded-xl px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:text-slate-900 aria-pressed:bg-white aria-pressed:text-slate-900 aria-pressed:shadow-sm">
                        {{ $label }} <span class="tabular-nums text-slate-400">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @forelse ($membersByDepartment as $department => $members)
        <details open class="card mb-4 overflow-hidden" data-member-department>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 hover:bg-gold-50 [&::-webkit-details-marker]:hidden">
                <span class="text-section">{{ $department }}</span>
                <span class="text-sm text-slate-500">{{ __(':n pegawai', ['n' => $members->count()]) }}</span>
            </summary>
            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($members as $member)
                    <li data-member data-name="{{ mb_strtolower($member->name) }}" data-state="{{ $member->position_state }}" data-custom="{{ $member->custom_tasks ? '1' : '0' }}">
                        <a href="{{ route('team.tasks.edit', $member) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-gold-50">
                            <span class="hod-avatar !h-10 !w-10 shrink-0 !text-sm" aria-hidden="true">{{ $initials($member->name) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-900">{{ $member->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ __('Absensi') }}: {{ $member->position_title ?? '—' }}</span>
                                <span class="mt-1 flex flex-wrap gap-1.5">
                                    @if ($member->position_state === 'none')
                                        <span class="pill bg-amber-100 text-amber-900">{{ __('Belum ada jabatan') }}</span>
                                    @else
                                        <span @class(['pill', 'bg-amber-100 text-amber-900' => $member->position_state === 'review', 'bg-emerald-50 text-emerald-800' => $member->position_state === 'set'])>
                                            {{ $member->checklistPosition?->name }}@if ($member->position_state === 'review') · {{ __('perlu dicek') }}@endif
                                        </span>
                                    @endif
                                    @if ($member->custom_tasks)
                                        <span class="pill bg-sky-100 text-sky-800">{{ __('Disesuaikan · :n tugas', ['n' => $member->assigned_tasks_count]) }}</span>
                                    @else
                                        <span class="pill-neutral">{{ __('Tugas bawaan') }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="text-xl text-gold-700" aria-hidden="true">›</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </details>
    @empty
        @if (auth()->user()->isHodWithoutDepartment())
            <p class="card border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"><span class="font-semibold">{{ __('Departemen Anda belum diatur.') }}</span> {{ __('Minta Administrator mencentang departemen Anda di Akses pengguna, supaya daftar tugas dan pegawainya muncul di sini.') }}</p>
        @else
            <p class="card p-6 text-sm text-slate-500">{{ __('Belum ada pegawai di departemen Anda. Data pegawai datang dari Absensi (Administrator bisa menekan "Sinkron dari absensi").') }}</p>
        @endif
    @endforelse
    <p class="hidden card p-6 text-center text-sm text-slate-500" data-member-empty>{{ __('Tidak ada pegawai yang cocok dengan pencarian atau saringan.') }}</p>

    <script>
        (() => {
            const search = document.querySelector('[data-member-search]');
            const buttons = [...document.querySelectorAll('[data-member-filter]')];
            if (!search) return;
            let filter = 'all';
            const matches = (row) => filter === 'all' || (filter === 'custom' ? row.dataset.custom === '1' : row.dataset.state === filter);
            const apply = () => {
                const query = search.value.trim().toLowerCase();
                let shown = 0;
                document.querySelectorAll('[data-member-department]').forEach((department) => {
                    let visible = 0;
                    department.querySelectorAll('[data-member]').forEach((row) => {
                        row.hidden = !(matches(row) && (query === '' || row.dataset.name.includes(query)));
                        visible += row.hidden ? 0 : 1;
                    });
                    department.hidden = visible === 0;
                    if (visible && (query || filter !== 'all')) department.open = true;
                    shown += visible;
                });
                document.querySelector('[data-member-empty]').classList.toggle('hidden', shown > 0);
            };
            search.addEventListener('input', apply);
            buttons.forEach((button) => button.addEventListener('click', () => {
                filter = button.dataset.memberFilter;
                buttons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
                apply();
            }));
        })();
    </script>
</x-tugas-page>
