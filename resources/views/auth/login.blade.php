<x-app-layout title="Login">
    <div class="mx-auto mt-10 max-w-sm">
        <h1 class="mb-1 text-xl font-semibold">{{ config('app.name') }}</h1>
        <p class="mb-6 text-sm text-slate-500">Masuk dengan username dan PIN yang sama dengan aplikasi absensi.</p>

        <form method="POST" action="{{ url('/login') }}" class="card space-y-4 p-6">
            @csrf
            <div>
                <label for="username" class="label">Username absensi</label>
                <input id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" autocapitalize="none" class="input">
            </div>
            <div>
                <label for="pin" class="label">PIN</label>
                <input id="pin" name="pin" type="password" inputmode="numeric" required autocomplete="current-password" maxlength="20" class="input">
            </div>
            @error('username')
                <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</p>
            @enderror
            @if (session('pin_not_set'))
                <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
                    <p class="font-medium">PIN belum dibuat</p>
                    <p class="mt-1">PIN dibuat di aplikasi absensi, lalu Anda kembali ke sini. Buka tautan ini di HP yang Anda pakai untuk absen.</p>
                    @if ($pinHelpUrl)
                        <a href="{{ $pinHelpUrl }}" class="btn-primary mt-3 w-full" rel="noopener">Buat PIN di aplikasi absensi</a>
                    @endif
                    <p class="mt-2 text-xs text-sky-800">Belum pernah absen dari HP itu? Scan QR absen di pintu masuk dan clock in dulu, lalu tekan tombol di atas lagi.</p>
                </div>
            @endif
            <button class="btn-primary w-full">Masuk</button>
        </form>

        <p class="mt-4 text-sm text-slate-500">
            Belum punya PIN atau lupa PIN? Buat atau reset di HP absensi: scan QR absen, lalu buka <strong>Akun saya</strong>.
            @if ($pinHelpUrl && ! session('pin_not_set'))
                <a href="{{ $pinHelpUrl }}" class="text-sky-700 underline" rel="noopener">Buka aplikasi absensi</a>
            @endif
        </p>
    </div>
</x-app-layout>
