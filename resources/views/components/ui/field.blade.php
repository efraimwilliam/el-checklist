{{-- Satu kolom form: label, isian (slot), keterangan, dan pesan error dari validasi server.
     ui.js menautkan pesan ke isian (aria-invalid, aria-describedby), jadi pembaca layar ikut membacanya. --}}
@props(['name', 'label' => null, 'hint' => null, 'for' => null])
@php($key = str_replace(['[', ']'], ['.', ''], $name))
@php($error = $errors->first($key))
<div {{ $attributes->class('min-w-0') }} data-field>
    @if ($label)
        <label for="{{ $for ?? $name }}" class="label">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($error)
        <p class="mt-1 text-sm text-rose-700" data-field-error>{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1 text-[13px] text-slate-500" data-field-hint>{{ $hint }}</p>
    @endif
</div>
