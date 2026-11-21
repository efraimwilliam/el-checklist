<x-app-layout title="{{ __('Tautan Telegram') }}">
    <?php
        $pending = $users->whereNull('telegram_id');
        $all = $pending->map(fn ($u) => $u->name().' ('.($u->employee?->department?->name ?? '—').'): '.$links[$u->id])->join("\n");
    ?>
    <x-ui.page-header :title="__('Tautan Telegram')" :subtitle="__('Kirim tautan ke tiap pegawai (WhatsApp). Pegawai cukup mengetuk tautan lalu menekan Start di Telegram, tanpa login aplikasi. Tautan sekali pakai dan berlaku :n hari.', ['n' => $days])" />

    <div class="mb-5 grid max-w-md grid-cols-2 gap-3">
        <div class="card p-4"><p class="text-2xl font-semibold">{{ $users->count() - $pending->count() }}</p><p class="text-sm text-slate-500">{{ __('Sudah tertaut') }}</p></div>
        <div class="card p-4"><p class="text-2xl font-semibold">{{ $pending->count() }}</p><p class="text-sm text-slate-500">{{ __('Belum tertaut') }}</p></div>
    </div>

    @if ($pending->isNotEmpty())
        <section class="card mb-6 p-5">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 class="text-section">{{ __('Semua yang belum tertaut') }}</h2>
                <button type="button" class="btn-secondary btn-sm" data-copy-target="all-links">{{ __('Salin semua') }}</button>
            </div>
            <textarea id="all-links" readonly rows="5" class="input w-full font-mono text-xs">{{ $all }}</textarea>
        </section>
    @endif

    <ul class="space-y-2">
        @foreach ($users as $u)
            <li class="card flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">{{ $u->name() }} <span class="text-xs font-normal text-slate-500">· {{ $u->employee?->department?->name ?? '—' }} · {{ $u->role->label() }}</span></p>
                    @if (! $u->telegram_id)
                        <input id="link-{{ $u->id }}" readonly value="{{ $links[$u->id] }}" class="input mt-1 w-full max-w-xl py-1 font-mono text-xs">
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if ($u->telegram_id)
                        <span class="text-sm text-emerald-700">✅ {{ __('Tertaut') }}</span>
                        <form method="POST" action="{{ route('admin.telegram.unlink', $u) }}" data-confirm="{{ __('Putuskan Telegram :name?', ['name' => $u->name()]) }}">@csrf @method('DELETE')<x-ui.button variant="secondary" size="sm">{{ __('Putuskan') }}</x-ui.button></form>
                    @else
                        <button type="button" class="btn-secondary btn-sm" data-copy-target="link-{{ $u->id }}">{{ __('Salin') }}</button>
                        <form method="POST" action="{{ route('admin.telegram.rotate', $u) }}" data-confirm="{{ __('Buat tautan baru? Tautan lama tidak berlaku lagi.') }}">@csrf<x-ui.button variant="secondary" size="sm">{{ __('Ganti') }}</x-ui.button></form>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <script>
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-copy-target]');
            if (!button) return;
            const field = document.getElementById(button.dataset.copyTarget);
            field.select();
            (navigator.clipboard?.writeText(field.value) ?? Promise.reject()).catch(() => document.execCommand('copy')).finally(() => window.toast?.(window.t('Tersalin.')));
        });
    </script>
</x-app-layout>
