{{-- Foto grup bila ada; chat pribadi = foto lawan bicara (Sistem Absensi); selain itu inisial berwarna. $size = kelas ukuran (mis. "h-12 w-12 text-base"). --}}
@php($other = $thread->isDirect() && $thread->others($viewer)->count() === 1 ? $thread->others($viewer)->first() : null)
@php($tones = ['bg-[#f3e6c4] text-[#8a6a22]', 'bg-sky-100 text-sky-800', 'bg-emerald-100 text-emerald-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-orange-100 text-orange-800'])
@if ($other?->employee?->photoUrl())
    @include('chat.partials.person-avatar', ['person' => $other, 'size' => $size])
@elseif ($thread->photo_path)
    <img src="{{ route('chat.group-photo', ['thread' => $thread, 'v' => substr(md5($thread->photo_path), 0, 8)]) }}" alt="" class="{{ $size }} shrink-0 rounded-full bg-slate-100 object-cover" loading="lazy">
@else
    <span class="flex {{ $size }} shrink-0 items-center justify-center rounded-full font-semibold {{ $tones[$thread->id % count($tones)] }}" aria-hidden="true">{{ $thread->initials($viewer) }}</span>
@endif
