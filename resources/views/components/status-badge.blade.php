@props(['status'])
@php
    $classes = match ($status) {
        \App\Enums\ChecklistStatus::Approved, \App\Enums\ItemStatus::Done => 'bg-emerald-100 text-emerald-800',
        \App\Enums\ChecklistStatus::Submitted, \App\Enums\ItemStatus::Ongoing => 'bg-amber-100 text-amber-800',
        \App\Enums\ChecklistStatus::Returned, \App\Enums\ItemStatus::Pending => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp
<span {{ $attributes->class("inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}") }}>{{ $status?->label() ?? 'Belum diisi' }}</span>
