{{-- Hubungkan akun Telegram ke akun ini (bot Telegram, lihat App\Telegram). Hanya tampil bila bot dikonfigurasi (TELEGRAM_BOT_TOKEN). --}}
@if (filled(config('services.telegram.token')))
    <?php
        $code = session('telegram_code');
        $bot = ltrim((string) config('services.telegram.username'), '@');
    ?>
    <section class="card mt-6 max-w-xl p-6">
        <h2 class="text-section">{{ __('Telegram') }}</h2>
        @if (auth()->user()->telegram_id)
            <p class="mt-1 text-sm text-slate-600">✅ {{ __('Akun Telegram Anda terhubung. Permintaan dan kendala yang Anda ketik di grup dicatat atas nama Anda, dan tombol di kartu memakai hak akses Anda.') }}</p>
            <form method="POST" action="{{ route('telegram.unlink') }}" class="mt-4" data-confirm="{{ __('Putuskan Telegram dari akun ini?') }}">
                @csrf @method('DELETE')
                <x-ui.button variant="secondary" size="sm">{{ __('Putuskan Telegram') }}</x-ui.button>
            </form>
        @elseif ($code)
            <p class="mt-1 text-sm text-slate-600">{{ __('Ketuk tombol di bawah, lalu tekan Start di Telegram. Kode berlaku 10 menit dan sekali pakai.') }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if ($bot !== '')<x-ui.button :href="'https://t.me/'.$bot.'?start='.$code">{{ __('Buka Telegram') }}</x-ui.button>@endif
                <span class="rounded-lg bg-gold-50 px-3 py-2 font-mono text-sm text-slate-700">/start {{ $code }}</span>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Tidak ada tombol? Kirim teks di atas ke bot secara pribadi.') }}</p>
        @else
            <p class="mt-1 text-sm text-slate-600">{{ __('Hubungkan Telegram supaya Anda bisa mengetik permintaan / kendala dan menekan tombol Saya ambil / Selesai langsung dari grup.') }}</p>
            <form method="POST" action="{{ route('telegram.code') }}" class="mt-4">
                @csrf
                <x-ui.button>{{ __('Hubungkan Telegram') }}</x-ui.button>
            </form>
        @endif
    </section>
@endif
