<x-app-layout title="{{ __('Log aplikasi') }}">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.settings.edit') }}" class="text-sm font-semibold text-sky-700 hover:underline">{{ __('← Pengaturan') }}</a>
            <h1 class="mt-1 text-3xl text-slate-900">{{ __('Log aplikasi') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Semua aktivitas tercatat: login, perubahan checklist, tugas, jabatan, akses, dan pengaturan. PIN tidak pernah dicatat.') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.logs.index') }}" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)] lg:items-end">
        <div>
            <label for="log-search" class="label">{{ __('Cari') }}</label>
            <input id="log-search" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Pengguna, objek, aksi, atau IP') }}" class="input">
        </div>
        <div>
            <label for="log-action" class="label">{{ __('Aksi') }}</label>
            <select id="log-action" name="aksi" class="input" onchange="this.form.submit()">
                <option value="">{{ __('Semua aksi') }}</option>
                @foreach ($actions as $option)
                    <option value="{{ $option }}" @selected($action === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <span class="label" id="log-range-caption">{{ __('Tanggal') }}</span>
            <div class="relative" data-range-field data-max="{{ $today->toDateString() }}">
                <input type="hidden" name="from" value="{{ $from->toDateString() }}" data-range-from>
                <input type="hidden" name="to" value="{{ $to->toDateString() }}" data-range-to>
                <button type="button" class="input flex w-full items-center gap-2 text-left" data-range-trigger aria-haspopup="dialog" aria-expanded="false" aria-labelledby="log-range-caption">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                    <span data-range-label>{{ \App\Support\DateRange::label($from, $to) }}</span>
                </button>
                <div class="cal-panel hidden" data-range-panel role="dialog" aria-label="{{ __('Pilih rentang tanggal') }}"></div>
            </div>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Waktu') }}</th>
                        <th class="px-4 py-3">{{ __('Pengguna') }}</th>
                        <th class="px-4 py-3">{{ __('Aksi') }}</th>
                        <th class="px-4 py-3">{{ __('Objek') }}</th>
                        <th class="px-4 py-3">{{ __('Perubahan') }}</th>
                        <th class="px-4 py-3">{{ __('IP') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-600">{{ $log->occurred_at->translatedFormat('d M Y H:i:s') }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-slate-800">{{ $log->user_name }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $log->action }}</code></td>
                            <td class="max-w-xs px-4 py-2.5 text-slate-600">
                                @if ($log->subject_label){{ $log->subject_label }}@else<span class="text-slate-400">—</span>@endif
                                @if ($log->subject_type)<span class="block text-xs text-slate-400">{{ $log->subject_type }} #{{ $log->subject_id }}</span>@endif
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                @if ($log->changes)
                                    <ul class="space-y-0.5">
                                        @foreach (array_slice($log->changes, 0, 4, true) as $field => $pair)
                                            <li><span class="font-semibold text-slate-700">{{ $field }}</span>: <span class="line-through text-slate-400">{{ \Illuminate\Support\Str::limit((string) $pair[0], 40) }}</span> → {{ \Illuminate\Support\Str::limit((string) $pair[1], 60) }}</li>
                                        @endforeach
                                        @if (count($log->changes) > 4)<li class="text-slate-400">+{{ count($log->changes) - 4 }} lainnya</li>@endif
                                    </ul>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-xs tabular-nums text-slate-500">{{ $log->ip ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('Tidak ada log pada filter ini.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
            <p>{{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} dari <strong class="text-slate-900">{{ $logs->total() }}</strong> {{ __('catatan') }}</p>
            <nav class="flex items-center gap-1.5" aria-label="{{ __('Halaman') }}">
                @if ($logs->onFirstPage())
                    <span class="rounded-full border border-slate-200 px-4 py-2 text-slate-300">{{ __('← Sebelumnya') }}</span>
                @else
                    <a href="{{ $logs->previousPageUrl() }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 hover:border-gold-500">{{ __('← Sebelumnya') }}</a>
                @endif
                <span class="px-2 tabular-nums">Halaman {{ $logs->currentPage() }} dari {{ $logs->lastPage() }}</span>
                @if ($logs->hasMorePages())
                    <a href="{{ $logs->nextPageUrl() }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 hover:border-gold-500">{{ __('Berikutnya →') }}</a>
                @else
                    <span class="rounded-full border border-slate-200 px-4 py-2 text-slate-300">{{ __('Berikutnya →') }}</span>
                @endif
            </nav>
        </div>
    </div>
</x-app-layout>
