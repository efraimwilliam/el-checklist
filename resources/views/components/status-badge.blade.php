@props(['status'])
@php
    $class = match ($status) {
        \App\Enums\ChecklistStatus::Reviewed, \App\Enums\ItemStatus::Done => 'pill-done',
        \App\Enums\ChecklistStatus::HodReviewed => 'pill-ongoing',
        \App\Enums\ItemStatus::Ongoing => 'pill-ongoing',
        \App\Enums\ItemStatus::Pending => 'pill-pending',
        default => 'pill-neutral',
    };
@endphp
<span {{ $attributes->class($class) }}>{{ $status?->label() ?? 'Belum diisi' }}</span>
