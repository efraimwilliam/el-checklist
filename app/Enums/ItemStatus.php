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
            self::Done => 'Done',
            self::Ongoing => 'Ongoing',
            self::Pending => 'Pending',
        };
    }
}
