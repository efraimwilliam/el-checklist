<?php

namespace App\Attendance;

/** Hasil verifikasi username + PIN di Sistem Absensi, dinormalkan. Hanya untuk server; pengguna melihat pesan umum. */
enum AuthOutcome: string
{
    case Authenticated = 'authenticated';
    case InvalidCredentials = 'invalid_credentials';
    case Locked = 'locked';
    case PinNotSet = 'pin_not_set';
    case PinDisabled = 'pin_disabled';
    case Inactive = 'inactive';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Malformed = 'malformed';
    case NotConfigured = 'not_configured';

    /** Masalah di sisi koneksi/API, bukan di kredensial pegawai. */
    public function isServiceProblem(): bool
    {
        return in_array($this, [self::Timeout, self::Unavailable, self::Malformed, self::NotConfigured], true);
    }
}
