@php
    $me = auth()->user();
    $title = $thread->title($me);
    $subtitle = $thread->subtitle($me);
    $dayLabel = function (string $day) {
        $date = \Carbon\Carbon::parse($day, config('app.timezone'));
        return match (true) {
            $date->isToday() => __('Hari ini'),
            $date->isYesterday() => __('Kemarin'),
            default => $date->locale(app()->getLocale())->translatedFormat('j F Y'),
        };
    };
    $previousDay = null;
@endphp
<x-app-layout :title="$title" :full-height="true" :bare-on-mobile="true">
    @vite(['resources/js/chat.js'])
    <div data-chat-shell class="flex min-h-0 flex-1 overflow-hidden bg-white md:rounded-2xl md:border md:border-slate-200 md:shadow-sm">
        @include('chat.partials.sidebar', ['hideOnMobile' => true])

        <section class="relative flex min-w-0 flex-1 flex-col" data-chat
                 data-poll-url="{{ route('chat.poll', $thread) }}" data-send-url="{{ route('chat.send', $thread) }}"
                 data-typing-url="{{ $canPost ? route('chat.typing', $thread) : '' }}" data-delete-url="{{ route('chat.destroy', [$thread, 0]) }}"
                 data-index-url="{{ route('chat.index') }}" data-last="{{ (int) $messages->last()?->id }}" data-since="{{ $serverNow }}"
                 data-read-upto="{{ $readUpto }}" data-search-url="{{ route('chat.search', $thread) }}" data-show-url="{{ route('chat.show', $thread) }}" data-target="{{ $target ?? '' }}" data-subtitle="{{ $subtitle }}" data-direct="{{ $thread->isDirect() ? 1 : 0 }}"
                 data-mentionables="{{ json_encode($thread->participants->pluck('user')->filter()->reject(fn ($u) => $u->id === $me->id)->sortBy(fn ($u) => mb_strtolower($u->name()))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name(), 'info' => $u->employee?->department?->name ?? '', 'photo' => $u->employee?->photoUrl()])->values()) }}">
            {{-- Header percakapan --}}
            <header class="flex shrink-0 items-center gap-2 border-b border-slate-200 bg-[#251d17] px-2 py-2 text-white md:bg-[#f7f2e8] md:px-4 md:text-slate-900">
                <a href="{{ route('chat.index', request()->boolean('semua') ? ['semua' => 1] : []) }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full hover:bg-white/10 md:hidden" aria-label="{{ __('Kembali ke daftar obrolan') }}">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <button type="button" data-info-open class="flex min-w-0 flex-1 items-center gap-3 rounded-lg py-1 text-left md:hover:bg-black/5">
                    @include('chat.partials.avatar', ['thread' => $thread, 'viewer' => $me, 'size' => 'h-10 w-10 text-sm'])
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">{{ $title }}</span>
                        <span class="block truncate text-xs text-white/70 md:text-slate-500" data-subtitle-text>{{ $subtitle }}</span>
                    </span>
                </button>
                @if ($thread->issue)
                    <a href="{{ route('kamar.issues.show', $thread->issue) }}" class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium hover:bg-white/10 md:hover:bg-black/5">{{ __('Kendala') }}</a>
                @elseif ($thread->checklist)
                    <a href="{{ route('checklists.show', $thread->checklist) }}" class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium hover:bg-white/10 md:hover:bg-black/5">{{ __('Checklist') }}</a>
                @endif
                <button type="button" data-search-open class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full hover:bg-white/10 md:hover:bg-black/5" aria-label="{{ __('Cari pesan') }}" title="{{ __('Cari pesan') }}">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="M14 14l4 4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" data-info-open class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full hover:bg-white/10 md:hover:bg-black/5" aria-label="{{ __('Info obrolan') }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                </button>
            </header>

            {{-- Cari pesan di percakapan ini --}}
            <div hidden data-search-bar class="relative z-20 shrink-0 border-b border-slate-200 bg-white px-3 py-2">
                <div class="flex items-center gap-2">
                    <label class="relative min-w-0 flex-1">
                        <span class="sr-only">{{ __('Cari pesan') }}</span>
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="M14 14l4 4" stroke-linecap="round"/></svg>
                        <input type="search" data-search-input autocomplete="off" class="w-full rounded-lg border-0 bg-slate-100 py-2 pl-9 pr-3 text-sm focus:bg-white focus:ring-2 focus:ring-gold-500">
                    </label>
                    <button type="button" data-search-close class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100">{{ __('Tutup') }}</button>
                </div>
                <div hidden data-search-results class="absolute inset-x-0 top-full max-h-[60vh] overflow-y-auto border-b border-slate-200 bg-white shadow-lg"></div>
            </div>

            {{-- Pesan --}}
            <div class="min-h-0 flex-1 space-y-1 overflow-y-auto bg-[#efe6d6] bg-[radial-gradient(#e2d6bf_1px,transparent_1px)] [background-size:18px_18px] px-3 py-3 sm:px-[6%]" data-log aria-live="polite">
                @foreach ($messages as $message)
                    @php($day = $message->created_at->copy()->timezone(config('app.timezone'))->toDateString())
                    @if ($day !== $previousDay)
                        <div class="sticky top-0 z-10 flex justify-center py-1" data-day-sep="{{ $day }}">
                            <span class="rounded-lg bg-white/95 px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">{{ $dayLabel($day) }}</span>
                        </div>
                        @php($previousDay = $day)
                    @endif
                    @if ($firstUnread === $message->id)
                        <div class="-mx-3 my-2 bg-white/60 py-1 text-center text-xs font-semibold text-[#6b5118] sm:-mx-[6%]" data-unread-divider>
                            {{ trans_choice(':n pesan belum dibaca|:n pesan belum dibaca', $unreadCount, ['n' => $unreadCount]) }}
                        </div>
                    @endif
                    @include('chat.partials.message', ['message' => $message, 'me' => $me, 'thread' => $thread, 'readUpto' => $readUpto])
                @endforeach
            </div>

            <button type="button" hidden data-scroll-down class="absolute bottom-24 right-4 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-white text-slate-600 shadow-lg ring-1 ring-black/5" aria-label="{{ __('Ke pesan terbaru') }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span hidden data-scroll-count class="absolute -top-1.5 -right-1 min-w-5 rounded-full bg-emerald-600 px-1 text-[11px] font-semibold leading-5 text-white"></span>
            </button>

            {{-- Kotak tulis --}}
            @if ($canPost)
                <div class="relative shrink-0 bg-[#f4efe6] px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2" data-composer>
                    <div hidden data-mention-list role="listbox" aria-label="{{ __('Sebut anggota') }}" class="absolute inset-x-2 bottom-full z-20 mb-1 max-h-64 overflow-y-auto rounded-xl bg-white py-1 shadow-xl ring-1 ring-black/10"></div>
                    <div hidden data-reply-bar class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-[#b08a3e] bg-white px-3 py-2 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-[#8a6a22]" data-reply-author></p>
                            <p class="truncate text-slate-600" data-reply-text></p>
                        </div>
                        <button type="button" data-reply-cancel class="inline-flex h-7 w-7 items-center justify-center rounded-full text-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('Batal membalas') }}">×</button>
                    </div>
                    <div hidden data-file-bar class="mb-2 flex items-center gap-3 rounded-lg bg-white p-2 text-sm">
                        <span class="flex h-12 w-10 shrink-0 items-center justify-center rounded-md bg-rose-50 text-[10px] font-bold text-rose-700" data-file-ext></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900" data-file-name></p>
                            <p class="text-xs text-slate-500" data-file-size></p>
                        </div>
                        <button type="button" data-file-cancel class="inline-flex h-7 w-7 items-center justify-center rounded-full text-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('Batal kirim dokumen') }}">×</button>
                    </div>
                    <div hidden data-photo-bar class="mb-2 flex items-center gap-3 rounded-lg bg-white p-2 text-sm">
                        <img data-photo-preview alt="" class="h-16 w-16 rounded-md object-cover">
                        <p class="min-w-0 flex-1 text-slate-600">{{ __('Foto siap dikirim. Tulis keterangan bila perlu, lalu tekan kirim.') }}</p>
                        <button type="button" data-photo-cancel class="inline-flex h-7 w-7 items-center justify-center rounded-full text-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('Batal kirim foto') }}">×</button>
                    </div>
                    <p hidden class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700" data-error></p>
                    <form class="flex items-end gap-2" data-form method="POST" action="{{ route('chat.send', $thread) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="relative flex min-w-0 flex-1 items-end rounded-3xl bg-white shadow-sm">
                            <button type="button" data-attach-open aria-haspopup="menu" aria-expanded="false" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-slate-500 hover:text-slate-800" aria-label="{{ __('Lampirkan') }}" title="{{ __('Lampirkan') }}">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 11.5l-7.8 7.8a5 5 0 01-7.1-7.1l8.1-8.1a3.4 3.4 0 014.8 4.8l-8 8a1.7 1.7 0 01-2.4-2.4l7.4-7.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div hidden data-attach-menu role="menu" class="absolute bottom-full left-0 z-20 mb-2 w-52 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-xl ring-1 ring-black/10">
                                <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50" role="menuitem">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-violet-100 text-violet-700" aria-hidden="true">📄</span>{{ __('Dokumen') }}
                                    <input type="file" class="sr-only" data-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.zip">
                                </label>
                                <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50" role="menuitem">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-sky-100 text-sky-700" aria-hidden="true">🖼</span>{{ __('Foto dari galeri') }}
                                    <input type="file" class="sr-only" data-photo-input accept="image/*">
                                </label>
                                <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50" role="menuitem">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-100 text-rose-700" aria-hidden="true">📷</span>{{ __('Kamera') }}
                                    <input type="file" class="sr-only" data-photo-input accept="image/*" capture="environment">
                                </label>
                            </div>
                            <label class="sr-only" for="chat-body">{{ __('Pesan') }}</label>
                            <textarea id="chat-body" name="body" rows="1" maxlength="4000" class="max-h-36 min-h-11 flex-1 resize-none border-0 bg-transparent py-2.5 pr-4 text-[15px] focus:ring-0" data-body aria-autocomplete="list"></textarea>
                        </div>
                        <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#b08a3e] text-white shadow-sm hover:bg-[#9a7733] disabled:opacity-60" data-send aria-label="{{ __('Kirim') }}">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4l17.4-7.5a1 1 0 000-1.8L3.4 3.6a1 1 0 00-1.4 1.1L3.8 11l9.2 1-9.2 1-1.8 6.3a1 1 0 001.4 1.1z"/></svg>
                        </button>
                    </form>
                </div>
            @else
                <p class="shrink-0 bg-[#f4efe6] px-4 py-3 text-center text-sm text-slate-600">{{ __('Anda memantau percakapan ini sebagai HR / Administrator: bisa membaca, tidak bisa mengirim pesan.') }}</p>
            @endif
        </section>
    </div>

    {{-- Menu pesan (Balas / Salin / Hapus), dipindah ke dekat pesan yang dipilih. --}}
    <div hidden data-msg-panel class="fixed z-50 w-44 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-xl ring-1 ring-black/10" role="menu">
        @if ($canPost)
            <button type="button" data-act="reply" class="block w-full px-4 py-2 text-left hover:bg-slate-50" role="menuitem">{{ __('Balas') }}</button>
        @endif
        <button type="button" data-act="copy" class="block w-full px-4 py-2 text-left hover:bg-slate-50" role="menuitem">{{ __('Salin') }}</button>
        <button type="button" data-act="delete" class="block w-full px-4 py-2 text-left text-rose-700 hover:bg-rose-50" role="menuitem">{{ __('Hapus untuk semua') }}</button>
    </div>

    {{-- Info obrolan: anggota, tambah anggota, keluar grup. --}}
    <dialog data-info-dialog class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-2xl p-0 shadow-2xl backdrop:bg-black/40">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="font-display text-xl text-slate-900">{{ $thread->isDirect() ? __('Info kontak') : __('Info grup') }}</h2>
            <button type="button" data-info-close class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xl text-slate-500 hover:bg-slate-100" aria-label="{{ __('Tutup') }}">×</button>
        </div>
        <div class="max-h-[70vh] overflow-y-auto">
            <div class="flex flex-col items-center gap-2 px-5 py-5 text-center">
                @include('chat.partials.avatar', ['thread' => $thread, 'viewer' => $me, 'size' => 'h-24 w-24 text-3xl'])
                @if ($canInvite)
                    <div class="flex flex-wrap justify-center gap-2 text-sm">
                        <form method="POST" action="{{ route('chat.group-photo.update', $thread) }}" enctype="multipart/form-data">
                            @csrf
                            <label class="cursor-pointer rounded-full px-3 py-1 font-medium text-sky-700 hover:bg-slate-100">
                                {{ $thread->photo_path ? __('Ganti foto grup') : __('Pasang foto grup') }}
                                <input type="file" name="photo" accept="image/*" class="sr-only" onchange="this.form.submit()">
                            </label>
                        </form>
                        @if ($thread->photo_path)
                            <form method="POST" action="{{ route('chat.group-photo.update', $thread) }}" data-confirm="{{ __('Hapus foto grup?') }}">
                                @csrf
                                <input type="hidden" name="hapus" value="1">
                                <button class="rounded-full px-3 py-1 font-medium text-rose-700 hover:bg-rose-50">{{ __('Hapus foto') }}</button>
                            </form>
                        @endif
                    </div>
                    @error('photo')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
                @endif
                <p class="text-lg font-semibold text-slate-900">{{ $title }}</p>
                @if ($thread->isDirect())
                    <p class="text-sm text-slate-500">{{ $subtitle }}</p>
                @else
                    <p class="text-sm text-slate-500">{{ trans_choice(':n anggota|:n anggota', $thread->participants->count(), ['n' => $thread->participants->count()]) }}</p>
                @endif
                @if ($thread->issue)
                    <a href="{{ route('kamar.issues.show', $thread->issue) }}" class="text-sm text-sky-700 hover:underline">{{ __('Buka kendala') }} →</a>
                    <p class="text-xs text-slate-500">{{ __('Semua yang boleh melihat kendala ini bisa ikut obrolan.') }}</p>
                @elseif ($thread->checklist)
                    <a href="{{ route('checklists.show', $thread->checklist) }}" class="text-sm text-sky-700 hover:underline">{{ __('Buka checklist') }} →</a>
                    <p class="text-xs text-slate-500">{{ __('Semua yang boleh melihat checklist ini bisa ikut obrolan.') }}</p>
                @endif
            </div>

            <div class="border-y border-slate-100" data-media data-url="{{ route('chat.media', $thread) }}">
                <p class="px-5 py-4 text-center text-sm text-slate-400">{{ __('Memuat media, tautan, dan dokumen…') }}</p>
            </div>

            @unless ($thread->isDirect())
                <p class="px-5 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-[#8a6a22]">{{ __('Anggota') }}</p>
                @if ($canInvite && $candidates->isNotEmpty())
                    <details class="px-3">
                        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-2 py-2 hover:bg-[#faf6ec]">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-[#b08a3e] text-xl text-white" aria-hidden="true">+</span>
                            <span class="font-medium text-slate-900">{{ __('Tambah anggota') }}</span>
                        </summary>
                        <form method="POST" action="{{ route('chat.invite', $thread) }}" class="pb-3 pl-2">
                            @csrf
                            <input type="search" class="input mt-2 w-full" placeholder="{{ __('Cari nama') }}" data-invite-search>
                            <div class="mt-2 max-h-52 space-y-0.5 overflow-y-auto">
                                @foreach ($candidates as $person)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50" data-invite-person data-name="{{ mb_strtolower($person->name()) }}">
                                        <input type="checkbox" name="users[]" value="{{ $person->id }}" class="h-4 w-4 accent-[#b08a3e]">
                                        <span>{{ $person->name() }} <span class="text-xs text-slate-500">· {{ $person->employee?->department?->name ?? '—' }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                            <button class="btn-primary mt-3 w-full">{{ __('Tambahkan') }}</button>
                        </form>
                    </details>
                @endif
                <ul class="px-3 pb-3">
                    @foreach ($thread->participants->sortBy(fn ($p) => $p->user_id === $me->id ? '' : mb_strtolower($p->user?->name() ?? '')) as $participant)
                        @php($person = $participant->user)
                        @continue(! $person)
                        <li class="flex items-center gap-3 rounded-lg px-2 py-2">
                            @include('chat.partials.person-avatar', ['person' => $person, 'size' => 'h-10 w-10 text-sm'])
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] text-slate-900">{{ $person->id === $me->id ? __('Anda') : $person->name() }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ collect([$person->employee?->position_title, $person->employee?->department?->name])->filter()->implode(' · ') ?: '—' }}</span>
                            </span>
                            @if ($person->id === $thread->created_by && $thread->isGroup())
                                <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ __('Pembuat') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endunless

            @if ($canLeave)
                <form method="POST" action="{{ route('chat.leave', $thread) }}" class="border-t border-slate-100 px-5 py-4" data-confirm="{{ __('Keluar dari grup ini? Anda tidak akan menerima pesannya lagi.') }}">
                    @csrf
                    <button class="flex items-center gap-2 text-sm font-semibold text-rose-700 hover:underline">{{ __('Keluar dari grup') }}</button>
                </form>
            @endif
        </div>
    </dialog>
</x-app-layout>
