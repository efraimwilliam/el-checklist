#!/usr/bin/env bash
# Update Sistem Checklist Kerjaan ke versi terbaru dari GitHub.
# Pakai (sebagai akun biasa, tanpa sudo di depannya):  checklist-update.sh [--paksa]
#   --paksa  tetap menjalankan composer, migrasi, dan cache walau kode sudah versi terbaru
#            (dipakai juga untuk melanjutkan update yang gagal sesudah kode terlanjur diperbarui).
#
# Urutan: pemeriksaan awal -> backup database (bila checklist-backup.sh terpasang) -> mode pemeliharaan -> git pull
#         -> composer -> migrasi -> cache -> reload PHP-FPM -> buka aplikasi -> cek /up.
# Bila gagal SEBELUM kode berubah, aplikasi dibuka lagi. Bila gagal SESUDAH kode berubah (composer / migrasi),
# aplikasi sengaja dibiarkan dalam mode pemeliharaan, karena kode baru dengan skema setengah jadi bisa merusak data.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/checklist}"
WEB_USER="${WEB_USER:-www-data}"
BACKUP_SCRIPT="${BACKUP_SCRIPT:-/usr/local/bin/checklist-backup.sh}"

if [ "$(id -u)" -eq 0 ]; then
    echo "Jalankan tanpa sudo: git memakai kunci GitHub akun Anda, dan perintah yang butuh hak lebih memanggil sudo sendiri." >&2
    exit 1
fi

FORCE=0
if [ "${1:-}" = "--paksa" ]; then
    FORCE=1
fi

cd "$APP_DIR"
# artisan selalu sebagai pengguna web, supaya file cache dan log tidak jadi milik akun lain.
artisan() { sudo -u "$WEB_USER" php artisan "$@"; }

# Hanya satu update pada satu waktu.
if command -v flock >/dev/null 2>&1; then
    exec 9>"${TMPDIR:-/tmp}/checklist-update.lock"
    flock -n 9 || { echo "Update lain sedang berjalan." >&2; exit 1; }
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" = "HEAD" ]; then
    echo "Repo di $APP_DIR tidak berada di sebuah cabang (HEAD terlepas). Pindah dulu: git switch main" >&2
    exit 1
fi
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Ada perubahan lokal di $APP_DIR, update dibatalkan:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

OLD="$(git rev-parse --short HEAD)"
git fetch --quiet origin "$BRANCH"
if [ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCH")" ] && [ "$FORCE" -eq 0 ]; then
    echo "Sudah versi terbaru ($OLD). Tidak ada yang diubah. (Pakai --paksa untuk tetap menjalankan composer, migrasi, dan cache.)"
    exit 0
fi

# Pemeriksaan awal: semua yang dibutuhkan harus ada SEBELUM aplikasi ditutup.
FPM_SERVICE="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
[ -f .env ] || { echo "File .env tidak ada di $APP_DIR." >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "composer tidak ditemukan." >&2; exit 1; }
systemctl cat "$FPM_SERVICE" >/dev/null 2>&1 || { echo "Layanan $FPM_SERVICE tidak ditemukan (versi PHP berbeda dengan PHP-FPM yang terpasang?)." >&2; exit 1; }

# Password sudo ditanyakan sekarang, bukan di tengah proses saat aplikasi sudah ditutup.
sudo -v

STAGE=before
fail() {
    status=$?
    case "$STAGE" in
        down)
            artisan up || true
            echo "=== Update GAGAL (kode $status) sebelum kode berubah. Aplikasi sudah dibuka lagi. ===" >&2
            ;;
        changed)
            {
                echo "=== Update GAGAL (kode $status) SESUDAH kode diperbarui ($OLD -> yang baru)."
                echo "    Aplikasi SENGAJA dibiarkan dalam mode pemeliharaan."
                echo "    Perbaiki penyebabnya (baca pesan di atas), lalu jalankan:  checklist-update.sh --paksa"
                echo "    Atau kembali ke versi lama:  cd $APP_DIR && git reset --hard $OLD && composer install --no-dev -o && sudo -u $WEB_USER php artisan up"
                echo "    (migrasi yang sempat jalan tidak dibatalkan otomatis; backup terakhir ada di /var/backups/checklist)"
            } >&2
            ;;
        *)
            echo "=== Update dibatalkan (kode $status). Tidak ada yang berubah. ===" >&2
            ;;
    esac
}
trap fail ERR

if [ -x "$BACKUP_SCRIPT" ]; then
    echo ">> Backup database"
    sudo "$BACKUP_SCRIPT"
else
    echo "!! $BACKUP_SCRIPT belum terpasang: lanjut TANPA backup sebelum migrasi."
fi

echo ">> Mode pemeliharaan"
artisan down --render="errors::503" --retry=15 --refresh=15
STAGE=down

echo ">> Mengambil kode ($BRANCH)"
git pull --ff-only --quiet origin "$BRANCH"
NEW="$(git rev-parse --short HEAD)"
STAGE=changed

echo ">> Composer"
# display_errors=0: menyembunyikan peringatan "Deprecation Notice" dari Composer bawaan Ubuntu (tidak mempengaruhi hasil).
php -d display_errors=0 "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction --no-progress

echo ">> Migrasi database"
artisan migrate --force

echo ">> Cache"
artisan view:clear >/dev/null
artisan optimize >/dev/null
artisan queue:restart >/dev/null
# Hanya folder yang ditulis aplikasi saat berjalan; foto di storage/app tidak perlu disentuh.
sudo chgrp -R "$WEB_USER" bootstrap/cache storage/framework storage/logs
sudo chmod -R g+rwX bootstrap/cache storage/framework storage/logs
sudo systemctl reload "$FPM_SERVICE"

echo ">> Membuka aplikasi"
artisan up
STAGE=done

URL="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d "\"' ")"
CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "${URL%/}/up" 2>/dev/null || true)"
echo ">> Cek ${URL%/}/up: HTTP ${CODE:-gagal}"
if [ "$CODE" != "200" ]; then
    echo "!! /up tidak menjawab 200. Periksa:  sudo tail -50 $APP_DIR/storage/logs/laravel.log" >&2
    exit 2
fi

echo "=== Update selesai: $OLD -> $NEW ==="
git --no-pager diff --stat "$OLD" "$NEW" 2>/dev/null | tail -1 || true
