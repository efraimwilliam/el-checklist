# Sistem Checklist Kerjaan eL Hotel & Resort Sanur

Laravel 13 + Blade + Tailwind 4 (Vite), MySQL, Pest. Timezone Asia/Makassar (WITA). UI berbahasa Indonesia.

- `public/build` ikut di Git (server tanpa Node.js): jalankan `npm run build` sebelum commit bila CSS/view berubah.
- Test: `php artisan test` (database MySQL `checklist_test`). API absensi selalu dipalsukan (`Http::preventStrayRequests`,
  helper `fakeAttendance()` / `attendanceAccepts()` / `attendanceRefuses()` / `loggedIn()` / `nextRequest()` di `tests/Pest.php`).

## Login (Sistem Absensi)
- Pegawai login dengan username + PIN absensi. Server ini meneruskan ke API absensi (`config/attendance.php`, `.env` `ATTENDANCE_API_*`;
  token = `TASKS_API_TOKEN` di Sistem Absensi; endpoint `POST /api/v1/tasks/auth/verify-pin`). Browser tidak pernah memanggil API absensi.
- Tidak ada password/PIN di database ini, di sesi, maupun di log. `users` tanpa kolom password; tanpa "ingat saya".
- `App\Attendance\AttendanceApiService` menormalkan respons ke `AuthOutcome` (alasan dari API + `timeout`/`unavailable`/`malformed`/`not_configured`); login tidak diulang otomatis.
- `Auth\AttendanceLoginController`: kunci 5 gagal / 15 menit per username+IP (gangguan API tidak dihitung) + throttle `login` 20/menit per IP;
  pesan umum untuk salah PIN & nonaktif; `pin_not_set` menampilkan tombol ke `{ATTENDANCE_API_URL}/absen/pin/mulai?kembali={login Checklist}` (`ATTENDANCE_PIN_HELP_URL` menimpa): langsung ke menu PIN absensi dengan QR yang tersimpan di HP, lalu kembali ke login ini. Absensi hanya mau kembali ke alamat di `TASKS_URL`. Staff tidak punya dashboard absensi; PIN tetap dibuat di HP yang pernah absen.
  Berhasil = pegawai lokal dicocokkan lewat `external_id` (id absensi), harus punya `users` aktif, sesi diregenerasi, `auth.logged_in_at` disimpan.
- `EnsureActiveSession` (semua route login): keluar otomatis bila akun dicabut, pegawai nonaktif di absensi, atau melewati `session.absolute_lifetime`
  (default 720 menit). Batas diam = `SESSION_LIFETIME` (30). Logout = invalidate + token CSRF baru. Log hasil login: channel `auth` (`storage/logs/auth-*.log`).
- `ATTENDANCE_API_VERIFY_TLS=false` ditolak saat boot di produksi.

## Data master
- `attendance:sync` (tiap jam, juga tombol "Sinkron dari absensi" di Akses pengguna): `EmployeeSyncService` mencocokkan departemen lewat id absensi → kode → nama,
  pegawai lewat id absensi; pegawai tidak pernah dihapus, yang hilang dari absensi dinonaktifkan.
- `checklist:import-workbook {xlsx}` (`WorkbookImporter`): sheet Departemen, Jabatan, Tugas dari workbook checklist lama; bisa diulang; sheet Akun (PIN) TIDAK dibaca.
  Departemen yang sudah tersinkron tetap memakai nama absensi.
- Akses pertama: `php artisan checklist:grant {username} admin` (setelah sync).

## Peran & otorisasi
- `App\Enums\Role`: admin, hr, gm, hod, staff. Diatur Administrator di `/admin/akses` (`Admin\AccessController`), BUKAN dari jabatan/departemen absensi.
  HOD hanya melihat departemen yang dicentang (`department_user`); peran lain dikosongkan departemennya. Admin tidak bisa mencabut admin dirinya.
- `ChecklistPolicy`: lihat = pemilik / HOD departemen checklist / HR-GM-Admin; ubah = pemilik selama draft/dikembalikan; review = status submitted, bukan milik sendiri.
  Gate `review-checklists`, `manage-access`.

## Checklist
- `users.position_id` = jabatan checklist pegawai. `ChecklistFactory::forDate()` membuat checklist sekali per pegawai per tanggal dari tugas aktif jabatannya.
- Checklist menyalin departemen, nama jabatan, judul & standar tugas supaya riwayat utuh saat pegawai pindah/keluar atau templat diubah.
- Alur: draft → kirim (`submitted`, semua tugas wajib berstatus; Pending wajib catatan bila `CHECKLIST_PENDING_NOTE_REQUIRED`) → disetujui / dikembalikan (catatan wajib) → kirim ulang.
