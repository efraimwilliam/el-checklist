<?php

namespace App\Enums;

/** Peran di aplikasi ini, diatur Administrator. Tidak diturunkan dari jabatan/departemen di Sistem Absensi. */
enum Role: string
{
    case Admin = 'admin';
    case Hr = 'hr';
    case Gm = 'gm';
    case Hod = 'hod';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Hr => 'HR',
            self::Gm => 'General Manager',
            self::Hod => 'HOD',
            self::Staff => 'Staff',
        };
    }

    /** Melihat & mereview checklist semua departemen. */
    public function seesAllDepartments(): bool
    {
        return in_array($this, [self::Admin, self::Hr, self::Gm], true);
    }

    public function reviews(): bool
    {
        return $this !== self::Staff;
    }
}
