@props(['status'])
@php
    $class = match ($status) {
        \App\Enums\ChecklistStatus::Approved, \App\Enums\ItemStatus::Done => 'pill-done',
        \App\Enums\ChecklistStatus::Submitted, \App\Enums\ItemStatus::Ongoing => 'pill-ongoing',
        \App\Enums\ChecklistStatus::Returned, \App\Enums\ItemStatus::Pending => 'pill-pending',
        default => 'pill-neutral',
    };
@endphp
<span {{ $attributes->class($class) }}>{{ $status?->label() ?? 'Belum diisi' }}</span>
