<x-app-layout :title="__('Profil saya')">
    <div class="card mb-4 mt-2 p-5">
        <p class="eyebrow"><span class="h-px w-6 bg-gold-500"></span>{{ __('Akun sistem') }}</p>
        <h1 class="text-2xl leading-tight text-slate-900 sm:text-3xl">{{ $user->name() }}</h1>
        <p class="text-sm text-slate-500">{{ __('Username :username · password khusus Checklist', ['username' => $user->employee->username]) }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="card max-w-xl space-y-5 p-6">
        @csrf
        @method('PUT')

        <p class="rounded-xl bg-sky-50 px-3.5 py-2.5 text-sm text-slate-700">
            {{ __('Password ini hanya untuk Checklist. Tidak mengubah Sistem Absensi maupun Portal.') }}
        </p>

        <div>
            <label for="current_password" class="label">{{ __('Password saat ini') }}</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" maxlength="255" class="input">
            @error('current_password')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">{{ __('Password baru') }}</label>
            <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" class="input">
            <p class="mt-1 text-xs text-slate-500">{{ __('Minimal 8 karakter.') }}</p>
            @error('password')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">{{ __('Ulangi password baru') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="input">
        </div>

        <div class="flex items-center justify-end gap-3 pt-1">
            <button class="btn-primary">{{ __('Simpan password') }}</button>
        </div>
    </form>
</x-app-layout>
