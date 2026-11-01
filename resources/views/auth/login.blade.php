@php($photo = asset('images/pantai-'.(now()->dayOfYear % 2 + 1).'.jpg'))
<x-guest-layout title="{{ __('Masuk') }}">
    <div class="min-h-dvh lg:flex">
        {{-- Foto pantai: kepala halaman di HP, setengah layar di komputer. --}}
        <section class="relative h-80 bg-cover bg-center lg:h-auto lg:w-[52%]" style="background-image: url('{{ $photo }}')">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/5 to-black/25"></div>
            <div class="relative flex h-full flex-col justify-between p-6 pb-14 lg:p-12">
                <div>
                    <img src="{{ asset('images/logo-el.png') }}" alt="éL Hotel &amp; Resort" class="h-14 w-auto lg:h-24">
                    <p class="mt-2 text-[10px] font-medium uppercase tracking-[0.2em] text-gold-400 lg:text-xs">Hotel &amp; Resort · ★★★★ · Bali - Sanur</p>
                </div>
                <div class="text-white">
                    <p class="eyebrow !text-gold-400"><span class="h-px w-8 bg-gold-500"></span>{{ __('Daily Department Checklist') }}</p>
                    <p class="mt-2 max-w-md font-display text-2xl leading-snug lg:text-4xl">{{ __('Standar terjaga, setiap hari, di setiap departemen.') }}</p>
                </div>
            </div>
        </section>

        <section class="relative flex flex-1 items-start justify-center px-4 pb-12 lg:items-center lg:overflow-hidden lg:px-12">
            {{-- Pola gelombang emas di tepi kanan --}}
            <div class="pointer-events-none absolute inset-y-0 right-0 hidden w-1/4 opacity-90 lg:block" style="background-image: url('{{ asset('images/pola-seigaiha.svg') }}'); background-size: 120px 60px; -webkit-mask-image: linear-gradient(to right, transparent, #000 60%); mask-image: linear-gradient(to right, transparent, #000 60%)"></div>

            <div class="card relative -mt-8 w-full max-w-sm p-7 lg:mt-0">
                <p class="eyebrow"><span class="h-px w-6 bg-gold-500"></span>{{ __('Selamat datang') }}</p>
                <h1 class="mt-2 text-4xl text-slate-900">{{ __('Masuk') }}</h1>
                <p class="mt-1 text-sm text-slate-500">éL Hotel &amp; Resort Bali Sanur</p>

                <div class="mb-4 flex justify-end"><x-language-switch tone="light" /></div>
            @if (\App\Support\PortalClient::enabled())
                <a href="{{ route('sso.login') }}" class="btn-primary mt-6 block w-full text-center">{{ __('Masuk lewat Portal') }}</a>
                @error('username')
                    <p class="mt-4 rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700">{{ $message }}</p>
                @enderror
            @endif
            @if (\App\Support\PortalClient::allowsPasswordLogin())
            @if (\App\Support\PortalClient::enabled())
                <p class="mt-5 text-center text-xs uppercase tracking-wider text-slate-400">{{ __('atau dengan username dan PIN') }}</p>
            @endif
            <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="username" class="label">{{ __('Username') }}</label>
                        <input id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" autocapitalize="none" class="input">
                    </div>
                    <div>
                        <label for="pin" class="label">{{ __('PIN atau password') }}</label>
                        <input id="pin" name="pin" type="password" required autocomplete="current-password" maxlength="255" class="input">
                    </div>
                    @if (! \App\Support\PortalClient::enabled())
                        @error('username')
                            <p class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700">{{ $message }}</p>
                        @enderror
                    @endif
                    <button class="btn-primary w-full">{{ __('Masuk') }}</button>
                </form>
            @endif

                @if (\App\Support\PortalClient::allowsPasswordLogin())
                <p class="mt-5 text-center text-xs text-slate-500">{{ __('Username dan PIN sama dengan aplikasi absensi. HOD, HR, dan Administrator bisa memakai password dashboard absensi.') }}<br>{{ __('Lupa PIN? Buat atau reset di aplikasi absensi.') }}</p>
                @endif
            </div>
        </section>
    </div>
</x-guest-layout>
