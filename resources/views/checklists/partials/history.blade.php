@if ($history->isNotEmpty())
    <div class="card mt-6 p-4">
        <h2 class="mb-2 text-sm font-semibold text-slate-600">Riwayat 14 hari</h2>
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($history as $past)
                <li class="flex items-center justify-between py-2">
                    <a href="{{ route('checklists.show', $past) }}" class="text-sky-700 hover:underline">{{ $past->date->translatedFormat('l, d M Y') }}</a>
                    <x-status-badge :status="$past->status" />
                </li>
            @endforeach
        </ul>
    </div>
@endif
