{{-- Pil status. tone: done (hijau) | ongoing (emas) | pending (bata) | neutral. --}}
@props(['tone' => 'neutral'])
<span {{ $attributes->class('pill-'.$tone) }}>{{ $slot }}</span>
