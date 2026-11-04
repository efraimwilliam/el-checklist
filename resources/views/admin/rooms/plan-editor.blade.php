<x-app-layout title="{{ __('Denah') }} {{ $plan->name }}">
    @vite(['resources/js/floor-plan-editor.js'])

    <a href="{{ route('admin.rooms.index', ['tab' => 'denah']) }}" class="text-sm text-sky-700 hover:underline">{{ __('← Semua denah') }}</a>
    <div class="mb-4 mt-1">
        <h1 class="text-3xl text-slate-900">{{ $plan->name }}</h1>
        <p class="text-sm text-slate-500">{{ __('Pilih kamar di daftar, lalu seret di atas gambar untuk menggambar kotaknya. Kotak bisa digeser dan diubah ukurannya.') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    {{-- Data denah --}}
    <details class="card mb-4 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('Pengaturan denah') }}</summary>
        <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_6rem_auto_auto] sm:items-end">
            @csrf @method('PUT')
            <div><label class="label" for="plan-edit-name">{{ __('Nama denah') }}</label><input id="plan-edit-name" name="name" value="{{ old('name', $plan->name) }}" required maxlength="120" class="input w-full"></div>
            <div><label class="label" for="plan-edit-floor">{{ __('Lantai') }}</label><input id="plan-edit-floor" name="floor" value="{{ old('floor', $plan->floor) }}" maxlength="30" class="input w-full"></div>
            <div><label class="label" for="plan-edit-sort">{{ __('Urutan') }}</label><input id="plan-edit-sort" name="sort" type="number" min="0" max="9999" value="{{ old('sort', $plan->sort) }}" class="input w-full"></div>
            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> {{ __('Aktif') }}</label>
            <button class="btn-secondary">{{ __('Simpan') }}</button>
        </form>
        <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="mt-3 border-t border-slate-100 pt-3">
            @csrf @method('DELETE')
            <button class="text-sm font-semibold text-rose-700 hover:underline" data-confirm-button="{{ __('Hapus denah ini? Kotak kamar di denah ini ikut terhapus. Data kamar dan kendala tidak berubah.') }}">{{ __('Hapus denah') }}</button>
        </form>
    </details>

    <div class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]" data-plan-editor
        data-save-url="{{ route('admin.plans.areas', $plan) }}"
        data-areas='@json($areas)'
        data-rooms='@json($rooms)'
        data-buildings='@json($buildings)'
        data-floor="{{ $plan->floor }}">

        <aside class="card flex flex-col gap-3 p-4 lg:max-h-[78vh]">
            {{-- Dua jenis kotak: satu kamar (denah lantai) atau satu gedung / area (peta kawasan) --}}
            <div class="grid grid-cols-2 gap-1 rounded-full border border-slate-200 bg-slate-50 p-1 text-sm font-semibold" role="tablist" aria-label="{{ __('Jenis kotak') }}">
                <button type="button" role="tab" class="rounded-full px-3 py-1.5 aria-selected:bg-white aria-selected:text-amber-900 aria-selected:shadow-sm text-slate-500" data-kind-tab="room" aria-selected="true">{{ __('Kamar') }}</button>
                <button type="button" role="tab" class="rounded-full px-3 py-1.5 aria-selected:bg-white aria-selected:text-amber-900 aria-selected:shadow-sm text-slate-500" data-kind-tab="building" aria-selected="false">{{ __('Gedung / area') }}</button>
            </div>
            <div data-pane="room" class="flex min-h-0 flex-1 flex-col gap-3">
                <div>
                    <label class="label" for="room-filter">{{ __('Cari kamar') }}</label>
                    <input id="room-filter" type="search" class="input w-full" placeholder="{{ __('mis. 305') }}" data-room-filter>
                </div>
                <p class="text-sm text-slate-500" data-room-count></p>
                <ul class="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1" data-room-list></ul>
            </div>
            <div data-pane="building" class="hidden min-h-0 flex-1 flex-col gap-3">
                <p class="text-sm text-slate-500">{{ __('Untuk peta kawasan: satu kotak mewakili satu gedung atau area. Warnanya dihitung dari kendala semua kamar yang kolom "Gedung"-nya sama dengan namanya.') }}</p>
                <form class="flex gap-2" data-building-form>
                    <label class="sr-only" for="building-new">{{ __('Nama gedung atau area baru') }}</label>
                    <input id="building-new" class="input min-w-0 flex-1" maxlength="60" placeholder="{{ __('Nama gedung / area baru') }}" data-building-new>
                    <button class="btn-secondary !px-4">{{ __('Tambah') }}</button>
                </form>
                <ul class="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1" data-building-list></ul>
            </div>
        </aside>

        <section class="min-w-0">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <div class="inline-flex overflow-hidden rounded-full border border-slate-300 bg-white" role="group" aria-label="{{ __('Zoom') }}">
                    <button type="button" class="px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="out" aria-label="{{ __('Perkecil') }}">−</button>
                    <button type="button" class="border-x border-slate-200 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="fit">{{ __('Pas') }}</button>
                    <button type="button" class="px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="in" aria-label="{{ __('Perbesar') }}">+</button>
                </div>
                <button type="button" class="btn-secondary !px-4 !py-1.5 text-sm" data-delete-area disabled>{{ __('Hapus kotak') }}</button>
                <span class="ml-auto text-sm text-slate-500" data-save-status aria-live="polite"></span>
                <button type="button" class="btn-primary !px-5 !py-2" data-save>{{ __('Simpan') }}</button>
            </div>
            <p class="mb-2 text-sm text-slate-600" data-hint></p>
            <div class="relative overflow-auto rounded-2xl border border-slate-200 bg-slate-100" style="max-height: 78vh" data-viewport>
                <div class="relative select-none" style="width: 100%" data-stage>
                    <img src="{{ route('denah.image', $plan) }}" alt="{{ __('Denah :name', ['name' => $plan->name]) }}" width="{{ $plan->width }}" height="{{ $plan->height }}" draggable="false" class="pointer-events-none block h-auto w-full max-w-none">
                    <div class="absolute inset-0" data-layer></div>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Tombol Delete menghapus kotak terpilih, tombol panah menggesernya sedikit (Shift = lebih jauh). Jangan lupa tekan Simpan.') }}</p>
        </section>
    </div>
</x-app-layout>
