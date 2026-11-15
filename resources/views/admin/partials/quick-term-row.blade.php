{{--
    Satu baris kamus yang bisa dibuka (details): ringkasan (nama, tujuan, jumlah kata, contoh kata) lalu isi (kata dengan ×,
    kolom tambah, dan pengaturan jenis / kategori). $group, $target, $title, $help, $terms, $kind (jenis permintaan) atau
    $category (kategori kendala) — keduanya SELALU diberikan (boleh null) supaya variabel perulangan tidak terbawa.
--}}
@php
    $slot = $group.'|'.$target;
    $anchor = 'kamus-'.$group.'-'.($target ?? 'x');
    $kindOpen = $kind && old('kind_form') === $kind->key;
    $categoryOpen = $category && old('category_form') === (string) $category->id;
    $open = session('quick_slot') === $slot || $kindOpen || $categoryOpen;
    $preview = $terms->take(5);
    $chip = 'inline-flex items-center gap-0.5 rounded-full bg-white py-0.5 pl-2.5 pr-0.5 text-sm text-slate-800 ring-1 ring-[#e6d8b8]';
@endphp
<details id="{{ $anchor }}" data-term-row data-words="{{ $terms->pluck('term')->implode('|') }} {{ mb_strtolower($title) }}" @if ($open) open @endif
         class="group/row rounded-xl border border-slate-200 bg-white transition open:border-[#d9c49a] open:shadow-sm">
    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-semibold text-slate-900">{{ $title }}</span>
                @if ($kind)
                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-800 ring-1 ring-sky-200">&rarr; {{ $kind->department?->name ?? __('Housekeeping (bawaan)') }}</span>
                    @if ($kind->has_quantity)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ __('Pakai jumlah') }}</span>@endif
                @elseif ($category)
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">&rarr; {{ $category->department?->name ?? __('Belum ditentukan') }}</span>
                @endif
                @if (($kind && ! $kind->is_active) || ($category && ! $category->is_active))<span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{{ __('Nonaktif') }}</span>@endif
            </span>
            {{-- Contoh kata saat tertutup; disembunyikan saat dibuka (daftar lengkap tampil di bawah). --}}
            <span class="mt-1 block truncate text-sm text-slate-500 group-open/row:hidden">
                @forelse ($preview as $term)<span class="font-medium text-slate-700">{{ $term->term }}</span>@if (! $loop->last)<span class="text-slate-300"> · </span>@endif @empty <span class="italic">{{ __('Belum ada kata.') }}</span> @endforelse
                @if ($terms->count() > $preview->count())<span class="text-slate-400"> +{{ $terms->count() - $preview->count() }}</span>@endif
            </span>
            @if (! empty($help))<span class="mt-1 hidden text-sm text-slate-500 group-open/row:block">{{ $help }}</span>@endif
        </span>
        <span class="shrink-0 text-xs tabular-nums text-slate-400">{{ $terms->count() }} {{ __('kata') }}</span>
        <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open/row:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </summary>

    <div class="border-t border-slate-100 px-4 pb-4 pt-3">
        <div class="flex flex-wrap gap-1.5">
            @forelse ($terms as $term)
                <form method="POST" action="{{ route('admin.quick-terms.destroy', $term) }}" class="{{ $chip }}" data-term-chip="{{ $term->term }}">
                    @csrf @method('DELETE')
                    <span>{{ $term->term }}</span>
                    <button class="inline-flex h-6 w-6 items-center justify-center rounded-full text-slate-400 hover:bg-rose-50 hover:text-rose-700" aria-label="{{ __('Hapus') }} {{ $term->term }}" title="{{ __('Hapus') }}" data-confirm-button="{{ __('Hapus ":term" dari kamus?', ['term' => $term->term]) }}">×</button>
                </form>
            @empty
                <p class="text-sm italic text-slate-400">{{ __('Belum ada kata.') }}</p>
            @endforelse
        </div>
        <form method="POST" action="{{ route('admin.quick-terms.store') }}#{{ $anchor }}" class="mt-3 flex gap-2">
            @csrf
            <input type="hidden" name="group" value="{{ $group }}">
            @if ($target !== null)<input type="hidden" name="target" value="{{ $target }}">@endif
            <label for="term-{{ $group }}-{{ $target ?? 'x' }}" class="sr-only">{{ __('Kata baru untuk') }} {{ $title }}</label>
            <input id="term-{{ $group }}-{{ $target ?? 'x' }}" name="term" maxlength="60" autocomplete="off" required class="input min-w-0 flex-1 py-1.5 text-sm" placeholder="{{ __('Tambah kata…') }}" value="{{ session('quick_slot') === $slot ? old('term') : '' }}">
            <button class="btn-secondary !px-4 !py-1.5 text-sm">{{ __('Tambah') }}</button>
        </form>
        @if (session('quick_slot') === $slot && $errors->quick->has('term'))<p class="mt-1 text-sm text-rose-700">{{ $errors->quick->first('term') }}</p>@endif

        @if ($kind)
            <form method="POST" action="{{ route('admin.quick-terms.kinds.update', $kind) }}" class="mt-4 grid gap-3 rounded-lg bg-gold-50 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                @csrf @method('PUT')
                <input type="hidden" name="kind_form" value="{{ $kind->key }}">
                <p class="text-xs font-semibold uppercase tracking-wider text-gold-700 sm:col-span-3">{{ __('Ubah jenis') }}</p>
                <div>
                    <label class="label" for="kind-label-{{ $kind->key }}">{{ __('Nama jenis') }}</label>
                    <input id="kind-label-{{ $kind->key }}" name="label" maxlength="80" required class="input w-full" value="{{ $kindOpen ? old('label') : $kind->label }}">
                </div>
                <div>
                    <label class="label" for="kind-dept-{{ $kind->key }}">{{ __('Diteruskan ke') }}</label>
                    <select id="kind-dept-{{ $kind->key }}" name="department_id" class="input w-full">
                        <option value="">{{ __('Housekeeping (bawaan)') }}</option>
                        @foreach ($departments as $department)
                            @continue($department->id === $defaultDepartmentId)
                            <option value="{{ $department->id }}" @selected((int) ($kindOpen ? old('department_id') : $kind->department_id) === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary !px-4 !py-2 text-sm">{{ __('Simpan') }}</button>
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm sm:col-span-3">
                    <label class="flex items-center gap-2"><input type="checkbox" name="has_quantity" value="1" class="h-5 w-5" @checked($kindOpen ? old('has_quantity') : $kind->has_quantity)> {{ __('Pakai jumlah (mis. "2 mineral water")') }}</label>
                    @if ($kind->key !== 'other')
                        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" class="h-5 w-5" @checked($kindOpen ? old('is_active') : $kind->is_active)> {{ __('Aktif (bisa dipilih dan dibaca dari obrolan)') }}</label>
                    @endif
                </div>
                @if ($kindOpen && $errors->kind->any())<p class="text-sm text-rose-700 sm:col-span-3">{{ $errors->kind->first() }}</p>@endif
            </form>
        @elseif ($category)
            <form method="POST" action="{{ route('admin.quick-terms.categories.update', $category) }}" class="mt-4 grid gap-3 rounded-lg bg-[#fbf5e8] p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                @csrf @method('PUT')
                <input type="hidden" name="category_form" value="{{ $category->id }}">
                <p class="text-xs font-semibold uppercase tracking-wider text-gold-700 sm:col-span-3">{{ __('Ubah kategori') }}</p>
                <div>
                    <label class="label" for="category-name-{{ $category->id }}">{{ __('Nama kategori') }}</label>
                    <input id="category-name-{{ $category->id }}" name="name" maxlength="100" required class="input w-full" value="{{ $categoryOpen ? old('name') : $category->name }}">
                </div>
                <div>
                    <label class="label" for="category-dept-{{ $category->id }}">{{ __('Departemen penanggung jawab') }}</label>
                    <select id="category-dept-{{ $category->id }}" name="department_id" class="input w-full">
                        <option value="">{{ __('Belum ditentukan (dipilih saat mencatat)') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) ($categoryOpen ? old('department_id') : $category->department_id) === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary !px-4 !py-2 text-sm">{{ __('Simpan') }}</button>
                <label class="flex items-center gap-2 text-sm sm:col-span-3"><input type="checkbox" name="is_active" value="1" class="h-5 w-5" @checked($categoryOpen ? old('is_active') : $category->is_active)> {{ __('Aktif (bisa dipilih dan dibaca dari obrolan)') }}</label>
                @if ($categoryOpen && $errors->category->any())<p class="text-sm text-rose-700 sm:col-span-3">{{ $errors->category->first() }}</p>@endif
            </form>
        @endif
    </div>
</details>
