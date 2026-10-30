<x-app-layout :title="$self ? __('Profil saya') : __('Akun :name', ['name' => $employee->name])">
    @php
        $action = $self ? route('profile.update') : route('team.account.update', $employee);
        $initials = collect(explode(' ', $employee->name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    @endphp

    @if (! $self)
        <a href="{{ route('team.tasks.edit', $employee) }}" class="text-sm font-semibold text-sky-700 hover:underline">{{ __('← Tugas :name', ['name' => $employee->name]) }}</a>
    @endif

    <div class="card mb-4 mt-2 flex flex-wrap items-center gap-4 p-5">
        <span class="hod-avatar !h-14 !w-14 !text-lg" aria-hidden="true">{{ $initials }}</span>
        <div class="min-w-0 flex-1">
            <p class="eyebrow"><span class="h-px w-6 bg-gold-500"></span>{{ $self ? __('Profil saya') : __('Akun pegawai') }}</p>
            <h1 class="text-2xl leading-tight text-slate-900 sm:text-3xl">{{ $employee->name }}</h1>
            <p class="text-sm text-slate-500">{{ $employee->department?->name }} · {{ $employee->position_title ?? __('Jabatan di Absensi belum diisi') }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ $action }}" class="card max-w-xl space-y-5 p-6">
        @csrf
        @method('PUT')

        <p class="rounded-xl bg-sky-50 px-3.5 py-2.5 text-sm text-slate-700">
            {{ __('Perubahan disimpan ke Sistem Absensi, lalu dipakai untuk login berikutnya.') }}
        </p>

        <div>
            <label for="username" class="label">{{ __('Username') }}</label>
            <input id="username" name="username" value="{{ old('username', $employee->username) }}" required minlength="3" maxlength="30" autocapitalize="none" autocomplete="username" class="input">
            <p class="mt-1 text-xs text-slate-500">{{ $self ? __('Diganti paling sering sebulan sekali. Username lama tetap bisa dipakai untuk login.') : __('Huruf, angka, titik, - atau _.') }}</p>
            @error('username')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>

        @if ($dashboard)
            <fieldset class="space-y-4 rounded-2xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-700">{{ __('Password dashboard') }}</legend>
                <div>
                    <label for="password" class="label">{{ __('Password baru') }}</label>
                    <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" class="input">
                    <p class="mt-1 text-xs text-slate-500">{{ __('Minimal 8 karakter. Kosongkan bila tidak diganti.') }}</p>
                    @error('password')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="password_confirmation" class="label">{{ __('Ketik ulang password baru') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" class="input">
                </div>
                <p class="text-xs text-slate-500">
                    @if ($self)
                        {{ __('Untuk mengganti password, isi password lama di kolom PIN atau password saat ini di bawah.') }}
                    @else
                        {{ __('Password baru berlaku sementara; pemilik wajib menggantinya saat login pertama.') }}
                    @endif
                </p>
            </fieldset>
        @endif

        <div>
            <label for="secret" class="label">{{ $self ? __('PIN atau password saat ini') : __('PIN atau password Anda (konfirmasi)') }}</label>
            <input id="secret" name="secret" type="password" required autocomplete="current-password" maxlength="255" class="input">
            <p class="mt-1 text-xs text-slate-500">{{ __('Untuk memastikan ini Anda. Tidak disimpan di aplikasi ini.') }}</p>
            @error('secret')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>

        @error('password_confirmation')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror

        <div class="flex items-center justify-end gap-3 pt-1">
            <button class="btn-primary">{{ __('Simpan ke Sistem Absensi') }}</button>
        </div>
    </form>
</x-app-layout>
