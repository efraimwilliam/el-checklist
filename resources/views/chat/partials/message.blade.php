{{-- Satu gelembung pesan. Dipakai saat halaman dibuka dan oleh polling (JSON html), jadi semua isi di-escape Blade. --}}
@php
    $at = $message->created_at->copy()->timezone(config('app.timezone'));
    $mine = $message->user_id === $me->id;
    $group = ! $thread->isDirect();
    $deleted = $message->isDeleted();
    $nameTones = ['text-[#8a6a22]', 'text-sky-700', 'text-emerald-700', 'text-rose-700', 'text-violet-700', 'text-orange-700'];
    $reply = $message->replyTo;
    $people = $thread->participants->pluck('user')->filter()->keyBy('id');
@endphp
@if ($message->isSystem())
    <div class="flex justify-center py-1" data-message="{{ $message->id }}" data-day="{{ $at->toDateString() }}" data-system>
        <span class="max-w-[85%] rounded-lg bg-white/90 px-3 py-1 text-center text-xs text-slate-600 shadow-sm">{{ $message->body }}</span>
    </div>
@else
    <div @class(['group/msg flex', 'justify-end' => $mine]) data-message="{{ $message->id }}" data-day="{{ $at->toDateString() }}"
         data-mine="{{ $mine ? 1 : 0 }}" data-deleted="{{ $deleted ? 1 : 0 }}"
         data-author="{{ $mine ? __('Anda') : $message->user?->name() }}" data-snippet="{{ $message->snippet(120) }}" data-text="{{ $deleted ? '' : $message->body }}">
        <div @class([
            'relative max-w-[85%] rounded-xl px-2 pb-1 pt-1.5 text-[15px] leading-snug text-slate-900 shadow-sm sm:max-w-[65%]',
            'rounded-tr-sm bg-[#f6e7c1]' => $mine,
            'rounded-tl-sm bg-white' => ! $mine,
        ])>
            @if (! $mine && $group)
                <p class="px-1 text-[13px] font-semibold {{ $nameTones[$message->user_id % count($nameTones)] }}">{{ $message->user?->name() ?? '—' }}</p>
            @endif

            @if ($reply && ! $deleted)
                <button type="button" data-jump="{{ $reply->id }}" class="mb-1 block w-full min-w-40 rounded-md border-l-4 border-[#b08a3e] bg-black/5 px-2 py-1 text-left text-[13px] hover:bg-black/10">
                    <span class="block font-semibold text-[#8a6a22]">{{ $reply->user_id === $me->id ? __('Anda') : $reply->user?->name() }}</span>
                    <span class="block truncate text-slate-600">{{ $reply->snippet(90) }}</span>
                </button>
            @endif

            @if ($message->photo_path && ! $deleted)
                <a href="{{ route('chat.photo', [$thread, $message]) }}" data-photo-open data-alt="{{ __('Foto') }}" class="mb-1 block overflow-hidden rounded-lg">
                    <img src="{{ route('chat.photo', [$thread, $message]) }}" alt="{{ __('Foto') }}" loading="lazy" class="max-h-80 w-full min-w-48 bg-slate-100 object-cover">
                </a>
            @endif

            @if ($message->room_issue_id && ! $deleted)
                @php
                    $shared = $message->sharedIssue;
                    $canOpen = $shared && \Illuminate\Support\Facades\Gate::forUser($me)->allows('view', $shared);
                    $issueTones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800'];
                @endphp
                @if ($shared)
                    <{{ $canOpen ? 'a' : 'div' }} @if ($canOpen) href="{{ route('kamar.issues.show', $shared) }}" @endif data-shared-issue="{{ $shared->id }}"
                        class="mb-1 block min-w-60 overflow-hidden rounded-lg border-l-4 border-[#b08a3e] bg-white/80 p-2.5 text-left {{ $canOpen ? 'hover:bg-white' : '' }}">
                        <span class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider text-[#8a6a22]">🛠 {{ __('Kendala') }} #{{ $shared->id }}</span>
                                <span class="block truncate font-semibold text-slate-900">{{ $shared->placeTitle() }}</span>
                            </span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $issueTones[$shared->status] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Models\RoomIssue::statusLabels()[$shared->status] ?? $shared->status }}</span>
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $shared->category?->name ?? __('Tanpa kategori') }}@if ($shared->priority === 'mendesak') · <span class="font-semibold text-rose-700">{{ __('Mendesak') }}</span>@endif
                        </span>
                        <span class="mt-1 line-clamp-3 block text-sm text-slate-700">{{ $shared->description }}</span>
                        <span class="mt-1.5 block text-xs font-semibold {{ $canOpen ? 'text-sky-700' : 'text-slate-400' }}">{{ $canOpen ? __('Buka kendala').' →' : __('Anda tidak punya akses ke halaman kendala ini') }}</span>
                    </{{ $canOpen ? 'a' : 'div' }}>
                @else
                    <p class="mb-1 rounded-lg bg-black/5 px-3 py-2 text-sm italic text-slate-500">🛠 {{ __('Kendala ini sudah dihapus.') }}</p>
                @endif
            @endif

            @if ($message->chronology_report_id && ! $deleted)
                @php
                    $sharedReport = $message->sharedReport;
                    $canSeeReport = $sharedReport && \Illuminate\Support\Facades\Gate::forUser($me)->allows('view', $sharedReport);
                    $rooms = $sharedReport ? $sharedReport->guests->pluck('room_number')->filter()->unique() : collect();
                @endphp
                @if ($sharedReport)
                    <{{ $canSeeReport ? 'a' : 'div' }} @if ($canSeeReport) href="{{ route('chronology.show', $sharedReport) }}" @endif data-shared-report="{{ $sharedReport->id }}"
                        class="mb-1 block min-w-60 overflow-hidden rounded-lg border-l-4 border-violet-600 bg-white/80 p-2.5 text-left {{ $canSeeReport ? 'hover:bg-white' : '' }}">
                        <span class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider text-violet-700">📝 Chronology Report</span>
                                <span class="block truncate font-semibold text-slate-900">{{ $sharedReport->guests->map(fn ($g) => $g->name)->filter()->implode(', ') ?: '—' }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    {{ $rooms->isNotEmpty() ? 'Room '.$rooms->implode(', ') : '' }}{{ $rooms->isNotEmpty() && $sharedReport->check_in ? ' · ' : '' }}{{ $sharedReport->check_in?->format('d M Y') }}
                                </span>
                            </span>
                            <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-50 text-emerald-800' => $sharedReport->isFinal(), 'bg-slate-100 text-slate-700' => ! $sharedReport->isFinal()])>{{ $sharedReport->isFinal() ? 'Final' : 'Draft' }}</span>
                        </span>
                        @if ($first = $sharedReport->entries->first())
                            <span class="mt-1 line-clamp-3 block text-sm text-slate-700">1. {{ $first->body }}</span>
                        @endif
                        <span class="mt-1 block text-xs text-slate-500">{{ $sharedReport->entries->count() }} {{ __('poin') }} · {{ $sharedReport->author?->name() }}</span>
                        <span class="mt-1.5 block text-xs font-semibold {{ $canSeeReport ? 'text-sky-700' : 'text-slate-400' }}">{{ $canSeeReport ? __('Buka laporan').' →' : __('Anda tidak punya akses ke laporan ini') }}</span>
                    </{{ $canSeeReport ? 'a' : 'div' }}>
                @else
                    <p class="mb-1 rounded-lg bg-black/5 px-3 py-2 text-sm italic text-slate-500">📝 {{ __('Laporan ini sudah dihapus.') }}</p>
                @endif
            @endif

            @if ($message->checklist_id && ! $deleted)
                @php
                    $sharedChecklist = $message->sharedChecklist;
                    $checklistGate = \Illuminate\Support\Facades\Gate::forUser($me);
                    $canSeeChecklist = $sharedChecklist && $checklistGate->allows('view', $sharedChecklist);
                    $canFillChecklist = $canSeeChecklist && $checklistGate->allows('update', $sharedChecklist);
                @endphp
                @if ($sharedChecklist)
                    @php
                        $total = $sharedChecklist->items->count();
                        $answered = $sharedChecklist->answeredCount();
                        $counts = $sharedChecklist->counts();
                        $complete = $sharedChecklist->isComplete();
                        $isOwner = $sharedChecklist->employee_id && $sharedChecklist->employee_id === $me->employee_id;
                        $percent = $total ? (int) round($answered / $total * 100) : 0;
                    @endphp
                    <div data-shared-checklist="{{ $sharedChecklist->id }}" class="mb-1 min-w-60 overflow-hidden rounded-lg border-l-4 border-emerald-600 bg-white/80 p-2.5 text-left">
                        <span class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider text-emerald-700">📋 {{ __('Checklist') }} · {{ $sharedChecklist->date->format('d M Y') }}</span>
                                <span class="block truncate font-semibold text-slate-900">{{ $sharedChecklist->employee?->name ?? '—' }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $sharedChecklist->position_name }}</span>
                            </span>
                            <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                'bg-emerald-50 text-emerald-800' => $complete || $sharedChecklist->isReviewed() || $sharedChecklist->isHodReviewed(),
                                'bg-amber-50 text-amber-800' => ! $complete && ! $sharedChecklist->isReviewed() && ! $sharedChecklist->isHodReviewed()])>
                                {{ $sharedChecklist->isReviewed() || $sharedChecklist->isHodReviewed() ? $sharedChecklist->status->label() : ($complete ? __('Lengkap') : __('Belum lengkap')) }}
                            </span>
                        </span>
                        <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><span class="block h-full rounded-full bg-emerald-600" style="width: {{ $percent }}%"></span></span>
                        <span class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-600">
                            <span>{{ __(':n/:m diisi', ['n' => $answered, 'm' => $total]) }}</span>
                            <span>{{ __('Skor') }} {{ $sharedChecklist->score() ?? 0 }}%</span>
                            @if ($counts['pending'])<span class="font-semibold text-amber-700">{{ $counts['pending'] }} {{ __('Pending') }}</span>@endif
                        </span>
                        @if ($isOwner && ! $complete)
                            <span class="mt-1.5 block rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900">{{ __('Pengingat: checklist Anda belum lengkap.') }}</span>
                        @endif
                        @if ($canFillChecklist && ! $complete)
                            <a href="{{ route('checklists.fill', $sharedChecklist) }}" class="mt-1.5 block text-xs font-semibold text-sky-700 hover:underline">{{ __('Lanjutkan isi checklist') }} →</a>
                        @elseif ($canSeeChecklist)
                            <a href="{{ route('checklists.show', $sharedChecklist) }}" class="mt-1.5 block text-xs font-semibold text-sky-700 hover:underline">{{ __('Buka checklist') }} →</a>
                        @else
                            <span class="mt-1.5 block text-xs font-semibold text-slate-400">{{ __('Anda tidak punya akses ke checklist ini') }}</span>
                        @endif
                    </div>
                @else
                    <p class="mb-1 rounded-lg bg-black/5 px-3 py-2 text-sm italic text-slate-500">📋 {{ __('Checklist ini sudah dihapus.') }}</p>
                @endif
            @endif

            @if ($message->file_path && ! $deleted)
                <a href="{{ route('chat.file', [$thread, $message]) }}" target="_blank" rel="noopener" class="mb-1 flex min-w-56 items-center gap-3 rounded-lg bg-black/5 p-2.5 hover:bg-black/10">
                    <span class="flex h-10 w-9 shrink-0 items-center justify-center rounded-md bg-white text-[10px] font-bold text-rose-700 shadow-sm">{{ $message->fileExtension() }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-900">{{ $message->file_name }}</span>
                        <span class="block text-xs text-slate-500">{{ $message->fileSizeLabel() }} · {{ $message->fileExtension() }}</span>
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            @endif

            <div class="flex flex-wrap items-end justify-end gap-x-2">
                @if ($deleted)
                    <p class="mr-auto px-1 italic text-slate-500">🚫 {{ $mine ? __('Anda menghapus pesan ini') : __('Pesan ini dihapus') }}</p>
                @elseif (filled($message->body))
                    <p class="mr-auto whitespace-pre-wrap break-words px-1 [overflow-wrap:anywhere]">{!! $message->bodyHtml($people, $me) !!}</p>
                @endif
                <span class="ml-auto flex shrink-0 items-center gap-0.5 pt-0.5 text-[11px] text-slate-500">
                    {{ $at->format('H:i') }}
                    @if ($mine && ! $deleted)
                        <span class="group/tick" data-tick data-read="{{ $message->id <= $readUpto ? 1 : 0 }}" title="{{ $message->id <= $readUpto ? __('Dibaca') : __('Terkirim') }}">
                            <svg class="h-4 w-4 text-slate-400 group-data-[read=1]/tick:hidden" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 8.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <svg class="hidden h-4 w-4 text-sky-500 group-data-[read=1]/tick:inline" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1 8.5l3 3 7-7M7.5 10.5l1 1 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    @endif
                </span>
            </div>

            @unless ($deleted)
                <button type="button" data-msg-menu aria-label="{{ __('Menu pesan') }}"
                        @class(['absolute right-1 top-1 hidden h-6 w-6 items-center justify-center rounded-full text-slate-500 shadow-sm group-hover/msg:flex focus:flex', 'bg-[#f6e7c1]' => $mine, 'bg-white' => ! $mine])>
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.5 7.5l4.5 4.5 4.5-4.5"/></svg>
                </button>
            @endunless
        </div>
    </div>
@endif
