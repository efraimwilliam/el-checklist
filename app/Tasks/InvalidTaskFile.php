<?php

namespace App\Tasks;

use RuntimeException;

/** Berkas impor Daftar tugas tidak bisa dibaca atau belum boleh disimpan. Pesannya sudah dalam bahasa pengguna. */
final class InvalidTaskFile extends RuntimeException {}
