<?php

namespace App\Enums;

enum ChecklistStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Belum dikirim',
            self::Submitted => 'Menunggu review',
            self::Approved => 'Disetujui',
            self::Returned => 'Dikembalikan',
        };
    }

    /** Pegawai masih boleh mengisi / mengubah. */
    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }
}
