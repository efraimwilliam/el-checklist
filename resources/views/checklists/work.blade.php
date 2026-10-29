@php
    $items = $checklist->items;
    $number = 0;
    $hasSections = $items->pluck('section')->filter()->isNotEmpty();
    $extras = $checklist->extraTasks->map(fn ($e) => ['title' => $e->title, 'status' => $e->status->value, 'note' => $e->note])->all();
@endphp
<x-app-layout :title="'Checklist '.$checklist->employee->name">
    @vite(['resources/js/checklist.js'])

    <div data-checklist-root data-view="card"
         data-item-url="{{ route('checklists.items.update', [$checklist, '__ITEM__']) }}"
         data-meta-url="{{ route('checklists.meta', $checklist) }}"
         data-rest-done-url="{{ route('checklists.rest-done', $checklist) }}"
         data-clear-url="{{ route('checklists.clear', $checklist) }}"
         data-photo-url="{{ route('checklists.photos.store', [$checklist, '__ITEM__']) }}"
         data-photo-requirement="{{ $photoRequirement }}"
         data-photo-max="{{ $photoMax }}"
         data-pending-note="{{ config('checklist.pending_note_required') ? '1' : '0' }}">

        <div class="card mb-4 flex flex-wrap items-start justify-between gap-3 p-5">
            <div>
                <h1 class="text-3xl leading-tight text-slate-900">{{ $checklist->position_name }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $checklist->department->name }} · {{ $checklist->date->translatedFormat('d M Y') }} ·
                    Staff: <strong class="text-slate-700">{{ $checklist->employee->name }}</strong>
                    @if ($checklist->checked_by && ! $staffMode) · Dicek: {{ $checklist->checked_by }} @endif
                </p>
            </div>
            <a href="{{ route('checklists.show', $checklist) }}" class="btn-secondary">Lihat hasil</a>
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="cl-toggle" role="group" aria-label="Tampilan checklist">
                <button type="button" data-view-toggle="card" aria-pressed="true">Kartu</button>
                <button type="button" data-view-toggle="list" aria-pressed="false">Daftar</button>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <button type="button" data-rest-done class="btn-secondary !px-4 !py-1.5">Sisa tugas = Done</button>
                <button type="button" data-clear class="font-medium text-slate-600 hover:text-rose-700">Kosongkan</button>
            </div>
        </div>

        <div class="cl-stage">
        {{-- Hanya tampilan Kartu: posisi, progres per tugas dan loncat ke tugas lain --}}
        <div class="cl-card-only mb-3">
            <div class="mb-2 flex items-center justify-between text-sm">
                <span data-deck-count class="font-semibold text-slate-800">Tugas 1 dari {{ $items->count() }}</span>
                <button type="button" data-jump-toggle class="font-semibold text-sky-700">Lihat semua tugas</button>
            </div>
            <div class="cl-segments" data-segments></div>
            <div data-jump class="card mt-3 hidden p-3"><div class="flex flex-wrap gap-1.5" data-jump-list></div></div>
        </div>

        <div class="cl-deck" data-deck>
        @foreach ($items->groupBy(fn ($item) => $item->section ?? '') as $section => $group)
            <section class="cl-section {{ $loop->first ? '' : 'mt-5' }}">
                @if ($hasSections)
                    <div class="cl-section-head flex items-center justify-between px-1 pb-2 text-xs font-semibold uppercase tracking-[0.1em]">
                        <span class="text-gold-700">{{ $section !== '' ? $section : 'Tugas' }}</span>
                        <span class="text-slate-400">{{ $group->count() }} tugas</span>
                    </div>
                @endif
                <div class="cl-body">
                    @foreach ($group as $item)
                        @php
                            $number++;
                            $showNote = in_array($item->status?->value, ['ongoing', 'pending'], true) || filled($item->note);
                        @endphp
                        <div class="cl-item" data-item data-id="{{ $item->id }}" data-status="{{ $item->status?->value }}">
                            <div class="cl-card-head"><span>{{ $section !== '' ? $section : 'Tugas' }}</span><span>{{ $number }} / {{ $items->count() }}</span></div>
                            <div class="cl-row">
                                <div class="min-w-0">
                                    <p class="cl-title"><span data-num class="cl-num">{{ $number }}.</span>{{ $item->title }}</p>
                                    @if ($item->standard)
                                        <details class="cl-std">
                                            <summary>Cara / standar</summary>
                                            <p>{{ $item->standard }}</p>
                                        </details>
                                    @endif
                                </div>
                                <div class="cl-seg" role="radiogroup" aria-label="Status tugas {{ $number }}">
                                    @foreach ($statuses as $status)
                                        <label>
                                            <input type="radio" name="status-{{ $item->id }}" value="{{ $status->value }}" class="sr-only" @checked($item->status === $status)>
                                            <span>{{ $status->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="mt-2" data-photos>
                                <div class="flex flex-wrap items-center gap-2" data-photo-list>
                                    @foreach ($item->photos as $photo)
                                        <div class="cl-photo" data-photo data-delete-url="{{ route('checklists.photos.destroy', [$checklist, $photo]) }}">
                                            <a href="{{ $photo->url() }}" target="_blank" rel="noopener"><img src="{{ $photo->url() }}" alt="Foto tugas {{ $number }}" loading="lazy"></a>
                                            <button type="button" data-photo-remove aria-label="Hapus foto">✕</button>
                                        </div>
                                    @endforeach
                                    {{-- Dua pilihan: Kamera (dialog kamera di halaman) atau Galeri (pilih foto yang sudah ada). Input "capture" = cadangan bila kamera di halaman tidak bisa dipakai. --}}
                                    <div class="flex flex-wrap items-center gap-2 {{ $item->photos->count() >= $photoMax ? 'hidden' : '' }}" data-photo-add>
                                        <button type="button" class="cl-photo-add" data-photo-camera aria-label="Ambil foto dengan kamera untuk tugas {{ $number }}">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                            <span>Kamera</span>
                                        </button>
                                        <label class="cl-photo-add">
                                            <input type="file" accept="image/*" multiple class="sr-only" data-photo-input aria-label="Pilih foto dari galeri untuk tugas {{ $number }}">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m21 16-5-5-8 8"/></svg>
                                            <span>Galeri</span>
                                        </label>
                                        <input type="file" accept="image/*" capture="environment" class="sr-only" data-photo-native tabindex="-1" aria-hidden="true">
                                    </div>
                                </div>
                                <p data-photo-hint class="mt-1 hidden text-sm text-rose-700">Foto wajib untuk tugas ini.</p>
                            </div>
                            <button type="button" data-note-toggle class="cl-note-toggle {{ $showNote ? 'hidden' : '' }}">+ Catatan</button>
                            <div data-note class="mt-2 {{ $showNote ? '' : 'hidden' }}">
                                <input data-note-input value="{{ $item->note }}" maxlength="1000" class="input" placeholder="{{ $item->status?->value === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)' }}">
                                <p data-note-hint class="mt-1 hidden text-sm text-rose-700">Tulis alasan agar checklist ini lengkap.</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="cl-final" data-final>
            <div class="cl-card-head"><span>Ringkasan</span><span>{{ $items->count() }} tugas</span></div>
        {{-- Tugas tambahan: pekerjaan di luar daftar (mis. membantu banquet). Tidak dihitung dalam skor. --}}
        <div class="card mt-5 p-5" data-extras>
            <div class="flex items-center justify-between">
                <h2 class="font-display text-xl">Tugas tambahan</h2>
                <button type="button" data-extra-add class="text-sm font-semibold text-sky-700">+ Tambah tugas</button>
            </div>
            <p class="mt-1 text-sm text-slate-500">Pekerjaan di luar daftar, misalnya membantu banquet. Tidak dihitung dalam skor.</p>
            <div data-extra-list class="mt-3 space-y-2">
                @foreach ($extras as $extra)
                    @include('checklists.partials.extra-row', ['extra' => $extra, 'statuses' => $statuses])
                @endforeach
            </div>
            <template data-extra-template>
                @include('checklists.partials.extra-row', ['extra' => ['title' => '', 'status' => 'done', 'note' => ''], 'statuses' => $statuses])
            </template>
        </div>

        <div class="card mt-4 p-5">
            <label for="note" class="label">Catatan umum</label>
            <textarea id="note" data-meta="note" rows="2" maxlength="2000" class="input" placeholder="Temuan lain yang perlu dicatat">{{ $checklist->note }}</textarea>
        </div>
        </div>
        </div>

        {{-- Hanya tampilan Kartu: panah dan "Lanjut otomatis" --}}
        <div class="cl-card-only mt-3">
            <div class="flex items-center justify-between gap-3">
                <button type="button" class="cl-nav" data-prev aria-label="Tugas sebelumnya"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6"/></svg></button>
                <div class="text-center text-sm text-slate-500">
                    <p>Geser kiri atau kanan</p>
                    <label class="mt-1 inline-flex cursor-pointer items-center gap-2"><input type="checkbox" data-auto checked class="h-4 w-4">Lanjut otomatis</label>
                </div>
                <button type="button" class="cl-nav" data-next aria-label="Tugas berikutnya"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 4l6 6-6 6"/></svg></button>
            </div>
        </div>
        </div>

        {{-- Kamera di halaman: pratinjau langsung, potret, balik kamera depan / belakang --}}
        <dialog class="cl-camera" data-camera aria-label="Kamera">
            <video data-camera-video autoplay playsinline muted></video>
            <p class="px-4 pt-3 text-center text-sm text-white/80" data-camera-message>Menyalakan kamera…</p>
            <div class="flex items-center justify-between gap-3 p-4">
                <button type="button" class="btn !bg-white/10 !text-white hover:!bg-white/20" data-camera-close>Tutup</button>
                <button type="button" class="cl-shutter" data-camera-shoot aria-label="Ambil foto"></button>
                <button type="button" class="btn !bg-white/10 !text-white hover:!bg-white/20" data-camera-flip>Balik kamera</button>
            </div>
        </dialog>

        {{-- Bilah progres menempel di bawah layar --}}
        <div class="sticky bottom-3 z-10 mt-4 rounded-2xl bg-ink px-5 py-3 text-white shadow-lg">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div>
                    <p class="text-sm text-white/80">
                        <span data-progress class="font-semibold text-white">{{ $progress['filled'] }} / {{ $progress['total'] }}</span> tugas terisi · Skor sementara
                        <span data-score class="font-semibold">{{ $progress['score'] ?? 0 }}%</span>
                        <span data-complete class="ml-1 {{ $progress['complete'] ? '' : 'hidden' }}">· Lengkap ✓</span>
                    </p>
                    <p class="text-xs text-white/60"><button type="button" data-retry class="hidden underline">Coba simpan lagi</button><span data-save-state data-state="saved">Tersimpan otomatis</span></p>
                </div>
                @php($finishReady = ($progress['score'] ?? 0) === 100)
                <div class="text-right">
                    <a href="{{ route('checklists.show', $checklist) }}" data-finish class="btn-primary {{ $finishReady ? '' : 'pointer-events-none opacity-50' }}" aria-disabled="{{ $finishReady ? 'false' : 'true' }}" @if (! $finishReady) tabindex="-1" @endif>Selesai · lihat hasil</a>
                    <p data-finish-hint class="mt-1 text-xs text-white/60 {{ $finishReady ? 'hidden' : '' }}">Aktif saat skor 100%</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
