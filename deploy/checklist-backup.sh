#!/usr/bin/env bash
# Backup harian Sistem Checklist: database, foto bukti, ringkasan mingguan, dan .env (hanya di server ini).
# Pasang:  sudo install -m 750 /var/www/checklist/deploy/checklist-backup.sh /usr/local/bin/checklist-backup.sh
# Catatan hasil ada di backup.log. Salinan ke luar server (mis. NAS): tulis satu folder per baris di
# /var/backups/checklist/tujuan-backup.txt. Yang disalin ke sana hanya database/, foto-bukti/, dan laporan/
# (TIDAK pernah aplikasi/.env, karena berisi password dan token).
set -u

APP="${APP_DIR:-/var/www/checklist}"
DIR="${BACKUP_DIR:-/var/backups/checklist}"
KEEP_DAYS="${KEEP_DAYS:-30}"
LOG="$DIR/backup.log"
TODAY="$(date +%F)"
PRIV="$APP/storage/app/private"

mkdir -p "$DIR/database" "$DIR/aplikasi"
log() { echo "$(date '+%F %T') $*" >> "$LOG"; }

OUT="$DIR/database/checklist-$TODAY.sql"
if ! mysqldump --defaults-extra-file="$DIR/mysql-backup.cnf" --single-transaction --no-tablespaces --set-gtid-purged=OFF checklist > "$OUT.tmp" 2> "$DIR/mysqldump-error.txt"; then
    rm -f "$OUT.tmp"
    log "GAGAL backup database: $(cat "$DIR/mysqldump-error.txt")"
    exit 1
fi
mv -f "$OUT.tmp" "$OUT"
rm -f "$DIR/mysqldump-error.txt"

for pair in checklist-photos:foto-bukti laporan:laporan; do
    src="${pair%%:*}"; dst="${pair##*:}"
    [ -d "$PRIV/$src" ] && rsync -a "$PRIV/$src/" "$DIR/$dst/"
done
cp -f "$APP/.env" "$DIR/aplikasi/.env"
find "$DIR/database" -name '*.sql' -mtime +"$KEEP_DAYS" -delete
log "backup selesai: $OUT"

# --- Salinan ke luar server -------------------------------------------------------------------------------
# Tujuan yang belum ter-mount (masih di disk server) ditolak, supaya salinan tidak diam-diam memenuhi disk server.
on_server_disk() {
    command -v findmnt >/dev/null 2>&1 || return 1
    local p="$1"
    while [ ! -e "$p" ] && [ "$p" != "/" ]; do p="$(dirname "$p")"; done
    [ "$(findmnt -n -o TARGET -T "$p" 2>/dev/null)" = "/" ]
}

if [ -f "$DIR/tujuan-backup.txt" ]; then
    while IFS= read -r dest || [ -n "$dest" ]; do
        dest="${dest#"${dest%%[![:space:]]*}"}"   # buang spasi di awal
        dest="${dest%"${dest##*[![:space:]]}"}"   # dan di akhir (nama folder boleh memuat spasi di tengah)
        case "$dest" in '' | '#'*) continue ;; esac

        if on_server_disk "$dest"; then
            log "GAGAL menyalin ke $dest: folder itu belum ter-mount (masih di disk server). Periksa mount NAS."
            echo "PERINGATAN: salinan ke $dest dilewati, folder belum ter-mount." >&2
            continue
        fi

        # -rt tanpa izin / pemilik: folder jaringan (SMB) tidak mendukungnya. Tanpa --delete: berkas lama diurus oleh retensi di bawah.
        if mkdir -p "$dest/database" \
            && rsync -rt --no-perms --no-owner --no-group "$DIR/database/" "$dest/database/" \
            && { [ ! -d "$DIR/foto-bukti" ] || rsync -rt --no-perms --no-owner --no-group "$DIR/foto-bukti/" "$dest/foto-bukti/"; } \
            && { [ ! -d "$DIR/laporan" ] || rsync -rt --no-perms --no-owner --no-group "$DIR/laporan/" "$dest/laporan/"; }; then
            find "$dest/database" -name '*.sql' -mtime +"$KEEP_DAYS" -delete
            log "salinan ke $dest selesai"
        else
            log "GAGAL menyalin ke $dest"
            echo "PERINGATAN: salinan ke $dest gagal (lihat $LOG)." >&2
        fi
    done < "$DIR/tujuan-backup.txt"
fi
