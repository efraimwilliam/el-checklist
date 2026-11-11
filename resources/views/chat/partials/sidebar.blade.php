{{-- Panel kiri seperti WhatsApp: daftar obrolan, panel "Chat baru" (kontak) dan "Grup baru" yang menimpa daftar. --}}
@php
    $groupError = $errors->has('subject') || $errors->has('users');
@endphp
<aside @class(['relative flex min-h-0 w-full shrink-0 flex-col bg-white md:w-[23rem] md:border-r md:border-slate-200', $hideOnMobile ?? false ? 'max-md:hidden' : ''])
       data-sidebar data-reorder-url="{{ route('chat.pins.reorder') }}" data-list-url="{{ route('chat.list', array_filter(['semua' => $viewingAll ? 1 : null, 'aktif' => $activeId])) }}">
    <div class="flex items-center justify-between gap-2 px-4 pb-2 pt-4">
        <span class="flex min-w-0 items-center gap-1">
            <button type="button" hidden data-chat-return class="-ml-2 grid h-10 w-10 shrink-0 place-items-center rounded-full text-slate-700 hover:bg-slate-100 md:hidden" aria-label="{{ __('Kembali') }}">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <h1 class="text-2xl text-slate-900">{{ __('Obrolan') }}</h1>
        </span>
        <span class="flex items-center gap-1">
        <button type="button" data-push-toggle hidden data-state="unsupported" class="group/bell inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-700 hover:bg-slate-100">
            <svg class="h-5 w-5 group-data-[state=on]/bell:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9a6 6 0 0112 0c0 5 2 6.5 2 6.5H4S6 14 6 9zM10 19a2 2 0 004 0M4 4l16 16" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <svg class="hidden h-5 w-5 text-[#b08a3e] group-data-[state=on]/bell:block" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 9a6 6 0 0112 0c0 5 2 6.5 2 6.5H4S6 14 6 9z" stroke-linejoin="round"/><path d="M10 19a2 2 0 004 0" fill="none" stroke-linecap="round"/></svg>
        </button>
        <button type="button" data-panel-open="new" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-700 hover:bg-slate-100" aria-label="{{ __('Chat baru') }}" title="{{ __('Chat baru') }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        </span>
    </div>
    <div hidden data-push-banner class="mx-3 mb-2 flex items-center gap-3 rounded-xl bg-[#fbf3dc] px-3 py-2.5 text-sm text-[#5c4514]">
        <span class="min-w-0 flex-1">{{ __('Aktifkan notifikasi agar pesan baru tidak terlewat.') }}</span>
        <button type="button" data-push-enable class="shrink-0 rounded-full bg-[#b08a3e] px-3 py-1 text-xs font-semibold text-white hover:bg-[#9a7733]">{{ __('Aktifkan') }}</button>
        <button type="button" data-push-later class="shrink-0 rounded-full px-2 py-1 text-xs font-medium text-[#5c4514]/70 hover:bg-black/5">{{ __('Nanti') }}</button>
    </div>
    <div class="px-3 pb-2">
        <label class="relative block">
            <span class="sr-only">{{ __('Cari obrolan') }}</span>
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="M14 14l4 4" stroke-linecap="round"/></svg>
            <input type="search" data-thread-search placeholder="{{ __('Cari obrolan') }}" class="w-full rounded-lg border-0 bg-slate-100 py-2 pl-9 pr-3 text-sm focus:bg-white focus:ring-2 focus:ring-gold-500 focus:outline-none">
        </label>
    </div>
    @if ($canSeeAll)
        <div class="flex gap-2 px-3 pb-2 text-sm">
            <a href="{{ route('chat.index') }}" @class(['rounded-full px-3 py-1', 'bg-[#f3e6c4] font-semibold text-[#6b5118]' => ! $viewingAll, 'bg-slate-100 text-slate-600 hover:bg-slate-200' => $viewingAll])>{{ __('Obrolan saya') }}</a>
            <a href="{{ route('chat.index', ['semua' => 1]) }}" @class(['rounded-full px-3 py-1', 'bg-[#f3e6c4] font-semibold text-[#6b5118]' => $viewingAll, 'bg-slate-100 text-slate-600 hover:bg-slate-200' => ! $viewingAll])>{{ __('Semua percakapan') }}</a>
        </div>
    @endif
    @if (session('status'))
        <p class="mx-3 mb-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif
    <p hidden data-list-note role="status" class="mx-3 mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900"></p>
    <div class="min-h-0 flex-1 overflow-y-auto" data-thread-list>
        @include('chat.partials.list')
    </div>

    {{-- Menu baris obrolan (komputer: tombol ⌄ atau klik kanan). Isinya diisi JS menurut keadaan baris. --}}
    <div hidden data-row-menu role="menu" class="fixed z-50 w-48 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-xl ring-1 ring-black/10">
        <button type="button" data-row-action="unread" role="menuitem" class="block w-full px-4 py-2 text-left hover:bg-slate-50"></button>
        <button type="button" data-row-action="pin" role="menuitem" class="block w-full px-4 py-2 text-left hover:bg-slate-50"></button>
    </div>

    {{-- Chat baru: pilih kontak = langsung buka chat pribadi. --}}
    <div hidden data-panel="new" class="absolute inset-0 z-20 flex flex-col bg-white">
        <div class="flex items-center gap-3 bg-[#251d17] px-3 py-3 text-white">
            <button type="button" data-panel-close class="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-white/10" aria-label="{{ __('Kembali') }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <h2 class="text-lg font-semibold">{{ __('Chat baru') }}</h2>
        </div>
        <div class="p-3">
            <input type="search" data-contact-search placeholder="{{ __('Cari nama atau departemen') }}" class="w-full rounded-lg border-0 bg-slate-100 px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-gold-500 focus:outline-none">
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
            <button type="button" data-panel-open="group" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-[#faf6ec]">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-[#b08a3e] text-white" aria-hidden="true">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.5 3.3-5.5 6.5-5.5s5.9 2 6.5 5.5M16 4.5a3.5 3.5 0 010 7M18 14.8c2 .7 3.2 2.5 3.5 5.2" stroke-linecap="round"/></svg>
                </span>
                <span class="font-medium text-slate-900">{{ __('Grup baru') }}</span>
            </button>
            <p class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-[#8a6a22]">{{ __('Kontak') }}</p>
            <form id="direct-form" method="POST" class="hidden">@csrf</form>
            @foreach ($people as $person)
                <button type="submit" form="direct-form" formaction="{{ route('chat.direct', $person) }}" data-contact
                        data-name="{{ mb_strtolower($person->name().' '.($person->employee?->department?->name ?? '')) }}"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-[#faf6ec]">
                    @include('chat.partials.person-avatar', ['person' => $person, 'size' => 'h-11 w-11 text-sm'])
                    <span class="min-w-0">
                        <span class="block truncate text-[15px] text-slate-900">{{ $person->name() }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ collect([$person->employee?->position_title, $person->employee?->department?->name])->filter()->implode(' · ') ?: '—' }}</span>
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Grup baru: pilih anggota dan nama grup. --}}
    <form hidden method="POST" action="{{ route('chat.store') }}" data-panel="group" class="absolute inset-0 z-30 flex flex-col bg-white" @if ($groupError) data-open-on-load @endif>
        @csrf
        <div class="flex items-center gap-3 bg-[#251d17] px-3 py-3 text-white">
            <button type="button" data-panel-close class="inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-white/10" aria-label="{{ __('Kembali') }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <h2 class="text-lg font-semibold">{{ __('Grup baru') }}</h2>
        </div>
        <div class="space-y-2 p-3">
            @if ($groupError)
                <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">@foreach (array_filter([$errors->first('subject'), $errors->first('users')]) as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ __('Nama grup') }}</span>
                <input name="subject" value="{{ old('subject') }}" maxlength="120" required class="input mt-1 w-full" placeholder="{{ __('Contoh: Engineering shift pagi') }}">
            </label>
            <input type="search" data-member-search placeholder="{{ __('Cari anggota') }}" class="w-full rounded-lg border-0 bg-slate-100 px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-gold-500 focus:outline-none">
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
            @foreach ($people as $person)
                <label data-member data-name="{{ mb_strtolower($person->name().' '.($person->employee?->department?->name ?? '')) }}" class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-[#faf6ec]">
                    @include('chat.partials.person-avatar', ['person' => $person, 'size' => 'h-11 w-11 text-sm'])
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] text-slate-900">{{ $person->name() }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ $person->employee?->department?->name ?? '—' }}</span>
                    </span>
                    <input type="checkbox" name="users[]" value="{{ $person->id }}" class="h-5 w-5 rounded-full accent-[#b08a3e]" @checked(in_array($person->id, (array) old('users', [])))>
                </label>
            @endforeach
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <span class="text-sm text-slate-600" data-member-count>{{ __('Belum ada anggota dipilih') }}</span>
            <button class="btn-primary">{{ __('Buat grup') }}</button>
        </div>
    </form>
</aside>
