{{-- Foto profil pegawai dari Sistem Absensi bila ada, selain itu inisial berwarna. $person = User, $size = kelas ukuran. --}}
@php
    $tones = ['bg-[#f3e6c4] text-[#8a6a22]', 'bg-sky-100 text-sky-800', 'bg-emerald-100 text-emerald-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-orange-100 text-orange-800'];
    $photoUrl = $person->employee?->photoUrl();
@endphp
@if ($photoUrl)
    <img src="{{ $photoUrl }}" alt="" class="{{ $size }} shrink-0 rounded-full bg-slate-100 object-cover" loading="lazy">
@else
    <span class="flex {{ $size }} shrink-0 items-center justify-center rounded-full font-semibold {{ $tones[$person->id % count($tones)] }}" aria-hidden="true">{{ mb_strtoupper(collect(preg_split('/\s+/', trim($person->name())) ?: [])->filter(fn ($w) => preg_match('/^\p{L}/u', $w))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '#' }}</span>
@endif
