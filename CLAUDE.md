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
  pesan umum untuk salah PIN & nonaktif; `pin_not_set` = pesan petunjuk saja (minta HR/HOD membuka izin buat PIN, lalu buat PIN di aplikasi absensi dan kembali ke sini); tidak ada tombol/pengalihan, PIN tidak pernah dibuat di aplikasi ini.
  Berhasil = pegawai lokal dicocokkan lewat `external_id` (id absensi), harus punya `users` aktif, sesi diregenerasi, `auth.logged_in_at` disimpan.
- `EnsureActiveSession` (semua route login): keluar otomatis bila akun dicabut, pegawai nonaktif di absensi, atau melewati `session.absolute_lifetime`
  (default 720 menit). Batas diam = `SESSION_LIFETIME` (360 menit = 6 jam, sesuai tutorial). Logout = invalidate + token CSRF baru. Log hasil login: channel `auth` (`storage/logs/auth-*.log`).
- `ATTENDANCE_API_VERIFY_TLS=false` ditolak saat boot di produksi.

## Data master
- `attendance:sync` (tiap jam, juga tombol "Sinkron dari absensi" di Akses pengguna): `EmployeeSyncService` mencocokkan departemen lewat id absensi → kode → nama,
  pegawai lewat id absensi; pegawai tidak pernah dihapus, yang hilang dari absensi dinonaktifkan.
- `checklist:import-workbook {xlsx}` (`WorkbookImporter`): sheet Departemen, Jabatan, Tugas dari workbook checklist lama; bisa diulang; sheet Akun (PIN) TIDAK dibaca.
  Departemen yang sudah tersinkron tetap memakai nama absensi.
- Akses pertama: `php artisan checklist:grant {username} admin` (setelah sync).

## Peran & otorisasi
- `App\Enums\Role`: admin, hr, gm, hod, staff. Diatur Administrator di `/admin/akses` (`Admin\AccessController`), BUKAN dari jabatan/departemen absensi.
  HOD hanya departemen yang dicentang (`department_user`); peran lain dikosongkan departemennya. Admin tidak bisa mencabut admin dirinya.
- Pembagian (mengikuti tutorial "Daily Department Checklist", lihat `Role`): HOD mengisi untuk staff di departemennya; staff berakun mengisi miliknya sendiri; HR & Administrator semua departemen
  (juga edit/hapus setelah direview, kelola tugas); GM hanya melihat & mereview (tidak mengisi). Gate: `fill-checklists` (bukan GM), `view-checklists` (bukan Staff), `manage-team-tasks`/`assign-tasks`,
  `manage-tasks` (Admin, HR), `manage-access` (Admin).
- `ChecklistPolicy` (selalu dicek di server): `view` = pemilik / HOD departemen / HR-GM-Admin; `fill` (checklist baru) = jabatan & pegawai harus satu departemen dan pegawai aktif; HOD departemennya,
  staff hanya dirinya + jabatannya sendiri, GM tidak; `update` = sebelum direview HOD/HR/Admin/pemilik, sesudah direview hanya HR/Admin; `review` = GM/HR/Admin, belum direview, bukan checklist sendiri; `delete` = HR/Admin.

## Kelola tugas
- `/admin/tugas` (`Admin\TaskController`, gate `manage-tasks` = Administrator & HR): tambah jabatan per departemen (nama unik per departemen), ubah nama / nonaktifkan jabatan,
  tambah / ubah / nonaktifkan / hapus / geser tugas. Urutan `sort` dirapikan 1..n setiap geser (data impor bisa bernomor ganda). Perubahan hanya berlaku untuk checklist yang dibuat sesudahnya
  (checklist menyalin isi tugas), jadi riwayat tetap utuh walau tugas dihapus.

## Atur tugas tim
- `/tim` (`TeamTaskController`, `assign-tasks` per pegawai): memilih tugas apa saja yang masuk checklist seorang PEGAWAI ABSENSI (tabel `employee_task`, `employees.custom_tasks`; tidak perlu punya akun,
  karena kebanyakan staff diisi HOD). HOD hanya departemennya dan tidak untuk dirinya sendiri (Administrator boleh); HR/Admin semua departemen.
- Bawaan (`custom_tasks = false`): semua tugas aktif jabatan yang dipilih saat mengisi. Setelah disimpan: hanya tugas terpilih, boleh dari jabatan lain di departemen pegawai itu (divalidasi di server:
  aktif + departemen sama + minimal satu). Halaman edit punya "Mulai dari tugas jabatan" untuk mencentang semua tugas satu jabatan lebih dulu. "Kembalikan ke bawaan" mengosongkan pilihan.
- `ChecklistService::tasksFor(Employee, Position)`: tugas jabatan yang dipilih lebih dulu, lalu jabatan lain; tugas nonaktif / terhapus dilewati. Hanya berlaku untuk checklist yang dibuat sesudahnya.

## Checklist (alur pengisian)
- Satu checklist = satu staff + satu jabatan + satu tanggal (unik `employee_id, position_id, date`). Disalin saat dibuat (departemen, nama jabatan, judul & standar tugas) sehingga riwayat utuh saat pegawai pindah /
  keluar atau tugas diubah. Hanya dibuat saat disimpan dan selalu lengkap: semua tugas berstatus Done / Ongoing / Pending, Pending wajib beralasan bila `CHECKLIST_PENDING_NOTE_REQUIRED`
  (`ChecklistInput`; skrip `resources/js/checklist.js` menunjukkan tugas bermasalah sebelum dikirim). Draft sebagian tidak ada di server.
- Halaman isi `/isi` (`FillController`): HOD / HR / Admin memilih Departemen (bila lebih dari satu), Jabatan (dengan jumlah tugas), Tanggal (boleh mundur, tidak ke depan), Nama staff (dari data Absensi, aktif) dan
  "Dicek oleh" (bawaan: yang terakhir dipakai akun itu, lalu nama sendiri), lalu "Mulai checklist" menampilkan form. Staff berakun langsung melihat form miliknya (`/checklist` mengalihkan ke sini).
  Isi ulang kombinasi yang sama = memperbarui (`PUT /checklists/{id}` lewat `/isi?...&mulai=1`, ada pemberitahuan); simpan ganda bersamaan -> `DuplicateChecklist`.
- Form: "Cara / standar", catatan otomatis untuk Ongoing/Pending, "Sisa tugas = Done", "Kosongkan", progres + skor sementara, "Tugas tambahan" (`checklist_extra_tasks`, tidak dihitung skor), catatan umum.
- Status: `saved` (Belum direview) / `reviewed` (Sudah direview). GM/HR/Admin menekan "Tandai sudah direview" (tercatat siapa & kapan, bukan checklist sendiri); sesudahnya HOD/staff tidak bisa mengubah,
  HR/Admin masih bisa edit dan hapus. Detail `/checklists/{id}` (ringkasan, skor n/N Done, pil, tugas tambahan, Edit, Cetak, Hapus). Daftar `/checklists` (menu Riwayat) per tanggal.
- Skor = persentase tugas Done; warna: >= 90 hijau, 70-89 emas, < 70 merah.
- Belum ada (rencana dari tutorial): dashboard, riwayat dengan filter lengkap + unduh CSV, draft di HP, email ringkasan harian.
