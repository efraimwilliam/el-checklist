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

## Checklist (alur pengisian, simpan otomatis)
- Satu checklist = satu staff + satu jabatan + satu tanggal (unik `employee_id, position_id, date`). Disalin saat dibuat (departemen, nama jabatan, judul & standar tugas) sehingga riwayat utuh saat pegawai pindah /
  keluar atau tugas diubah. Dibuat saat "Mulai checklist" (`FillController::start`, `ChecklistService::start`: semua tugas belum diisi; memulai kombinasi yang sama membuka yang lama).
- Halaman pemilih `/isi` (`FillController::create`): HOD / HR / Admin memilih Departemen (bila lebih dari satu), Jabatan (dengan jumlah tugas), Tanggal (boleh mundur, tidak ke depan), Nama staff (dari data Absensi, aktif).
  "Dicek oleh" TIDAK diisi manual: otomatis nama akun yang memulai checklist (HOD / HR / Admin; null = "Diisi sendiri" untuk staff), tidak bisa diubah lewat simpan otomatis. Staff berakun hanya "Buka checklist" miliknya (`/checklist` mengalihkan ke sini). Panel "Lanjutkan checklist" = yang belum lengkap (7 hari).
- Halaman kerja `/checklists/{id}/isi` (`ChecklistController::fill`, view `checklists/work`): TIAP PILIHAN TERSIMPAN OTOMATIS, tidak ada tombol simpan. Tampilan Kartu = SATU TUGAS PER LAYAR (dek `[data-deck]` dengan CSS scroll-snap horizontal; sections dan `.cl-body` dibuat
  `display: contents` supaya semua kartu jadi anak langsung dek): geser jari kiri/kanan, panah, papan ketik, segmen progres per tugas (berwarna sesuai status, bisa diketuk), "Lihat semua tugas" (kotak nomor untuk loncat),
  "Lanjut otomatis" (setelah Done pindah ke kartu berikut; Ongoing/Pending tidak; bisa dimatikan, diingat di perangkat), dibuka di tugas pertama yang belum diisi, slide terakhir = Ringkasan (tugas tambahan + catatan umum),
  tombol status bertumpuk besar, di layar lebar satu kartu di tengah (maks 40rem). Tampilan Daftar = baris rapat yang tetap bisa diisi. Pilihan tampilan diingat di perangkat (localStorage); satu markup, gaya dipilih `data-view` di
  `resources/css/app.css` (`.cl-*`). Catatan otomatis untuk Ongoing/Pending, "Cara / standar",
  "Sisa tugas = Done", "Kosongkan", progres + skor sementara + "Lengkap ✓" + tombol "Selesai · lihat hasil" yang DISEMBUNYIKAN sampai skor sementara 100% (semua tugas Done; "Lihat hasil" di atas halaman tetap selalu ada), tugas tambahan (tidak dihitung skor), catatan umum. Indikator simpan + "Coba simpan lagi" + peringatan saat menutup halaman.
- API simpan otomatis (JSON, semua lewat `Gate update`): `PATCH checklists/{c}/items/{item}` (status boleh null; Pending tanpa alasan tetap tersimpan), `POST rest-done`, `POST clear`, `PATCH checklists/{c}` (note,
  extras; hanya kunci yang dikirim). `resources/js/checklist.js`: antrean per kunci, ketikan ditunda 700 ms dan dikirim saat berpindah isian, keadaan terbaru dibaca dari layar. Sesi habis -> 401 JSON (`EnsureActiveSession`).
- Lengkap (`Checklist::isComplete`, turunan, bukan kolom): semua tugas berstatus dan setiap Pending beralasan (`CHECKLIST_PENDING_NOTE_REQUIRED`). Checklist boleh tersimpan setengah jadi ("Belum lengkap n/m" di daftar & detail),
  tetapi hanya yang lengkap yang bisa direview. `checklist:prune-empty` (harian 03:00) menghapus checklist tanpa satu pun isian yang lebih tua dari 2 hari.
- Buat checklist sekaligus (`BulkAssignController`, `BulkAssignService`, tombol "Buat checklist hari ini" di `/isi`): membuatkan checklist KOSONG untuk setiap staff yang masuk kerja pada tanggal itu (boleh mundur, tidak ke depan),
  menurut jadwal Absensi (`AttendanceApiService::schedules`, endpoint Absensi `GET /api/v1/tasks/schedules?date=`: work / off / leave+kode / unscheduled). Dilewati: libur (OFF), izin/cuti/sakit/PH, jadwal yang BELUM DIISI (tidak dibuatkan, keputusan pengguna),
  dan staff yang jabatan checklistnya belum diatur (dilaporkan dengan nama). Aman ditekan berulang (yang sudah ada dihitung "sudah ada"). HOD hanya departemennya, HR/Admin semua; GM dan staff ditolak. Ringkasan tampil di halaman.
  Checklist yang dibuatkan ditandai `assigned_at`, `checked_by` kosong sampai atasan (bukan stafnya) pertama mengisinya (`ChecklistService::touch`), dan TIDAK dibersihkan `checklist:prune-empty` walau kosong ("Belum diisi", dasar dashboard "belum dicek").
  Panel "Lanjutkan checklist": HOD/HR/Admin melihat yang belum lengkap di departemen yang boleh diakses (7 hari), staff miliknya.
