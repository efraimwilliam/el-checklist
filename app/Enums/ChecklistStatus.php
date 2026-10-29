<?php

namespace App\Enums;

/**
 * Belum direview -> Direview oleh HOD (masih bisa diubah HOD / staff) -> Direview oleh GM / HR (terkunci; hanya HR / Administrator yang bisa mengubah).
 */
enum ChecklistStatus: string
{
    case Saved = 'saved';
    case HodReviewed = 'hod_reviewed';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Saved => 'Belum direview',
            self::HodReviewed => 'Direview oleh HOD',
            self::Reviewed => 'Direview oleh GM/HR',
        };
    }
}
