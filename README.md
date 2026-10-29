# Sistem Checklist Kerjaan

Checklist tugas harian pegawai eL Hotel & Resort Sanur. Login memakai username + PIN Sistem Absensi.

## Pasang

```bash
composer install
cp .env.example .env && php artisan key:generate
# isi DB_*, ATTENDANCE_API_URL, ATTENDANCE_API_TOKEN (= TASKS_API_TOKEN di Sistem Absensi)
php artisan migrate
php artisan attendance:sync
php artisan checklist:import-workbook "eL Hotel Sanur Checklist.xlsx"
php artisan checklist:grant <username-admin> admin
```

Jadwalkan `php artisan schedule:run` setiap menit (sinkron pegawai tiap jam, pembersihan checklist kosong dan foto lama tiap hari).
Foto bukti disimpan di `storage/app/private`: pastikan folder itu ikut backup, dan atur PHP `upload_max_filesize` >= 8M serta `post_max_size` >= 10M. Produksi: HTTPS dan `SESSION_SECURE_COOKIE=true`.

Catatan lengkap untuk pengembang: `CLAUDE.md`.
