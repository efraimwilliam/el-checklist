    @can('chat')
        {{-- Kirim kendala / checklist ke obrolan (seperti "teruskan" di WhatsApp). Butuh: $action, $subtitle, $preselect (id pengguna yang langsung dicentang), $shareThreads, $sharePeople. --}}
        <dialog data-share-dialog @if ($errors->has('share')) data-open-on-load @endif class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-2xl p-0 shadow-2xl backdrop:bg-black/40">
            <form method="POST" action="{{ $action }}" class="flex max-h-[85dvh] flex-col">
                @csrf
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-display text-xl text-slate-900">{{ __('Kirim ke obrolan') }}</h2>
                        <p class="text-xs text-slate-500">{{ $subtitle }}</p>
                    </div>
                    <button type="button" data-share-close class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xl text-slate-500 hover:bg-slate-100" aria-label="{{ __('Tutup') }}">×</button>
                </div>
                @error('share')<p class="mx-5 mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</p>@enderror
                <div class="px-5 pt-3">
                    <input type="search" data-share-search placeholder="{{ __('Cari obrolan atau nama') }}" class="w-full rounded-lg border-0 bg-slate-100 px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-gold-500">
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto py-2">
                    @if ($shareThreads->isNotEmpty())
                        <p class="px-5 pb-1 pt-2 text-xs font-semibold uppercase tracking-wider text-[#8a6a22]" data-share-heading>{{ __('Obrolan terbaru') }}</p>
                        @foreach ($shareThreads as $target)
                            <label data-share-row data-name="{{ mb_strtolower($target->title(auth()->user())) }}" class="flex cursor-pointer items-center gap-3 px-5 py-2 hover:bg-[#faf6ec]">
                                @include('chat.partials.avatar', ['thread' => $target, 'viewer' => auth()->user(), 'size' => 'h-10 w-10 text-sm'])
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-900">{{ $target->title(auth()->user()) }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ $target->isDirect() ? __('Chat pribadi') : $target->subtitle(auth()->user()) }}</span>
                                </span>
                                <input type="checkbox" name="threads[]" value="{{ $target->id }}" class="h-5 w-5 rounded-full accent-[#b08a3e]" data-share-pick>
                            </label>
                        @endforeach
                    @endif
                    <p class="px-5 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-[#8a6a22]" data-share-heading>{{ __('Kontak') }}</p>
                    @foreach ($sharePeople->sortBy(fn ($p) => in_array($p->id, $preselect, true) ? 0 : 1)->values() as $person)
                        <label data-share-row data-name="{{ mb_strtolower($person->name().' '.($person->employee?->department?->name ?? '')) }}" class="flex cursor-pointer items-center gap-3 px-5 py-2 hover:bg-[#faf6ec]">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#f3e6c4] text-sm font-semibold text-[#8a6a22]" aria-hidden="true">{{ mb_strtoupper(mb_substr($person->name(), 0, 1)) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $person->name() }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ collect([$person->employee?->position_title, $person->employee?->department?->name])->filter()->implode(' · ') ?: '—' }}</span>
                            </span>
                            <input type="checkbox" name="people[]" value="{{ $person->id }}" class="h-5 w-5 rounded-full accent-[#b08a3e]" data-share-pick @checked(in_array($person->id, old('people', $preselect), true) || in_array((string) $person->id, (array) old('people', []), true))>
                        </label>
                    @endforeach
                </div>
                <div class="space-y-3 border-t border-slate-100 px-5 py-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-slate-600">{{ __('Pesan tambahan (opsional)') }}</span>
                        <textarea name="body" rows="2" maxlength="4000" class="input w-full resize-none" aria-label="{{ __('Pesan tambahan (opsional)') }}">{{ old('body') }}</textarea>
                    </label>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-slate-600" data-share-count>{{ __('Belum ada tujuan dipilih') }}</span>
                        <button class="btn-primary" data-share-send disabled>{{ __('Kirim') }}</button>
                    </div>
                </div>
            </form>
        </dialog>
        <script>
            (() => {
                const dialog = document.querySelector('[data-share-dialog]');
                if (!dialog) return;
                const t = (text) => (window.__i18n && window.__i18n[text]) || text;
                const count = dialog.querySelector('[data-share-count]');
                const send = dialog.querySelector('[data-share-send]');
                const refresh = () => {
                    const n = dialog.querySelectorAll('[data-share-pick]:checked').length;
                    count.textContent = n ? `${n} ${t('tujuan dipilih')}${n > 10 ? ` · ${t('maksimal 10')}` : ''}` : t('Belum ada tujuan dipilih');
                    send.disabled = n === 0 || n > 10;
                };
                document.querySelector('[data-share-open]').addEventListener('click', () => dialog.showModal());
                dialog.querySelector('[data-share-close]').addEventListener('click', () => dialog.close());
                dialog.addEventListener('change', refresh);
                dialog.querySelector('[data-share-search]').addEventListener('input', (event) => {
                    const term = event.target.value.trim().toLowerCase();
                    dialog.querySelectorAll('[data-share-row]').forEach((row) => { row.hidden = term !== '' && !row.dataset.name.includes(term); });
                });
                refresh();
                if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
            })();
        </script>
    @endcan
