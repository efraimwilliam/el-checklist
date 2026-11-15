<x-app-layout title="{{ __('Permintaan tamu') }}">
    @php
        $statusLabels = ['open' => __('Belum selesai'), 'all' => __('Semua'), ...\App\Models\GuestRequest::statusLabels()];
        $tones = ['baru' => 'bg-rose-50 text-rose-700', 'diambil' => 'bg-sky-50 text-sky-800', 'selesai' => 'bg-emerald-50 text-emerald-800'];
        $labels = \App\Models\GuestRequest::statusLabels();
        $minutes = fn ($v) => $v === null ? '—' : round($v).' '.__('mnt');
        // Tautan pintas (tombol + cepat, bookmark, QR): ?catat=1 membuka dialog, ?kamar= dan ?jenis= mengisi awal.
        // Dibuka dari obrolan (?obrolan=ID, html.chat-tool): hanya dialognya; setelah disimpan kartunya terkirim ke percakapan itu.
        $chatThreadId = request()->input('obrolan', old('obrolan'));
        $openDialog = $errors->any() || request()->boolean('catat') || filled($chatThreadId);
        $presetRoom = old('room', (string) request()->query('kamar', ''));
        $presetKinds = (array) old('kinds', array_filter([(string) request()->query('jenis', '')], fn ($kind) => isset($kinds[$kind])));
    @endphp

    @unless (filled($chatThreadId))
    <x-ui.page-header :title="__('Permintaan tamu')" :subtitle="__('Catat permintaan tamu (air mineral, handuk, laundry, MUR, dll.). Departemen tujuan menekan Saya ambil lalu Selesai.')">
        <x-slot:actions><button type="button" class="btn-primary" data-open-request-dialog>{{ __('+ Catat permintaan') }}</button></x-slot:actions>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5 [&>:last-child]:col-span-2 lg:[&>:last-child]:col-span-1">
        @foreach ([[__('Menunggu'), $summary['waiting'], 'text-rose-700'], [__('Sedang diambil'), $summary['claimed'], 'text-sky-800'], [__('Selesai hari ini'), $summary['done_today'], 'text-emerald-800'], [__('Rata-rata sampai diambil'), $minutes($summary['avg_claim']), 'text-slate-900'], [__('Rata-rata sampai selesai'), $minutes($summary['avg_done']), 'text-slate-900']] as [$label, $value, $tone])
            <div class="card p-4"><p class="text-xs text-slate-500">{{ $label }}</p><p class="font-display text-2xl tabular-nums {{ $tone }}">{{ $value }}</p></div>
        @endforeach
    </div>

    <form method="GET" action="{{ route('permintaan.index') }}" data-filters class="card mb-5 grid gap-3 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
            <label for="f-status" class="label">{{ __('Status') }}</label>
            <select id="f-status" name="status" data-default="open" class="input w-full" onchange="this.form.submit()">
                @foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-search" class="label">{{ __('Kamar') }}</label>
            <input id="f-search" type="search" name="q" value="{{ $search }}" maxlength="30" class="input w-full" placeholder="{{ __('mis. 1206') }}">
        </div>
        <button class="btn-primary">{{ __('Cari') }}</button>
    </form>

    <section class="card mb-6 overflow-hidden">
        @forelse ($requests as $item)
            @php
                $waiting = $item->status === 'baru' ? (int) $item->created_at->diffInMinutes(now()) : null;
                $late = $waiting !== null && $waiting >= 30 ? 'border-l-4 border-l-rose-500 bg-rose-50/40' : ($waiting !== null && $waiting >= 15 ? 'border-l-4 border-l-amber-400 bg-amber-50/40' : '');
            @endphp
            <div class="flex flex-col gap-3 border-b border-slate-100 p-5 last:border-0 sm:flex-row sm:items-start sm:justify-between {{ $late }}">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="text-section">{{ __('Kamar') }} {{ $item->room->number }}</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-sm font-medium text-slate-800">{{ $item->label() }}</span>
                        @if ($item->priority === 'mendesak')<span class="rounded-full bg-rose-600 px-2.5 py-0.5 text-xs font-semibold text-white">{{ __('Mendesak') }}</span>@endif
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $tones[$item->status] }}">{{ $labels[$item->status] }}</span>
                    </p>
                    @if ($item->note)<p class="mt-1 text-sm text-slate-700">{{ $item->note }}</p>@endif
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $item->created_at->translatedFormat('d M H:i') }} · {{ $item->requester?->employee?->name ?? __('Pengguna dihapus') }} · {{ $item->department?->name ?? __('Belum ditugaskan') }}
                        @if ($waiting !== null) · <span class="font-medium {{ $waiting >= 30 ? 'text-rose-700' : ($waiting >= 15 ? 'text-amber-800' : '') }}">{{ __('menunggu :n mnt', ['n' => $waiting]) }}</span>@endif
                        @if ($item->claimer) · {{ __('Diambil') }} {{ $item->claimer->name() }}@if ($item->minutesToClaim() !== null) ({{ $item->minutesToClaim() }} {{ __('mnt') }})@endif @endif
                        @if ($item->completed_at) · {{ __('selesai dalam :n mnt', ['n' => $item->minutesToDone()]) }}@endif
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @can('claim', $item)
                        <form method="POST" action="{{ route('permintaan.claim', $item) }}">@csrf<button class="btn-secondary">{{ __('Saya ambil') }}</button></form>
                    @endcan
                    @can('complete', $item)
                        <form method="POST" action="{{ route('permintaan.complete', $item) }}">@csrf<button class="btn-primary">{{ __('Selesai') }}</button></form>
                    @endcan
                    @can('release', $item)
                        <form method="POST" action="{{ route('permintaan.release', $item) }}">@csrf<button class="btn-secondary">{{ __('Lepas') }}</button></form>
                    @endcan
                    @can('delete', $item)
                        <form method="POST" action="{{ route('permintaan.destroy', $item) }}" data-confirm="{{ __('Permintaan :kind di Kamar :room dihapus permanen.', ['kind' => $item->label(), 'room' => $item->room->number]) }}" data-confirm-title="{{ __('Hapus permintaan ini?') }}" data-confirm-ok="{{ __('Hapus') }}">@csrf @method('DELETE')<button class="btn-secondary">{{ __('Hapus') }}</button></form>
                    @endcan
                </div>
            </div>
        @empty
            @php($filtered = $search || $status !== 'open')
            <x-ui.empty-state class="m-4" :title="__('Tidak ada permintaan.')" :text="$filtered ? __('Tidak ada yang cocok dengan filter ini.') : __('Belum ada permintaan yang belum selesai.')">
                @if ($filtered)<a href="{{ route('permintaan.index') }}" class="btn-secondary btn-sm">{{ __('Hapus filter') }}</a>@else<button type="button" class="btn-primary btn-sm" data-open-request-dialog>{{ __('+ Catat permintaan') }}</button>@endif
            </x-ui.empty-state>
        @endforelse

        <x-ui.pagination :paginator="$requests" />
    </section>

    @endunless

    <dialog id="request-dialog" @if ($openDialog) data-autoopen @endif data-chat-tool-main aria-labelledby="request-dialog-title" class="dialog-panel max-h-[calc(100dvh-2rem)] w-[min(36rem,calc(100vw-2rem))] overflow-y-auto">
        <form method="POST" action="{{ route('permintaan.store') }}" class="space-y-5 p-6">
            @csrf
            @if (filled($chatThreadId))<input type="hidden" name="obrolan" value="{{ $chatThreadId }}">@endif
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="request-dialog-title" class="text-section">{{ __('Catat permintaan tamu') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Ketik nomor kamar, centang yang diminta tamu.') }}</p>
                </div>
                <button type="button" class="dialog-close" aria-label="{{ __('Tutup') }}" data-close-request-dialog>×</button>
            </div>

            <div>
                <label for="req-room" class="label">{{ __('Nomor kamar') }}</label>
                {{-- Diketik (tanpa daftar pilihan); nomor yang tidak ada di Data kamar langsung diberi peringatan dan form tidak bisa dikirim. --}}
                <input id="req-room" name="room" value="{{ $presetRoom }}" maxlength="30" required autofocus autocomplete="off" inputmode="numeric" class="input w-full" aria-describedby="req-room-error" data-request-room>
                <p id="req-room-error" @class(['mt-1 text-sm text-rose-700', 'hidden' => ! $errors->has('room')]) data-request-room-error>{{ $errors->first('room') }}</p>
            </div>

            <fieldset>
                <legend class="label">{{ __('Permintaan') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($kinds as $key => [$label, $hasQty])
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm has-checked:border-gold-500 has-checked:bg-amber-50">
                            <input type="checkbox" name="kinds[]" value="{{ $key }}" class="h-5 w-5" @checked(in_array($key, $presetKinds, true))>
                            <span class="flex-1">{{ __($label) }}</span>
                            @if ($hasQty)<input type="number" name="qty[{{ $key }}]" value="{{ old('qty.'.$key, 1) }}" min="1" max="20" class="input w-16 py-1 text-center" aria-label="{{ __('Jumlah') }} {{ __($label) }}">@endif
                        </label>
                    @endforeach
                </div>
                @error('kinds')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                @error('kinds.*')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </fieldset>

            <div>
                <label for="req-note" class="label">{{ __('Catatan (opsional)') }}</label>
                <textarea id="req-note" name="note" rows="2" maxlength="500" class="input w-full">{{ old('note') }}</textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="req-priority" class="label">{{ __('Prioritas') }}</label>
                    <select id="req-priority" name="priority" class="input w-full">
                        <option value="biasa" @selected(old('priority', 'biasa') === 'biasa')>{{ __('Biasa') }}</option>
                        <option value="mendesak" @selected(old('priority') === 'mendesak')>{{ __('Mendesak (tamu menunggu)') }}</option>
                    </select>
                </div>
                <div>
                    <label for="req-department" class="label">{{ __('Diteruskan ke') }}</label>
                    <select id="req-department" name="assigned_department_id" class="input w-full">
                        <option value="">{{ __('Sesuai jenis (bawaan)') }}</option>
                        @foreach ($departments as $department)<option value="{{ $department->id }}" @selected((int) old('assigned_department_id') === $department->id)>{{ $department->name }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" data-close-request-dialog>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Catat') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const dialog = document.getElementById('request-dialog');

            // Nomor kamar dicocokkan ke Data kamar (aktif). Saat mengetik peringatan hilang bila cocok; saat pindah kolom / kirim, nomor
            // yang tidak ada ditandai merah dan form ditolak. Server tetap memeriksa lagi.
            const rooms = new Set(@json($rooms->map(fn ($room) => mb_strtolower($room->number))->values()));
            const roomInput = dialog.querySelector('[data-request-room]');
            const roomError = dialog.querySelector('[data-request-room-error]');
            const roomMessage = @json(__('Kamar :number tidak ada di data kamar. Periksa lagi nomornya.'));
            const checkRoom = (show) => {
                const value = roomInput.value.trim();
                const ok = value === '' || rooms.has(value.toLowerCase());
                roomInput.setCustomValidity(ok ? '' : roomMessage.replace(':number', value));
                roomInput.classList.toggle('!border-rose-400', !ok && (show || !roomError.classList.contains('hidden')));
                if (ok) roomError.classList.add('hidden');
                else if (show) { roomError.textContent = roomMessage.replace(':number', value); roomError.classList.remove('hidden'); }
            };
            roomInput.addEventListener('input', () => checkRoom(false));
            roomInput.addEventListener('change', () => checkRoom(true));
            roomInput.form.addEventListener('submit', (event) => {
                checkRoom(true);
                if (!roomInput.checkValidity()) { event.preventDefault(); roomInput.focus(); }
            });
            if (roomInput.value.trim() !== '') checkRoom(true);
            document.querySelectorAll('[data-open-request-dialog]').forEach((button) => button.addEventListener('click', () => dialog.showModal()));
            dialog.querySelectorAll('[data-close-request-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));
            if (dialog.hasAttribute('data-autoopen')) dialog.showModal();
        })();
    </script>
</x-app-layout>
