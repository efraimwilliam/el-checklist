<x-app-layout :title="__('Obrolan')" :full-height="true">
    @vite(['resources/js/chat.js'])
    <div data-chat-shell class="flex min-h-0 flex-1 overflow-hidden bg-white md:rounded-2xl md:border md:border-slate-200 md:shadow-sm">
        @include('chat.partials.sidebar')

        <section class="hidden min-w-0 flex-1 flex-col items-center justify-center gap-3 bg-[#f7f2e8] px-10 text-center md:flex">
            <span class="flex h-20 w-20 items-center justify-center rounded-full bg-white text-[#b08a3e] shadow-sm" aria-hidden="true">
                <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4h0A1.5 1.5 0 014 14.5v-9z" stroke-linejoin="round"/><path d="M8 8h8M8 11.5h5" stroke-linecap="round"/></svg>
            </span>
            <h2 class="font-display text-2xl text-slate-900">{{ __('Obrolan pekerjaan') }}</h2>
            <p class="max-w-md text-sm text-slate-500">{{ __('Pilih obrolan di kiri, atau tekan tombol pensil untuk chat dengan rekan dan membuat grup. Obrolan kendala dan checklist dibuka dari halaman masing-masing.') }}</p>
            <p class="mt-4 text-xs text-slate-400">{{ __('HR dan Administrator dapat membaca semua percakapan.') }}</p>
        </section>
    </div>
</x-app-layout>
