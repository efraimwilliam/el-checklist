{{-- Satu baris obrolan di daftar. Butuh: $item, $viewingAll, $activeId, $pinnedRow. Dibungkus agar tombol urut bisa berdampingan dengan tautan. --}}
@php
    $me = auth()->user();
@endphp
    @php
        $title = $item->title($me);
        $last = $item->latestMessage;
        $unread = $viewingAll ? 0 : (int) $item->unread_count;
        $mineLast = $last && $last->user_id === $me->id && ! $last->isSystem();
        $prefix = $last && ! $last->isSystem() && ! $mineLast && ! $item->isDirect() ? ($last->user?->name() ?? '—').': ' : '';
    @endphp
    <a href="{{ route('chat.show', $viewingAll ? ['thread' => $item, 'semua' => 1] : $item) }}" data-thread-link data-row-surface
       @class(['relative z-[1] flex touch-pan-y items-center gap-3 px-3 py-2.5 transition-colors hover:bg-[#faf6ec]', 'bg-[#f6efdf]' => $activeId === $item->id, 'bg-white' => $activeId !== $item->id])
       @if ($activeId === $item->id) aria-current="page" @endif>
        <span class="relative shrink-0" aria-hidden="true">
            @include('chat.partials.avatar', ['thread' => $item, 'viewer' => $me, 'size' => 'h-12 w-12 text-base'])
            @if ($item->room_issue_id)
                <span class="absolute -bottom-0.5 -right-0.5 rounded-full bg-white px-1 text-[10px] shadow">🛠</span>
            @elseif ($item->checklist_id)
                <span class="absolute -bottom-0.5 -right-0.5 rounded-full bg-white px-1 text-[10px] shadow">✓</span>
            @elseif ($item->isGroup())
                <span class="absolute -bottom-0.5 -right-0.5 rounded-full bg-white px-1 text-[10px] shadow">👥</span>
            @endif
        </span>
        <span class="min-w-0 flex-1">
            <span class="flex items-baseline justify-between gap-2">
                <span @class(['truncate text-[15px] text-slate-900', 'font-semibold' => $unread > 0, 'font-medium' => $unread === 0])>{{ $title }}</span>
                <span @class(['shrink-0 text-xs', 'font-semibold text-emerald-600' => $unread > 0, 'text-slate-500' => $unread === 0])>@if ($pinnedRow)<span class="mr-1" aria-label="{{ __('Disematkan') }}">📌</span>@endif{{ $when($last?->created_at ?? $item->created_at) }}</span>
            </span>
            <span class="mt-0.5 flex items-center justify-between gap-2">
                <span class="flex min-w-0 items-center gap-1 text-sm text-slate-500">
                    @if ($mineLast && ! $last->isDeleted())
                        <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1 8.5l3 3 7-7M7.5 10.5l1 1 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @endif
                    <span class="truncate">{{ $last ? ($mineLast && $last->isDeleted() ? __('Anda menghapus pesan ini') : $prefix.$last->snippet()) : ($item->isDirect() ? __('Mulai percakapan') : __('Belum ada pesan')) }}</span>
                </span>
                @if (! $viewingAll && ($item->mention_count ?? 0) > 0)
                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[12px] font-bold text-white" title="{{ __('Anda disebut') }}" aria-label="{{ __('Anda disebut') }}">@</span>
                @endif
                @if ($unread > 0)
                    <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white" aria-label="{{ $unread }} {{ __('pesan baru') }}">{{ $unread > 99 ? '99+' : $unread }}</span>
                @elseif (! $viewingAll && $item->marked_unread)
                    <span class="inline-flex h-3 w-3 shrink-0 rounded-full bg-emerald-600" title="{{ __('Ditandai belum dibaca') }}" aria-label="{{ __('Ditandai belum dibaca') }}"></span>
                @endif
            </span>
            @if ($viewingAll)
                <span class="mt-0.5 block truncate text-xs text-slate-400">{{ $item->participants->pluck('user')->filter()->map->name()->implode(', ') }}</span>
            @endif
        </span>
    </a>
