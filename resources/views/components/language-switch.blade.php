@props(['tone' => 'dark'])
@php($current = app()->getLocale())
<div class="inline-flex items-center rounded-full p-0.5 text-[12px] font-semibold {{ $tone === 'light' ? 'border border-slate-300 bg-white' : 'border border-white/20' }}" role="group" aria-label="{{ __('Bahasa') }}">
    @foreach (['id' => 'ID', 'en' => 'EN'] as $code => $label)
        <a href="{{ route('locale.update', $code) }}" lang="{{ $code }}" aria-current="{{ $current === $code ? 'true' : 'false' }}"
           class="rounded-full px-2.5 py-1 transition {{ $current === $code ? 'bg-gold-500 text-[#2a1d12]' : ($tone === 'light' ? 'text-slate-600 hover:text-slate-900' : 'text-white/70 hover:text-white') }}">{{ $label }}</a>
    @endforeach
</div>
