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
            self::Admin => __('Administrator'),
            self::Hr => 'HR',
            self::Gm => __('General Manager'),
            self::Hod => __('HOD'),
            self::Staff => __('Staff'),
        };
    }

    /** Melihat & mereview checklist semua departemen. */
    public function seesAllDepartments(): bool
    {
        return in_array($this, [self::Admin, self::Hr, self::Gm], true);
    }

    /** Menandai checklist sudah direview. */
    public function reviews(): bool
    {
        return in_array($this, [self::Admin, self::Hr, self::Gm], true);
    }

    /** Mengisi, mengubah dan mengatur tugas untuk pegawai lain (HOD hanya departemennya). */
    public function managesOthers(): bool
    {
        return in_array($this, [self::Admin, self::Hr, self::Hod], true);
    }

    /** Admin dan HR boleh mengubah / menghapus checklist yang sudah direview. */
    public function overridesReview(): bool
    {
        return in_array($this, [self::Admin, self::Hr], true);
    }

    /** Akun ini masuk ke dashboard Absensi dengan password (HOD, HR, Administrator); Staff dan GM memakai PIN. */
    public function usesDashboardPassword(): bool
    {
        return in_array($this, [self::Admin, self::Hr, self::Hod], true);
    }
}
