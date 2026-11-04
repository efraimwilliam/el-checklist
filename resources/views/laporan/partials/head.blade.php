{{-- Judul dan kalender rentang halaman Laporan (menu Laporan ada di header). $picker = true menampilkan kalender; $keep = filter lain yang ikut dikirim. --}}
@php
    $keep = $keep ?? [];
@endphp
@if ($picker ?? false)
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4 print:mb-3">
        <div>
            <h1 class="text-3xl text-slate-900">{{ $heading }}</h1>
            <p class="text-sm text-slate-500">{{ \App\Support\DateRange::label($from, $to) }} · {{ (int) $from->diffInDays($to) + 1 }} hari</p>
        </div>
        <form method="GET" action="{{ $action }}" class="print:hidden">
            @foreach ($keep as $name => $value)
                @if ($value !== null && $value !== '' && $value !== false)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <div class="relative w-max max-w-full" data-range-field data-max="{{ $today->toDateString() }}">
                <input type="hidden" name="from" value="{{ $from->toDateString() }}" data-range-from>
                <input type="hidden" name="to" value="{{ $to->toDateString() }}" data-range-to>
                <button type="button" class="input flex min-w-64 items-center gap-3 text-left" data-range-trigger aria-haspopup="dialog" aria-expanded="false">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                    <span class="font-medium text-slate-800" data-range-label>{{ \App\Support\DateRange::label($from, $to) }}</span>
                </button>
                <div class="cal-panel hidden" data-range-panel role="dialog" aria-label="{{ __('Pilih rentang tanggal') }}"></div>
            </div>
        </form>
    </div>
@endif
