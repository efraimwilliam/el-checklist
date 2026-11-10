<x-app-layout title="{{ $report->exists ? __('Ubah Chronology Report') : __('Chronology Report baru') }}">
    @vite(['resources/js/chronology-form.js'])
    @php
        $guestRows = old('guests', $guests);
        $entryRows = old('entries', $entries);
        $issueId = old('room_issue_id', $report->room_issue_id);
    @endphp

    <a href="{{ $report->exists ? route('chronology.show', $report) : route('chronology.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Kembali') }}</a>
    <h1 class="mb-1 mt-1 text-3xl text-slate-900">{{ $report->exists ? __('Ubah Chronology Report') : __('Chronology Report baru') }}</h1>
    <p class="mb-5 text-sm text-slate-500">{{ __('Isi laporan dalam bahasa Inggris. Simpan sebagai draft dulu; setelah Finalisasi laporan terkunci.') }}</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    {{-- Tempel satu kali: seluruh teks laporan dibaca otomatis (data tamu, tanggal, sumber booking, poin bernomor). --}}
    @unless ($report->exists)
        <section class="card mb-6 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Tempel dari WhatsApp / Word') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Salin laporan yang sudah ditulis, tempel di sini sekali saja. Sistem membaca Guest Name, Room Number, Nationality, Length of stay, Source of Booking, dan poin bernomor.') }}</p>
            <form method="POST" action="{{ route('chronology.paste') }}" class="mt-3 space-y-3">
                @csrf
                <label class="sr-only" for="paste-text">{{ __('Teks laporan') }}</label>
                <textarea id="paste-text" name="text" rows="8" maxlength="40000" required class="input w-full font-mono text-sm leading-relaxed" placeholder="*CHRONOLOGY REPORT*&#10;&#10;Guest Name : …&#10;Room Number : …&#10;Nationality : …&#10;Length of stay : 02 November 2026 - 05 November 2026&#10;Source of Booking : …&#10;&#10;1. …&#10;2. …">{{ old('text', $pasted) }}</textarea>
                <div class="flex flex-wrap gap-2">
                    <button name="mode" value="save" class="btn-primary">{{ __('Simpan langsung sebagai draft') }}</button>
                    <button name="mode" value="fill" class="btn-secondary">{{ __('Baca & isi form') }}</button>
                </div>
            </form>
        </section>

        @if ($parsed)
            <div class="mb-6 rounded-2xl border {{ $warnings ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} px-5 py-4 text-sm" role="status">
                <p class="font-semibold {{ $warnings ? 'text-amber-900' : 'text-emerald-900' }}">{{ $warnings ? __('Sebagian belum terbaca, lengkapi di bawah') : __('Teks terbaca. Periksa isinya di bawah, lalu simpan.') }}</p>
                <p class="mt-1 text-slate-700">
                    {{ collect($parsed['guests'])->where('name', '!=', '')->count() }} {{ __('tamu') }} · {{ count($parsed['entries']) }} {{ __('poin') }}
                    @if ($parsed['check_in']) · {{ \App\Chronology\ReportText::stay($report) }}@endif
                    @if ($parsed['author']) · {{ __('Penulis di teks') }}: {{ $parsed['author'] }}{{ $parsed['position'] ? ' ('.$parsed['position'].')' : '' }}@endif
                </p>
                @if ($warnings)<ul class="mt-2 list-disc space-y-0.5 pl-5 text-amber-900">@foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>@endif
                <p class="mt-2 text-xs text-slate-500">{{ __('Penulis laporan = akun yang menyimpan; nama dan jabatan di bagian bawah laporan diambil dari akun Anda.') }}</p>
            </div>
        @endif
    @endunless

    <form method="POST" action="{{ $report->exists ? route('chronology.update', $report) : route('chronology.store') }}" class="space-y-6">
        @csrf
        @if ($report->exists) @method('PUT') @endif
        <input type="hidden" name="room_issue_id" value="{{ $issueId }}">

        {{-- Data tamu --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Data tamu') }}</h2>
            @if ($issue)
                <p class="mt-1 text-sm text-slate-500">{{ __('Terhubung ke kendala') }} <a class="underline" href="{{ route('kamar.issues.show', $issue) }}">#{{ $issue->id }} · {{ $issue->placeName() }}</a></p>
            @endif

            <div class="mt-4" data-repeater data-min="1">
                <div class="space-y-3" data-repeater-list>
                    @foreach ($guestRows as $row)
                        <div class="grid grid-cols-[minmax(0,1fr)_7rem_auto] items-end gap-2" data-row>
                            <div><label class="label">Guest Name</label><input class="input w-full" maxlength="120" data-name="guests[{i}][name]" name="guests[{{ $loop->index }}][name]" value="{{ $row['name'] ?? '' }}"></div>
                            <div><label class="label">Room Number</label><input class="input w-full" maxlength="30" data-name="guests[{i}][room_number]" name="guests[{{ $loop->index }}][room_number]" value="{{ $row['room_number'] ?? '' }}"></div>
                            <button type="button" class="mb-0.5 inline-flex h-10 w-10 items-center justify-center rounded-full text-xl text-slate-400 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-30" data-row-remove aria-label="{{ __('Hapus tamu') }}">×</button>
                        </div>
                    @endforeach
                </div>
                <template>
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_auto] items-end gap-2" data-row>
                        <div><label class="label">Guest Name</label><input class="input w-full" maxlength="120" data-name="guests[{i}][name]"></div>
                        <div><label class="label">Room Number</label><input class="input w-full" maxlength="30" data-name="guests[{i}][room_number]"></div>
                        <button type="button" class="mb-0.5 inline-flex h-10 w-10 items-center justify-center rounded-full text-xl text-slate-400 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-30" data-row-remove aria-label="{{ __('Hapus tamu') }}">×</button>
                    </div>
                </template>
                <button type="button" class="mt-3 text-sm font-semibold text-sky-700 hover:underline" data-repeater-add>+ {{ __('Tambah tamu') }}</button>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="nationality">Nationality</label><input id="nationality" name="nationality" maxlength="80" class="input w-full" value="{{ old('nationality', $report->nationality) }}"></div>
                <div><label class="label" for="booking_source">Source of Booking</label><input id="booking_source" name="booking_source" maxlength="120" class="input w-full" value="{{ old('booking_source', $report->booking_source) }}"></div>
                <div><label class="label" for="check_in">Check-in</label><input id="check_in" type="date" name="check_in" required class="input w-full" value="{{ old('check_in', $report->check_in?->toDateString()) }}"></div>
                <div><label class="label" for="check_out">Check-out <span class="font-normal text-slate-400">{{ __('(opsional)') }}</span></label><input id="check_out" type="date" name="check_out" class="input w-full" value="{{ old('check_out', $report->check_out?->toDateString()) }}"></div>
            </div>
        </section>

        {{-- Kronologi --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Kronologi') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Satu poin satu kejadian, urut dari yang pertama. Geser dengan panah; nomor mengikuti urutan.') }}</p>

            <div class="mt-4" data-repeater data-min="1">
                <ol class="space-y-3" data-repeater-list>
                    @foreach ($entryRows as $row)
                        <li class="flex gap-2 rounded-xl border border-slate-200 bg-white p-3" data-row>
                            <span class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-50 text-sm font-semibold text-amber-900" data-row-number>{{ $loop->iteration }}</span>
                            <div class="min-w-0 flex-1 space-y-2">
                                <textarea rows="4" maxlength="4000" class="input w-full" data-name="entries[{i}][body]" name="entries[{{ $loop->index }}][body]" placeholder="{{ __('Contoh: On 2 November 2026 around 20:00 PM the guest asked the Front Office team regarding …') }}">{{ $row['body'] ?? '' }}</textarea>
                                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <label for="occurred-{{ $loop->index }}">{{ __('Waktu kejadian (opsional, hanya acuan di layar)') }}</label>
                                    <input id="occurred-{{ $loop->index }}" type="datetime-local" class="input !w-auto !py-1.5 text-sm" data-name="entries[{i}][occurred_at]" name="entries[{{ $loop->index }}][occurred_at]" value="{{ $row['occurred_at'] ?? '' }}">
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col gap-1">
                                <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-amber-500 disabled:opacity-30" data-row-up aria-label="{{ __('Naikkan') }}">↑</button>
                                <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-amber-500 disabled:opacity-30" data-row-down aria-label="{{ __('Turunkan') }}">↓</button>
                                <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-rose-400 hover:text-rose-700 disabled:opacity-30" data-row-remove aria-label="{{ __('Hapus poin') }}">×</button>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <template>
                    <li class="flex gap-2 rounded-xl border border-slate-200 bg-white p-3" data-row>
                        <span class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-50 text-sm font-semibold text-amber-900" data-row-number></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <textarea rows="4" maxlength="4000" class="input w-full" data-name="entries[{i}][body]"></textarea>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span>{{ __('Waktu kejadian (opsional, hanya acuan di layar)') }}</span>
                                <input type="datetime-local" class="input !w-auto !py-1.5 text-sm" data-name="entries[{i}][occurred_at]">
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-col gap-1">
                            <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-amber-500 disabled:opacity-30" data-row-up aria-label="{{ __('Naikkan') }}">↑</button>
                            <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-amber-500 disabled:opacity-30" data-row-down aria-label="{{ __('Turunkan') }}">↓</button>
                            <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-500 hover:border-rose-400 hover:text-rose-700 disabled:opacity-30" data-row-remove aria-label="{{ __('Hapus poin') }}">×</button>
                        </div>
                    </li>
                </template>
                <button type="button" class="mt-3 text-sm font-semibold text-sky-700 hover:underline" data-repeater-add>+ {{ __('Tambah poin') }}</button>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <a href="{{ $report->exists ? route('chronology.show', $report) : route('chronology.index') }}" class="btn-secondary text-center">{{ __('Batal') }}</a>
            <button name="intent" value="draft" class="btn-secondary">{{ __('Simpan draft') }}</button>
            <button name="intent" value="final" class="btn-primary" data-confirm-button="{{ __('Finalisasi laporan? Setelah final, laporan terkunci dan tidak bisa diubah.') }}">{{ __('Finalisasi') }}</button>
        </div>
    </form>
</x-app-layout>
