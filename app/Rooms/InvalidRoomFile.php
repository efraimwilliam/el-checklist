<?php

namespace App\Rooms;

use RuntimeException;

/** Berkas Excel daftar kamar tidak bisa dibaca atau tidak punya kolom yang dibutuhkan. */
class InvalidRoomFile extends RuntimeException {}