- Jabatan checklist per pegawai (`/tim/jabatan`, `TeamPositionController`, `employees.checklist_position_id`): diatur sekali oleh HOD (HR/Admin semua; tidak untuk dirinya sendiri kecuali Admin), dengan saran dari teks jabatan Absensi
  (`PositionSuggester`: cocok persis / singkatan di dalam kurung / tepat satu yang memuat; tetap harus disimpan). Staff berakun memakai jabatan ini (lalu `users.position_id`) saat membuka checklist miliknya.
- Pembuatan otomatis: jam (WITA) per departemen di `departments.auto_assign_at`, diatur HOD (departemennya) / HR / Admin di bagian "Pembuatan otomatis" halaman `/isi`; kosong = mati. `checklist:auto-assign` (tiap 5 menit lewat scheduler) membuatkan
  untuk departemen yang jamnya sudah lewat dan belum jalan hari ini (`auto_assigned_on`); gagal membaca jadwal = dicoba lagi 5 menit kemudian, tidak ditandai selesai. Pembuatnya `filled_by` null.
- Foto bukti per tugas (`checklist_item_photos`, `ChecklistPhotoService`, `ChecklistPhotoController`): dua tombol di tiap tugas: "Kamera" (dialog kamera di halaman: `getUserMedia`, pratinjau langsung, tombol potret, "Balik kamera" depan/belakang, tetap terbuka sampai batas foto; butuh HTTPS atau localhost,
  bila tidak tersedia / izin ditolak jatuh ke kamera bawaan HP lewat `<input capture=environment data-photo-native>`) dan "Galeri" (`<input type=file accept=image/* multiple>` tanpa `capture`), maksimal 3 foto per tugas
  (`config/checklist.php` -> `photos.per_task`). Dikecilkan di HP dulu (`compress()` di `checklist.js`: sisi terpanjang 1280 px, JPEG 0.8), lalu server mengolah ulang dengan GD (putar sesuai EXIF, maks 1600 px, JPEG baru tanpa metadata;
  file yang bukan gambar ditolak). Disimpan di disk privat `local` (`storage/app/private/checklist-photos/{checklist}/{uuid}.jpg`), TIDAK di folder publik: hanya dilayani `GET /checklists/{c}/photos/{photo}` setelah `Gate view`.
  Tambah / hapus lewat `Gate update` (terkunci setelah direview, kecuali HR/Admin). Menghapus checklist ikut menghapus berkasnya. Foto ikut tampil di detail dan hasil cetak.
- Pengaturan Administrator (`/admin/pengaturan`, `Admin\SettingsController`, tabel `settings`, `ChecklistSettings` scoped): `photo_requirement` = optional / pending (wajib untuk tugas Pending) / all (wajib untuk semua tugas) dan
  `photo_retention_days` (bawaan 90, 0 = tidak pernah). Foto wajib yang belum ada membuat checklist "belum lengkap" (`Checklist::missingPhotos`, ikut `isComplete`), jadi belum bisa direview. `checklist:prune-photos` (harian 03:30)
  menghapus foto yang lewat masa simpan; isian checklist tetap ada. Server produksi: `upload_max_filesize` >= 8M dan `post_max_size` >= 10M (HP sudah mengecilkan foto, tapi server menerima sampai 8 MB).
- Status: `saved` (Belum direview) / `reviewed` (Sudah direview). GM/HR/Admin menekan "Tandai sudah direview" (tercatat siapa & kapan, bukan checklist sendiri); sesudahnya HOD/staff tidak bisa mengubah,
  HR/Admin masih bisa edit dan hapus. Detail `/checklists/{id}` (ringkasan, skor n/N Done, pil, tugas tambahan, Edit, Cetak, Hapus). Daftar `/checklists` (menu Riwayat) per tanggal.
- Skor = persentase tugas Done; warna: >= 90 hijau, 70-89 emas, < 70 merah.
- Belum ada (rencana dari tutorial): dashboard, riwayat dengan filter lengkap + unduh CSV, email ringkasan harian.
