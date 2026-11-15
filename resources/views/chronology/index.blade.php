<x-app-layout title="Chronology Report">
    <x-ui.page-header title="Chronology Report" :subtitle="__('Laporan kronologi kejadian dengan tamu: data tamu, poin-poin berurutan, siap dicetak atau disalin ke WhatsApp.')">
        <x-slot:actions><a href="{{ route('chronology.create') }}" class="btn-primary">{{ __('+ Buat laporan') }}</a></x-slot:actions>
    </x-ui.page-header>

    {{-- Penyaring hanya untuk atasan (HOD, HR, GM, Administrator) --}}
    @if ($filters)
        <form method="GET" action="{{ route('chronology.index') }}" data-filters class="card mb-5 grid gap-3 p-4 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_auto] sm:items-end">
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
            @php($filtered = $search || $status)
            <x-ui.empty-state class="m-4" :title="$filtered ? __('Tidak ada laporan yang cocok.') : __('Belum ada Chronology Report.')" :text="$filtered ? __('Ubah kata cari atau filter status.') : __('Tulis laporan pertama untuk kejadian dengan tamu.')">
                @if ($filtered)<a href="{{ route('chronology.index') }}" class="btn-secondary btn-sm">{{ __('Hapus filter') }}</a>@else<a href="{{ route('chronology.create') }}" class="btn-primary btn-sm">{{ __('+ Buat laporan') }}</a>@endif
            </x-ui.empty-state>
        @endforelse
        <x-ui.pagination :paginator="$reports" />
    </section>
</x-app-layout>
