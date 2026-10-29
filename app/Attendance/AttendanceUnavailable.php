<?php

namespace App\Attendance;

use RuntimeException;

/** API absensi tidak dapat dipakai (mati, timeout, token ditolak, atau respons tidak dikenal). */
class AttendanceUnavailable extends RuntimeException {}
