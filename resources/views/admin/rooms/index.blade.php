<x-app-layout title="{{ __('Data kamar') }}">
    @php
        $query = ['tab' => $tab, 'q' => $search, 'floor' => $floor, 'status' => $status];
        $filtered = $search || $floor || $status;
        $heading = ['kamar' => __('Data kamar'), 'kategori' => __('Kategori kendala'), 'denah' => __('Denah')][$tab];
        $intro = [
            'kamar' => __('Daftar kamar untuk mencatat kendala di kamar dan Peta kamar.'),
            'kategori' => __('Kategori masalah dan departemen penanggung jawab bawaannya.'),
            'denah' => __('Gambar denah per lantai atau kawasan, tempat kamar dan gedung ditandai untuk Peta kamar.'),
        ][$tab];
    @endphp

    {{-- Judul dan aksi utama --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl text-slate-900">{{ $heading }}</h1>
            <p class="text-sm text-slate-500">{{ $intro }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($tab === 'kamar')
                <a href="{{ route('admin.rooms.import') }}" class="btn-secondary">{{ __('Impor Excel') }}</a>
                <button type="button" class="btn-primary" data-open-dialog="add-room">{{ __('+ Tambah kamar') }}</button>
            @elseif ($tab === 'kategori')
                <button type="button" class="btn-primary" data-open-dialog="add-category">{{ __('+ Tambah kategori') }}</button>
            @else
                <button type="button" class="btn-primary" data-open-dialog="upload-plan">{{ __('+ Unggah denah') }}</button>
            @endif
        </div>
    </div>

    @if ($errors->any() && ! old('_form'))
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    @if ($tab === 'kamar')
    {{-- Ringkasan --}}
    <div class="mb-5 grid grid-cols-3 gap-3">
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Kamar') }}</p><p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $stats['total'] }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Aktif') }}</p><p class="mt-1 font-display text-3xl tabular-nums text-emerald-800">{{ $stats['active'] }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $stats['total'] - $stats['active'] }} {{ __('nonaktif') }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Lantai') }}</p><p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $stats['floors'] }}</p></div>
    </div>
    @endif

    @if ($tab === 'kamar')
        {{-- Saring --}}
        <form method="GET" action="{{ route('admin.rooms.index') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
            <input type="hidden" name="tab" value="kamar">
            <div>
                <label class="label" for="room-search">{{ __('Cari nomor kamar') }}</label>
                <input id="room-search" type="search" name="q" value="{{ $search }}" maxlength="30" class="input w-full" placeholder="{{ __('mis. 305') }}">
            </div>
            <div>
                <label class="label" for="room-floor">{{ __('Lantai') }}</label>
                <select id="room-floor" name="floor" class="input w-full" onchange="this.form.submit()">
                    <option value="">{{ __('Semua lantai') }}</option>
                    @foreach ($floors as $value)<option value="{{ $value }}" @selected($floor === (string) $value)>{{ __('Lantai') }} {{ $value }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="room-status">{{ __('Status') }}</label>
                <select id="room-status" name="status" class="input w-full" onchange="this.form.submit()">
                    <option value="">{{ __('Semua') }}</option>
                    <option value="aktif" @selected($status === 'aktif')>{{ __('Aktif') }}</option>
                    <option value="nonaktif" @selected($status === 'nonaktif')>{{ __('Nonaktif') }}</option>
                </select>
            </div>
            <button class="btn-primary">{{ __('Cari') }}</button>
        </form>

        {{-- Atur gedung untuk banyak kamar sekaligus (semua kamar yang cocok dengan penyaring), supaya kotak gedung di peta kawasan tahu kamarnya. --}}
        @if ($filtered && $rooms->total() > 0)
            <details class="card mb-5 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('Atur gedung untuk :n kamar yang cocok', ['n' => $rooms->total()]) }}</summary>
                <form method="POST" action="{{ route('admin.rooms.building') }}" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    @csrf
                    <input type="hidden" name="q" value="{{ $search }}"><input type="hidden" name="floor" value="{{ $floor }}"><input type="hidden" name="status" value="{{ $status }}">
                    <div>
                        <label class="label" for="bulk-building">{{ __('Nama gedung / area') }}</label>
                        <input id="bulk-building" name="building" maxlength="60" class="input w-full" placeholder="{{ __('mis. Zona 3, Villa A (kosong = hapus gedung)') }}">
                        <p class="mt-1 text-xs text-slate-500">{{ __('Berlaku untuk semua :n kamar yang cocok dengan penyaring di atas, bukan hanya yang tampil di halaman ini.', ['n' => $rooms->total()]) }}</p>
                    </div>
                    <button class="btn-secondary" data-confirm-button="{{ __('Atur gedung untuk :n kamar?', ['n' => $rooms->total()]) }}">{{ __('Terapkan') }}</button>
                </form>
            </details>
        @endif

        {{-- Daftar kamar: baris rapi, ubah lewat dialog --}}
        <section class="card mb-6 overflow-hidden">
            <div class="hidden grid-cols-[6rem_5rem_minmax(0,1fr)_minmax(0,1fr)_6rem_5rem_4rem] gap-3 border-b border-slate-100 bg-[#fbf6ea] px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500 lg:grid">
                <span>{{ __('Kamar') }}</span><span>{{ __('Lantai') }}</span><span>{{ __('Gedung') }}</span><span>{{ __('Tipe') }}</span><span>{{ __('Status') }}</span><span class="text-right">{{ __('Kendala') }}</span><span class="sr-only">{{ __('Aksi') }}</span>
            </div>
            @forelse ($rooms as $room)
                <div class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 border-b border-slate-100 px-5 py-3 last:border-0 lg:grid-cols-[6rem_5rem_minmax(0,1fr)_minmax(0,1fr)_6rem_5rem_4rem] {{ $room->is_active ? '' : 'bg-slate-50 text-slate-400' }}">
                    <p class="font-display text-xl {{ $room->is_active ? 'text-slate-900' : 'text-slate-400' }}">{{ $room->number }}</p>
                    <p class="col-span-2 text-sm text-slate-500 lg:col-span-1 lg:order-none">{{ $room->floor ? __('Lantai').' '.$room->floor : '—' }}</p>
                    <p class="col-span-2 text-sm lg:col-span-1">{{ $room->building ?: '—' }}</p>
                    <p class="col-span-2 text-sm lg:col-span-1">{{ $room->type ?: '—' }}</p>
                    <p class="col-span-2 lg:col-span-1"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $room->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $room->is_active ? __('Aktif') : __('Nonaktif') }}</span></p>
                    <p class="col-span-2 text-sm tabular-nums text-slate-500 lg:col-span-1 lg:text-right">{{ $room->issues_count }} {{ __('kendala') }}</p>
                    <div class="row-start-1 col-start-2 justify-self-end lg:col-start-auto lg:row-start-auto">
                        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[#f6efdf] hover:text-gold-700" aria-label="{{ __('Ubah kamar :room', ['room' => $room->number]) }}" title="{{ __('Ubah') }}"
                            data-edit-room data-url="{{ route('admin.rooms.update', $room) }}" data-id="{{ $room->id }}" data-number="{{ $room->number }}" data-floor="{{ $room->floor }}" data-building="{{ $room->building }}" data-type="{{ $room->type }}" data-active="{{ $room->is_active ? '1' : '0' }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        </button>
                    </div>
                </div>
            @empty
                <div class="p-10 text-center">
                    <p class="font-medium text-slate-800">{{ $filtered ? __('Tidak ada kamar yang cocok.') : __('Belum ada kamar.') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ $filtered ? __('Ubah kata cari atau filter lantai dan status.') : __('Tambahkan satu per satu, atau impor daftar kamar dari Excel.') }}</p>
                    @if ($filtered)<a href="{{ route('admin.rooms.index', ['tab' => 'kamar']) }}" class="mt-3 inline-block text-sm text-sky-700 hover:underline">{{ __('Hapus filter') }}</a>@endif
                </div>
            @endforelse
            @if ($rooms->hasPages())
                <div class="flex items-center justify-between border-t border-slate-100 p-4 text-sm">
                    <span class="text-slate-500">{{ __('Halaman') }} {{ $rooms->currentPage() }} / {{ $rooms->lastPage() }} · {{ $rooms->total() }} {{ __('kamar') }}</span>
                    <span class="flex gap-2">
                        @if ($rooms->previousPageUrl())<a class="btn-secondary" href="{{ $rooms->previousPageUrl() }}">&larr; {{ __('Sebelumnya') }}</a>@endif
                        @if ($rooms->hasMorePages())<a class="btn-secondary" href="{{ $rooms->nextPageUrl() }}">{{ __('Berikutnya') }} &rarr;</a>@endif
                    </span>
                </div>
            @endif
        </section>
    @elseif ($tab === 'denah')
        {{-- Denah: gambar per lantai, kotak area kamar ditandai di editor --}}
        @if ($plans->isEmpty())
            <section class="card mb-6 p-10 text-center">
                <p class="font-display text-xl text-slate-900">{{ __('Belum ada denah') }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ __('Unggah PDF atau gambar denah per lantai, lalu tandai kotak tiap kamar di atas gambarnya. Peta kamar di Laporan akan tampil seperti denah hotel.') }}</p>
                <button type="button" class="btn-primary mt-4" data-open-dialog="upload-plan">{{ __('+ Unggah denah') }}</button>
            </section>
        @else
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($plans as $plan)
                    <a href="{{ route('admin.plans.edit', $plan) }}" class="card group overflow-hidden transition hover:border-amber-300 {{ $plan->is_active ? '' : 'opacity-60' }}">
                        <div class="flex h-44 items-center justify-center bg-slate-50">
                            <img src="{{ route('denah.image', $plan) }}" alt="" loading="lazy" class="max-h-full max-w-full object-contain">
                        </div>
                        <div class="p-4">
                            <p class="font-display text-lg text-slate-900">{{ $plan->name }}@unless ($plan->is_active) <span class="text-xs font-normal text-slate-500">· {{ __('nonaktif') }}</span>@endunless</p>
                            <p class="mt-1 text-sm text-slate-500">{{ $plan->areas_count }} {{ __('dari') }} {{ $plan->rooms_total }} {{ __('kamar sudah ditandai') }}</p>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ $plan->rooms_total ? min(100, round($plan->areas_count / $plan->rooms_total * 100)) : 0 }}%"></div></div>
                            <p class="mt-3 text-sm font-semibold text-sky-700 group-hover:underline">{{ __('Atur kamar di denah') }} &rarr;</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    @else
        {{-- Kategori kendala --}}
        <section class="card mb-6 overflow-hidden">
            <div class="border-b border-slate-100 p-5">
                <h2 class="font-display text-xl text-slate-900">{{ __('Kategori kendala') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Departemen penanggung jawab terisi otomatis saat kategori dipilih di form kendala, dan bisa diganti di sana.') }}</p>
            </div>
            @foreach ($categories as $category)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 {{ $category->is_active ? '' : 'bg-slate-50' }}">
                    <div class="min-w-0">
                        <p class="font-medium {{ $category->is_active ? 'text-slate-900' : 'text-slate-400' }}">{{ $category->name }}</p>
                        <p class="text-sm text-slate-500">{{ $category->department?->name ?? __('Tanpa departemen bawaan') }} · {{ $category->issues_count }} {{ __('kendala') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $category->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $category->is_active ? __('Aktif') : __('Nonaktif') }}</span>
                        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[#f6efdf] hover:text-gold-700" aria-label="{{ __('Ubah kategori :name', ['name' => $category->name]) }}" title="{{ __('Ubah') }}"
                            data-edit-category data-url="{{ route('admin.categories.update', $category) }}" data-id="{{ $category->id }}" data-name="{{ $category->name }}" data-department="{{ $category->department_id }}" data-active="{{ $category->is_active ? '1' : '0' }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        </button>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Dialog: tambah kamar --}}
    <dialog id="add-room" aria-labelledby="add-room-title" class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="{{ route('admin.rooms.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="_form" value="add-room">
            <div class="flex items-start justify-between gap-3">
                <h2 id="add-room-title" class="font-display text-2xl text-slate-900">{{ __('Tambah kamar') }}</h2>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-dialog>×</button>
            </div>
            <div><label class="label" for="add-number">{{ __('Nomor kamar') }}</label><input id="add-number" name="number" required maxlength="30" class="input w-full" value="{{ old('_form') === 'add-room' ? old('number') : '' }}"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label" for="add-floor">{{ __('Lantai') }}</label><input id="add-floor" name="floor" maxlength="30" class="input w-full" value="{{ old('_form') === 'add-room' ? old('floor') : '' }}"></div>
                <div><label class="label" for="add-type">{{ __('Tipe') }}</label><input id="add-type" name="type" maxlength="60" class="input w-full" value="{{ old('_form') === 'add-room' ? old('type') : '' }}"></div>
            </div>
            <div><label class="label" for="add-building">{{ __('Gedung') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label><input id="add-building" name="building" maxlength="60" class="input w-full" value="{{ old('_form') === 'add-room' ? old('building') : '' }}"></div>
            @if ($errors->any() && old('_form') === 'add-room')<p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Simpan kamar') }}</button>
            </div>
        </form>
    </dialog>

    {{-- Dialog: ubah kamar --}}
    <dialog id="edit-room" aria-labelledby="edit-room-title" class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="" class="space-y-4 p-6" data-edit-form>
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="edit-room">
            <input type="hidden" name="_id" value="">
            <div class="flex items-start justify-between gap-3">
                <h2 id="edit-room-title" class="font-display text-2xl text-slate-900">{{ __('Ubah kamar') }}</h2>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-dialog>×</button>
            </div>
            <div><label class="label" for="edit-number">{{ __('Nomor kamar') }}</label><input id="edit-number" name="number" required maxlength="30" class="input w-full"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label" for="edit-floor">{{ __('Lantai') }}</label><input id="edit-floor" name="floor" maxlength="30" class="input w-full"></div>
                <div><label class="label" for="edit-type">{{ __('Tipe') }}</label><input id="edit-type" name="type" maxlength="60" class="input w-full"></div>
            </div>
            <div><label class="label" for="edit-building">{{ __('Gedung') }}</label><input id="edit-building" name="building" maxlength="60" class="input w-full"></div>
            <label class="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="mt-0.5" id="edit-active">
                <span><span class="font-medium">{{ __('Kamar aktif') }}</span><span class="block text-xs text-slate-500">{{ __('Kamar nonaktif tidak muncul di pilihan kendala, riwayatnya tetap ada.') }}</span></span>
            </label>
            @if ($errors->any() && old('_form') === 'edit-room')<p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Simpan') }}</button>
            </div>
        </form>
    </dialog>

    {{-- Dialog: tambah kategori --}}
    <dialog id="add-category" aria-labelledby="add-category-title" class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="{{ route('admin.categories.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="_form" value="add-category">
            <div class="flex items-start justify-between gap-3">
                <h2 id="add-category-title" class="font-display text-2xl text-slate-900">{{ __('Tambah kategori') }}</h2>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-dialog>×</button>
            </div>
            <div><label class="label" for="cat-add-name">{{ __('Nama kategori') }}</label><input id="cat-add-name" name="name" required maxlength="100" class="input w-full" value="{{ old('_form') === 'add-category' ? old('name') : '' }}"></div>
            <div>
                <label class="label" for="cat-add-department">{{ __('Departemen penanggung jawab') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label>
                <select id="cat-add-department" name="department_id" class="input w-full">
                    <option value="">{{ __('Tanpa departemen bawaan') }}</option>
                    @foreach ($departments as $department)<option value="{{ $department->id }}" @selected(old('_form') === 'add-category' && (string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach
                </select>
            </div>
            @if ($errors->any() && old('_form') === 'add-category')<p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Simpan kategori') }}</button>
            </div>
        </form>
    </dialog>

    {{-- Dialog: ubah kategori --}}
    <dialog id="edit-category" aria-labelledby="edit-category-title" class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="" class="space-y-4 p-6" data-edit-form>
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="edit-category">
            <input type="hidden" name="_id" value="">
            <div class="flex items-start justify-between gap-3">
                <h2 id="edit-category-title" class="font-display text-2xl text-slate-900">{{ __('Ubah kategori') }}</h2>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-dialog>×</button>
            </div>
            <div><label class="label" for="cat-edit-name">{{ __('Nama kategori') }}</label><input id="cat-edit-name" name="name" required maxlength="100" class="input w-full"></div>
            <div>
                <label class="label" for="cat-edit-department">{{ __('Departemen penanggung jawab') }}</label>
                <select id="cat-edit-department" name="department_id" class="input w-full">
                    <option value="">{{ __('Tanpa departemen bawaan') }}</option>
                    @foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach
                </select>
            </div>
            <label class="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="mt-0.5" id="cat-edit-active">
                <span><span class="font-medium">{{ __('Kategori aktif') }}</span><span class="block text-xs text-slate-500">{{ __('Kategori nonaktif tidak muncul di pilihan kendala baru.') }}</span></span>
            </label>
            @if ($errors->any() && old('_form') === 'edit-category')<p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Simpan') }}</button>
            </div>
        </form>
    </dialog>

    {{-- Dialog: unggah denah. PDF diubah menjadi gambar di browser (pilih halamannya); yang dikirim ke server hanya gambar. --}}
    @vite(['resources/js/floor-plan-upload.js'])
    <dialog id="upload-plan" aria-labelledby="upload-plan-title" class="m-auto w-[min(40rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
        <form method="POST" action="{{ route('admin.plans.store') }}" enctype="multipart/form-data" class="space-y-4 p-6" data-plan-upload data-pdf-label="{{ __('Halaman') }}">
            @csrf
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="upload-plan-title" class="font-display text-2xl text-slate-900">{{ __('Unggah denah') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('PDF, PNG, atau JPG. Bila PDF punya banyak halaman, pilih halaman denah yang dipakai.') }}</p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-2xl leading-none text-slate-500 hover:bg-[#f6efdf]" aria-label="{{ __('Tutup') }}" data-close-dialog>×</button>
            </div>
            <div>
                <label class="label" for="plan-file">{{ __('Berkas denah') }}</label>
                <input id="plan-file" type="file" accept=".pdf,application/pdf,image/png,image/jpeg,image/webp" class="input w-full" data-plan-file>
            </div>
            <div class="hidden" data-plan-pages-wrap>
                <p class="label">{{ __('Pilih halaman') }}</p>
                <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto" data-plan-pages></div>
            </div>
            <div class="hidden rounded-xl border border-slate-200 bg-slate-50 p-2" data-plan-preview-wrap>
                <canvas class="mx-auto max-h-64 w-auto max-w-full" data-plan-preview></canvas>
            </div>
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]">
                <div><label class="label" for="plan-name">{{ __('Nama denah') }}</label><input id="plan-name" class="input w-full" maxlength="120" placeholder="{{ __('mis. Lantai 3') }}" data-plan-name></div>
                <div><label class="label" for="plan-floor">{{ __('Lantai') }} <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label><input id="plan-floor" class="input w-full" maxlength="30" placeholder="3" data-plan-floor></div>
            </div>
            <p class="hidden rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" data-plan-error role="alert"></p>
            <p class="hidden text-sm text-slate-500" data-plan-status role="status"></p>
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary" data-plan-submit disabled>{{ __('Unggah dan tandai kamar') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const dialogs = Object.fromEntries([...document.querySelectorAll('dialog')].map((dialog) => [dialog.id, dialog]));
            document.querySelectorAll('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => dialogs[button.dataset.openDialog].showModal()));
            document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));

            const fill = (dialog, values) => {
                const form = dialog.querySelector('[data-edit-form]');
                form.action = values.url;
                form.querySelector('[name="_id"]').value = values.id;
                Object.entries(values.fields).forEach(([name, value]) => {
                    const field = form.querySelector(`[name="${name}"]:not([type="hidden"])`);
                    if (field) field.value = value ?? '';
                });
                const active = form.querySelector('input[type="checkbox"][name="is_active"]');
                active.checked = values.active === '1';
            };
            document.querySelectorAll('[data-edit-room]').forEach((button) => button.addEventListener('click', () => {
                const d = button.dataset;
                fill(dialogs['edit-room'], { url: d.url, id: d.id, active: d.active, fields: { number: d.number, floor: d.floor, building: d.building, type: d.type } });
                dialogs['edit-room'].showModal();
            }));
            document.querySelectorAll('[data-edit-category]').forEach((button) => button.addEventListener('click', () => {
                const d = button.dataset;
                fill(dialogs['edit-category'], { url: d.url, id: d.id, active: d.active, fields: { name: d.name, department_id: d.department } });
                dialogs['edit-category'].showModal();
            }));

            // Setelah gagal simpan, dialog yang sama dibuka lagi dengan isian terakhir.
            const reopen = @json(['form' => old('_form'), 'id' => old('_id'), 'values' => old()]);
            if (reopen.form && dialogs[reopen.form]) {
                if (reopen.form.startsWith('edit-')) {
                    const trigger = document.querySelector(`[data-${reopen.form}][data-id="${reopen.id}"]`);
                    if (trigger) {
                        trigger.click();
                        Object.entries(reopen.values).forEach(([name, value]) => {
                            const field = dialogs[reopen.form].querySelector(`[name="${name}"]:not([type="hidden"]):not([type="checkbox"])`);
                            if (field && typeof value === 'string') field.value = value;
                        });
                    }
                } else {
                    dialogs[reopen.form].showModal();
                }
            }
        })();
    </script>
</x-app-layout>
