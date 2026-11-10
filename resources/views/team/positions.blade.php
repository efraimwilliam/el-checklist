<x-app-layout title="{{ __('Jabatan staff') }}">
    @php
        $all = $byDepartment->flatten();
        $total = $all->count();
        $setTotal = $all->filter(fn ($member) => $member->checklist_position_id)->count();
        $reviewTotal = $reviews->count();
        $stateOf = fn ($member) => $reviews->has($member->id) ? 'review' : ($member->checklist_position_id ? 'set' : 'none');
    @endphp

    <a href="{{ route('fill.create') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Isi checklist') }}</a>
    <div class="mt-2 mb-5">
        <h1 class="text-3xl text-slate-900">{{ __('Jabatan staff') }}</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ __('Atur sekali jabatan checklist tiap staff. Jabatan inilah yang menentukan daftar tugas saat checklist dibuatkan sekaligus. Saran diambil dari jabatan di Absensi; periksa lalu simpan.') }}</p>
    </div>

    <dl class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="card p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Staff') }}</dt>
            <dd class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $total }}</dd>
        </div>
        <div class="card p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Sudah diatur') }}</dt>
            <dd class="mt-1 font-display text-3xl tabular-nums text-emerald-800">{{ $setTotal }}</dd>
        </div>
        <div class="card p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Belum diatur') }}</dt>
            <dd class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $total - $setTotal }}</dd>
        </div>
        <div class="card p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{{ __('Ditandai') }}</dt>
            <dd @class(['mt-1 font-display text-3xl tabular-nums', 'text-amber-700' => $reviewTotal > 0, 'text-slate-900' => $reviewTotal === 0])>{{ $reviewTotal }}</dd>
        </div>
    </dl>

    @if ($reviews->isNotEmpty())
        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>{{ trans_choice(':n staff perlu dicek|:n staff perlu dicek', $reviews->count(), ['n' => $reviews->count()]) }}</strong>
            {{ __('Jabatan mereka di Absensi berubah (atau mereka pindah departemen) sejak jabatan checklist diatur. Daftar tugas tidak berubah sendiri: pilih jabatan yang benar lalu simpan, atau centang "Jabatan checklist tetap benar".') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif

    <div class="card mb-4 space-y-3 p-4">
        <label class="block">
            <span class="sr-only">{{ __('Cari nama staff') }}</span>
            <input type="search" class="input w-full" placeholder="{{ __('Cari nama staff') }}" data-filter-search>
        </label>
        <div class="flex flex-wrap gap-1 rounded-full bg-[#f6efdf] p-1" role="group" aria-label="{{ __('Saring staff') }}">
            @foreach (['all' => __('Semua'), 'none' => __('Belum diatur'), 'review' => __('Ditandai'), 'set' => __('Sudah diatur')] as $key => $label)
                <button type="button" class="rounded-full px-4 py-1.5 text-sm font-medium text-slate-600 transition hover:text-slate-900 aria-pressed:bg-white aria-pressed:text-slate-900 aria-pressed:shadow-sm" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" data-filter="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <form id="positions-form" method="POST" action="{{ route('team.positions.update') }}" class="space-y-4 pb-28">
        @csrf
        @method('PUT')
        @forelse ($byDepartment as $department => $members)
            <details open class="card overflow-hidden" data-department>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 hover:bg-[#fbf7ee]">
                    <span class="font-display text-xl text-slate-900">{{ $department }}</span>
                    <span class="text-sm text-slate-500">{{ $members->filter(fn ($m) => $m->checklist_position_id)->count() }}/{{ $members->count() }} {{ __('diatur') }}</span>
                </summary>
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($members as $member)
                        @php
                            $options = $positions->get($member->department_id, collect());
                            $suggested = $suggestions[$member->id] ?? null;
                            $review = $reviews[$member->id] ?? null;
                            $state = $stateOf($member);
                            $selected = (int) old("positions.{$member->id}", $review && $suggested ? $suggested : ($member->checklist_position_id ?? $suggested));
                        @endphp
                        <li class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] md:items-start md:gap-6" data-staff data-name="{{ mb_strtolower($member->name) }}" data-state="{{ $state }}">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ $member->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ __('Absensi') }}: {{ $member->position_title ?? '—' }}</p>
                                <span @class([
                                    'pill mt-2',
                                    'bg-emerald-50 text-emerald-800' => $state === 'set',
                                    'bg-amber-100 text-amber-900' => $state === 'review',
                                    'bg-slate-100 text-slate-600' => $state === 'none',
                                ])>{{ match ($state) { 'set' => __('Sudah diatur'), 'review' => __('Ditandai'), default => __('Belum diatur') } }}</span>
                            </div>

                            <div class="min-w-0 space-y-2">
                                <select name="positions[{{ $member->id }}]" class="input w-full" data-initial="{{ $selected ?: '' }}" aria-label="{{ __('Jabatan checklist') }} {{ $member->name }}">
                                    <option value="">{{ __('— Belum diatur —') }}</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected($selected === $option->id)>{{ $option->name }}</option>
                                    @endforeach
                                </select>

                                @if ($review)
                                    <p class="text-xs font-semibold text-amber-900">
                                        @if ($review['type'] === 'title')
                                            {{ __('Perlu dicek: jabatan di Absensi berubah dari ":from" menjadi ":to".', ['from' => $review['from'], 'to' => $review['to'] ?? __('(kosong)')]) }}
                                        @else
                                            {{ __('Perlu dicek: jabatan checklist saat ini (:position) bukan dari departemen pegawai ini.', ['position' => $review['position']]) }}
                                        @endif
                                    </p>
                                @endif

                                @if ($suggested)
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-amber-800">
                                        <span>{{ __('Saran dari Absensi, belum disimpan') }}</span>
                                        <button type="button" class="btn-secondary !px-3 !py-1 !text-xs" data-use-suggestion="{{ $suggested }}">{{ __('Pakai saran') }}</button>
                                    </div>
                                @endif

                                @if ($review && $member->checklist_position_id && $options->contains('id', $member->checklist_position_id))
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" name="confirm[]" value="{{ $member->id }}" class="h-4 w-4 rounded border-slate-300 accent-[#b08a3e]" data-confirm>
                                        {{ __('Jabatan checklist tetap benar') }}
                                    </label>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </details>
        @empty
            @if (auth()->user()->isHodWithoutDepartment())
                <p class="card border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"><span class="font-semibold">{{ __('Departemen Anda belum diatur.') }}</span> {{ __('Minta Administrator mencentang departemen Anda di Akses pengguna, supaya daftar tugas dan pegawainya muncul di sini.') }}</p>
            @else
                <p class="card p-6 text-sm text-slate-500">{{ __('Belum ada staff di departemen Anda.') }}</p>
            @endif
        @endforelse
        <p class="hidden text-center text-sm text-slate-500" data-filter-empty>{{ __('Tidak ada staff yang cocok dengan pencarian atau saringan.') }}</p>
    </form>

    <div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3">
            <span class="text-sm text-slate-600" data-dirty-count aria-live="polite">{{ __('Tidak ada perubahan') }}</span>
            <button type="submit" form="positions-form" class="btn-primary" data-save disabled>{{ __('Simpan jabatan') }}</button>
        </div>
    </div>

    <script>
        (() => {
            const form = document.getElementById('positions-form');
            const rows = Array.from(form.querySelectorAll('[data-staff]'));
            const search = document.querySelector('[data-filter-search]');
            const chips = Array.from(document.querySelectorAll('[data-filter]'));
            const dirtyCount = document.querySelector('[data-dirty-count]');
            const save = document.querySelector('[data-save]');
            const empty = document.querySelector('[data-filter-empty]');
            let filter = 'all';
            let submitting = false;

            const isDirty = (row) => row.querySelector('select').value !== row.querySelector('select').dataset.initial
                || Boolean(row.querySelector('[data-confirm]')?.checked);

            const refreshDirty = () => {
                const changed = rows.filter(isDirty).length;
                dirtyCount.textContent = changed ? `${changed} ${window.t('perubahan belum disimpan')}` : window.t('Tidak ada perubahan');
                save.disabled = changed === 0;
            };

            // Saring berdasarkan nama dan status; departemen yang kosong disembunyikan.
            const applyFilter = () => {
                const term = search.value.trim().toLowerCase();
                rows.forEach((row) => {
                    row.hidden = !((!term || row.dataset.name.includes(term)) && (filter === 'all' || row.dataset.state === filter));
                });
                document.querySelectorAll('[data-department]').forEach((section) => {
                    section.hidden = section.querySelectorAll('[data-staff]:not([hidden])').length === 0;
                });
                empty.hidden = rows.some((row) => !row.hidden);
                chips.forEach((chip) => chip.setAttribute('aria-pressed', String(chip.dataset.filter === filter)));
            };

            search.addEventListener('input', applyFilter);
            chips.forEach((chip) => chip.addEventListener('click', () => {
                filter = chip.dataset.filter;
                applyFilter();
            }));

            form.addEventListener('click', (event) => {
                const button = event.target.closest('[data-use-suggestion]');
                if (!button) return;
                const select = button.closest('[data-staff]').querySelector('select');
                select.value = button.dataset.useSuggestion;
                refreshDirty();
            });
            form.addEventListener('change', refreshDirty);
            form.addEventListener('submit', () => { submitting = true; });

            // Perubahan yang belum disimpan tidak hilang diam-diam saat meninggalkan halaman.
            window.addEventListener('beforeunload', (event) => {
                if (submitting || rows.every((row) => !isDirty(row))) return;
                event.preventDefault();
                event.returnValue = '';
            });

            refreshDirty();
        })();
    </script>
</x-app-layout>
