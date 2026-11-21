<x-app-layout title="{{ __('Barang tertinggal') }}">
    @php
        // Halaman ini dibuka dari obrolan (jendela form, ?obrolan=ID): setelah simpan, kartunya terkirim ke percakapan itu.
        $chatThreadId = request()->input('obrolan', old('obrolan'));
        $createOpen = request()->boolean('catat') || old('form') === 'create';
        $focusId = (int) (request()->query('barang') ?: old('item_id'));
        $labels = \App\Models\LostItem::statusLabels();
        $filters = ['disimpan' => __('Masih disimpan'), 'all' => __('Semua'), 'diambil' => $labels['diambil'], 'dikirim' => $labels['dikirim'], 'dilepas' => $labels['dilepas']];
        $keep = array_filter(['obrolan' => $chatThreadId, 'q' => $search]);
    @endphp

    <x-ui.page-header :title="'🧳 '.__('Barang tertinggal')" :subtitle="__('Daftar barang yang ditemukan. Bisa dicatat dari sini, dari obrolan (/t), atau dari grup Telegram: kirim foto berketerangan mis. 1203 dompet tertinggal.')">
        <x-slot:actions>
            @if ($chatThreadId)<button type="button" data-chat-tool-close class="btn-secondary btn-sm">{{ __('Tutup') }}</button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Catat barang baru --}}
    <details class="card mb-5 overflow-hidden" @if ($createOpen) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 [&::-webkit-details-marker]:hidden">
            <span class="font-semibold text-slate-900">+ {{ __('Catat barang tertinggal') }}</span>
            <span class="text-sm text-slate-500">{{ $storedCount }} {{ __('masih disimpan') }}</span>
        </summary>
        <form method="POST" action="{{ route('lost.store') }}" enctype="multipart/form-data" class="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="form" value="create">
            @if ($chatThreadId)<input type="hidden" name="obrolan" value="{{ $chatThreadId }}">@endif
            <x-ui.field name="room" :label="__('Nomor kamar')" :hint="__('Kosongkan bila ditemukan di luar kamar.')">
                <input id="room" name="room" value="{{ old('room', request()->query('kamar')) }}" maxlength="30" inputmode="numeric" autocomplete="off" class="input w-full" @if ($createOpen) autofocus @endif>
            </x-ui.field>
            <x-ui.field name="location" :label="__('Lokasi lain')" :hint="__('Mis. Lobby, Restoran, Kolam renang.')">
                <input id="location" name="location" value="{{ old('location') }}" maxlength="120" class="input w-full">
            </x-ui.field>
            <x-ui.field name="description" :label="__('Barang')" class="sm:col-span-2">
                <input id="description" name="description" value="{{ old('description') }}" maxlength="200" required class="input w-full" placeholder="{{ __('mis. dompet hitam, charger iPhone') }}">
            </x-ui.field>
            <x-ui.field name="stored_at" :label="__('Disimpan di')">
                <input id="stored_at" name="stored_at" value="{{ old('stored_at') }}" maxlength="120" list="lost-stored-places" class="input w-full" placeholder="{{ __('mis. Kantor HK, loker 3') }}">
                <datalist id="lost-stored-places">@foreach ($storedPlaces as $place)<option value="{{ $place }}">@endforeach</datalist>
            </x-ui.field>
            <x-ui.field name="photo" :label="__('Foto (opsional)')">
                <input id="photo" name="photo" type="file" accept="image/*" class="input w-full py-1.5 text-sm">
            </x-ui.field>
            <div class="flex justify-end sm:col-span-2">
                <x-ui.button>{{ $chatThreadId ? __('Catat & kirim ke obrolan') : __('Catat') }}</x-ui.button>
            </div>
        </form>
    </details>

    {{-- Penyaring --}}
    <form method="GET" action="{{ route('lost.index') }}" class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        @if ($chatThreadId)<input type="hidden" name="obrolan" value="{{ $chatThreadId }}">@endif
        <div class="flex flex-wrap gap-1.5">
            @foreach ($filters as $value => $label)
                <a href="{{ route('lost.index', $keep + ['status' => $value]) }}" @class(['rounded-full px-3 py-1.5 text-sm font-medium ring-1 transition', 'bg-ink text-white ring-ink' => $status === $value, 'bg-white text-slate-700 ring-slate-200 hover:bg-gold-50' => $status !== $value])>{{ $label }}</a>
            @endforeach
        </div>
        <input type="hidden" name="status" value="{{ $status }}">
        <label class="sr-only" for="lost-search">{{ __('Cari') }}</label>
        <input id="lost-search" type="search" name="q" value="{{ $search }}" class="input sm:w-64" placeholder="{{ __('Cari barang atau kamar') }}">
    </form>

    @if ($items->isEmpty())
        <x-ui.empty-state :title="$search || $status !== 'disimpan' ? __('Tidak ada yang cocok.') : __('Tidak ada barang yang sedang disimpan.')" :text="__('Barang yang ditemukan di kamar atau area hotel dicatat di sini, lengkap dengan foto dan siapa yang menerimanya.')" />
    @else
        <ul class="space-y-3">
            @foreach ($items as $item)
                <li id="barang-{{ $item->id }}" class="card flex gap-4 p-4 {{ $focusId === $item->id ? 'ring-2 ring-gold-500' : '' }}">
                    @if ($item->photo_path)
                        <a href="{{ route('lost.photo', $item) }}" data-photo-open data-alt="{{ $item->description }}" class="shrink-0">
                            <img src="{{ route('lost.photo', $item) }}" alt="{{ $item->description }}" loading="lazy" class="h-20 w-20 rounded-xl object-cover ring-1 ring-slate-200">
                        </a>
                    @else
                        <span class="grid h-20 w-20 shrink-0 place-items-center rounded-xl bg-gold-50 text-3xl ring-1 ring-gold-200" aria-hidden="true">🧳</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-slate-900">{{ $item->placeTitle() }}</span>
                            <x-ui.badge :tone="$item->tone()">{{ $labels[$item->status] }}</x-ui.badge>
                            <span class="text-xs text-slate-400">#{{ $item->id }}</span>
                        </p>
                        <p class="mt-0.5 text-sm text-slate-800">{{ $item->description }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ __('Ditemukan') }} {{ $item->found_at?->translatedFormat('d M H:i') }} · {{ $item->finder?->name() ?? '—' }}
                            @if ($item->stored_at) · {{ __('disimpan di') }} <b class="font-medium text-slate-700">{{ $item->stored_at }}</b>@endif
                        </p>
                        @if (! $item->isOpen())
                            <p class="mt-1 text-xs text-slate-600">{{ $labels[$item->status] }} {{ $item->resolved_at?->translatedFormat('d M H:i') }} · {{ $item->resolver?->name() }}@if ($item->resolution_note) · {{ $item->resolution_note }}@endif</p>
                        @endif

                        <details class="mt-2" @if ($focusId === $item->id) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-gold-700 hover:underline">{{ __('Perbarui status') }}</summary>
                            <form method="POST" action="{{ route('lost.status', $item) }}" class="mt-2 grid gap-3 rounded-xl bg-gold-50 p-3 sm:grid-cols-[12rem_minmax(0,1fr)_auto] sm:items-end">
                                @csrf
                                <input type="hidden" name="item_id" value="{{ $item->id }}">
                                @if ($chatThreadId)<input type="hidden" name="obrolan" value="{{ $chatThreadId }}">@endif
                                <div>
                                    <label class="label" for="status-{{ $item->id }}">{{ __('Status') }}</label>
                                    <select id="status-{{ $item->id }}" name="status" class="input w-full">
                                        @foreach ($labels as $value => $label)<option value="{{ $value }}" @selected(($focusId === $item->id ? old('status', $item->isOpen() ? 'diambil' : $item->status) : ($item->isOpen() ? 'diambil' : $item->status)) === $value)>{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="label" for="note-{{ $item->id }}">{{ __('Nama penerima / resi / alasan') }}</label>
                                    <input id="note-{{ $item->id }}" name="resolution_note" maxlength="300" class="input w-full" value="{{ $focusId === $item->id ? old('resolution_note') : $item->resolution_note }}" placeholder="{{ __('mis. Diambil Mr. Smith di FO') }}">
                                </div>
                                <x-ui.button size="sm">{{ __('Simpan') }}</x-ui.button>
                                @if ($focusId === $item->id && $errors->any())<p class="text-sm text-rose-700 sm:col-span-3">{{ $errors->first() }}</p>@endif
                            </form>
                        </details>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="mt-5"><x-ui.pagination :paginator="$items" /></div>
    @endif

    @if ($focusId)
        <script>document.getElementById('barang-{{ $focusId }}')?.scrollIntoView({ block: 'center' });</script>
    @endif
</x-app-layout>
