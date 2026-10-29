<?php

namespace App\Enums;

/** Checklist selalu disimpan lengkap; status hanya menunjukkan sudah atau belum ditandai direview GM / HR. */
enum ChecklistStatus: string
{
    case Saved = 'saved';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Saved => 'Belum direview',
            self::Reviewed => 'Sudah direview',
        };
    }
}
