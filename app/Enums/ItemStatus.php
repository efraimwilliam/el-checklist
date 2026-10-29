<?php

namespace App\Enums;

enum ItemStatus: string
{
    case Done = 'done';
    case Ongoing = 'ongoing';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Done => __('Done'),
            self::Ongoing => __('Ongoing'),
            self::Pending => __('Pending'),
        };
    }
}
