{{-- Pesan di halaman. tone: success | danger | warning | info. --}}
@props(['tone' => 'info'])
@php($class = [
    'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
    'danger' => 'border-rose-200 bg-rose-50 text-rose-800',
    'warning' => 'border-amber-200 bg-amber-100 text-amber-800',
    'info' => 'border-slate-200 bg-slate-50 text-slate-700',
][$tone] ?? '')
<div {{ $attributes->merge(['role' => $tone === 'danger' ? 'alert' : 'status'])->class("rounded-xl border px-4 py-3 text-sm {$class}") }}>{{ $slot }}</div>
