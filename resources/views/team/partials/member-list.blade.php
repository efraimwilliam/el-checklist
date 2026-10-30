@props(['members'])
@php($initials = fn ($name) => collect(explode(' ', $name))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode(''))
<ul class="divide-y divide-slate-100">
    @foreach ($members as $member)
        <li>
            <a href="{{ route('team.tasks.edit', $member) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-[#fbf6ea]">
                <span class="hod-avatar !h-10 !w-10 shrink-0 !text-sm" aria-hidden="true">{{ $initials($member->name) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-semibold text-slate-900">{{ $member->name }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ $member->position_title ?? '—' }}</span>
                </span>
                @if ($member->custom_tasks)
                    <span class="pill bg-sky-100 text-sky-800">{{ __('Disesuaikan · :n tugas', ['n' => $member->assigned_tasks_count]) }}</span>
                @else
                    <span class="pill-neutral">{{ __('Bawaan jabatan') }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
