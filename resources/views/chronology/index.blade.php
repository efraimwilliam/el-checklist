<x-app-layout title="Chronology Report">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl text-slate-900">Chronology Report</h1>
            <p class="text-sm text-slate-500">{{ __('Laporan kronologi kejadian dengan tamu: data tamu, poin-poin berurutan, siap dicetak atau disalin ke WhatsApp.') }}</p>
        </div>
        <a href="{{ route('chronology.create') }}" class="btn-primary">{{ __('+ Buat laporan') }}</a>
    </div>

    {{-- Penyaring hanya untuk atasan (HOD, HR, GM, Administrator) --}}
    @if ($filters)
        <form method="GET" action="{{ route('chronology.index') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_auto] sm:items-end">
            <div>
                <label class="label" for="chronology-search">{{ __('Cari nama tamu atau nomor kamar') }}</label>
                <input id="chronology-search" type="search" name="q" value="{{ $search }}" maxlength="100" class="input w-full">
            </div>
            <div>
                <label class="label" for="chronology-status">{{ __('Status') }}</label>
                <select id="chronology-status" name="status" class="input w-full" onchange="this.form.submit()">
                    <option value="">{{ __('Semua') }}</option>
                    <option value="draft" @selected($status === 'draft')>Draft</option>
                    <option value="final" @selected($status === 'final')>Final</option>
                </select>
            </div>
            <button class="btn-primary">{{ __('Cari') }}</button>
        </form>
    @endif

    <section class="card mb-6 overflow-hidden">
        @forelse ($reports as $report)
            <a href="{{ route('chronology.show', $report) }}" class="flex flex-col gap-2 border-b border-slate-100 p-5 transition last:border-0 hover:bg-amber-50/50 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">{{ $report->guests->pluck('name')->implode(', ') }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">
                        @if ($report->guests->pluck('room_number')->filter()->isNotEmpty()){{ __('Kamar') }} {{ $report->guests->pluck('room_number')->filter()->implode(', ') }} · @endif{{ \App\Chronology\ReportText::stay($report) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $report->author?->name() ?? __('Pengguna dihapus') }}@if ($report->department) · {{ $report->department->name }}@endif · {{ $report->entries_count }} {{ __('poin') }} · {{ $report->created_at->translatedFormat('d M Y H:i') }}</p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $report->isFinal() ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">{{ $report->isFinal() ? 'Final' : 'Draft' }}</span>
            </a>
        @empty
            <div class="p-10 text-center">
                <p class="font-medium text-slate-800">{{ ($search || $status) ? __('Tidak ada laporan yang cocok.') : __('Belum ada Chronology Report.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ ($search || $status) ? __('Ubah kata cari atau filter status.') : __('Tulis laporan pertama untuk kejadian dengan tamu.') }}</p>
            </div>
        @endforelse
        @if ($reports->hasPages())
            <div class="flex items-center justify-between border-t border-slate-100 p-4 text-sm">
                <span class="text-slate-500">{{ __('Halaman') }} {{ $reports->currentPage() }} / {{ $reports->lastPage() }}</span>
                <span class="flex gap-2">
                    @if ($reports->previousPageUrl())<a class="btn-secondary" href="{{ $reports->previousPageUrl() }}">&larr; {{ __('Sebelumnya') }}</a>@endif
                    @if ($reports->hasMorePages())<a class="btn-secondary" href="{{ $reports->nextPageUrl() }}">{{ __('Berikutnya') }} &rarr;</a>@endif
                </span>
            </div>
        @endif
    </section>
</x-app-layout>
