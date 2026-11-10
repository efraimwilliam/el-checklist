<x-app-layout title="Chronology Report">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a href="{{ route('chronology.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Semua laporan') }}</a>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $report->isFinal() ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">{{ $report->isFinal() ? 'Final' : 'Draft' }}</span>
            @can('update', $report)
                <a href="{{ route('chronology.edit', $report) }}" class="btn-secondary !px-4 !py-1.5 text-sm">{{ __('Ubah') }}</a>
                <form method="POST" action="{{ route('chronology.finalize', $report) }}">@csrf<button class="btn-secondary !px-4 !py-1.5 text-sm" data-confirm-button="{{ __('Finalisasi laporan? Setelah final, laporan terkunci dan tidak bisa diubah.') }}">{{ __('Finalisasi') }}</button></form>
            @endcan
            @can('reopen', $report)
                <form method="POST" action="{{ route('chronology.reopen', $report) }}">@csrf<button class="btn-secondary !px-4 !py-1.5 text-sm" data-confirm-button="{{ __('Buka kembali sebagai draft?') }}">{{ __('Buka kembali') }}</button></form>
            @endcan
            @can('delete', $report)
                <form method="POST" action="{{ route('chronology.destroy', $report) }}">@csrf @method('DELETE')
                    <button class="inline-flex items-center gap-1.5 rounded-full border border-rose-200 bg-white px-4 py-1.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50" data-confirm-button="{{ __('Hapus laporan ini? Tindakan ini tidak bisa dibatalkan.') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                        {{ __('Hapus') }}
                    </button>
                </form>
            @endcan
            <button type="button" class="btn-secondary !px-4 !py-1.5 text-sm" data-copy>{{ __('Salin teks') }}</button>
            <button type="button" class="btn-primary !px-4 !py-1.5 text-sm" onclick="window.print()">{{ __('Cetak / simpan PDF') }}</button>
        </div>
    </div>

    {{-- Teks siap tempel (WhatsApp / email), disalin tombol "Salin teks" --}}
    <textarea class="sr-only" readonly tabindex="-1" aria-hidden="true" data-copy-source>{{ $text }}</textarea>

    {{-- Laporan: bahasa Inggris tetap, sama seperti contoh --}}
    <article class="card mx-auto max-w-3xl p-8 font-sans text-[15px] leading-relaxed text-slate-900 print:max-w-none print:border-0 print:p-0 print:shadow-none" lang="en">
        <h1 class="text-center text-xl font-bold tracking-wide">{{ $title }}</h1>

        <dl class="mt-6 space-y-0.5">
            @foreach ($header as $row)
                <div class="grid grid-cols-[9.5rem_minmax(0,1fr)] gap-x-2"><dt>{{ $row['label'] }} :</dt><dd class="font-medium">{{ $row['value'] }}</dd></div>
            @endforeach
        </dl>

        <ol class="mt-6 space-y-3">
            @foreach ($report->entries as $entry)
                <li class="grid grid-cols-[1.75rem_minmax(0,1fr)] break-inside-avoid">
                    <span class="tabular-nums">{{ $loop->iteration }}.</span>
                    <span>
                        {{ $entry->body }}
                        @if ($entry->occurred_at)<span class="ml-1 whitespace-nowrap text-xs text-slate-400 print:hidden">({{ $entry->occurred_at->locale('en')->translatedFormat('d M Y, H:i') }})</span>@endif
                    </span>
                </li>
            @endforeach
        </ol>

        <p class="mt-6">{{ $statement }}</p>
        <p class="mt-5">{{ $closing }}</p>
        <p class="mt-6 font-bold">{{ $author['name'] }}</p>
        @if ($author['position'])<p>{{ $author['position'] }}</p>@endif
    </article>

    <p class="mx-auto mt-3 max-w-3xl text-xs text-slate-400 print:hidden">
        {{ __('Dibuat') }} {{ $report->created_at->translatedFormat('d M Y H:i') }}@if ($report->finalized_at) · {{ __('Final') }} {{ $report->finalized_at->translatedFormat('d M Y H:i') }}@endif
        @if ($report->roomIssue) · <a class="underline" href="{{ route('kamar.issues.show', $report->roomIssue) }}">{{ __('Kendala') }} #{{ $report->roomIssue->id }}</a>@endif
    </p>


    <script>
        document.querySelector('[data-copy]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const text = document.querySelector('[data-copy-source]').value;
            const done = () => { const label = button.textContent; button.textContent = @json(__('Tersalin ✓')); setTimeout(() => { button.textContent = label; }, 1800); };
            try { await navigator.clipboard.writeText(text); done(); } catch (error) {
                const source = document.querySelector('[data-copy-source]'); source.classList.remove('sr-only'); source.rows = 12; source.select(); document.execCommand('copy'); source.classList.add('sr-only'); done();
            }
        });
    </script>
</x-app-layout>
