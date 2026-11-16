#!/usr/bin/env bash
# Terbitkan aplikasi Android versi baru ke server (pembaruan dari server sendiri, tanpa Play Store).
# Pakai:  android/rilis.sh william@ip-server "Isi singkat yang baru"   [--wajib]
# Sebelumnya: naikkan versionCode dan versionName di android/app/build.gradle.kts.
# --wajib = versi lebih lama dari ini harus memperbarui sebelum aplikasi bisa dipakai.
set -euo pipefail
SERVER="${1:?Isi alamat server, mis. william@192.168.1.10}"
NOTES="${2:-}"
MANDATORY="${3:-}"
APP_DIR="${APP_DIR:-/var/www/checklist}"
cd "$(dirname "$0")"

export JAVA_HOME="${JAVA_HOME:-/opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home}"
./gradlew -q assembleRelease
OUT=app/build/outputs/apk/release
CODE=$(python3 -c "import json;print(json.load(open('$OUT/output-metadata.json'))['elements'][0]['versionCode'])")
NAME=$(python3 -c "import json;print(json.load(open('$OUT/output-metadata.json'))['elements'][0]['versionName'])")
echo ">> Versi $NAME ($CODE) dibuat, mengirim ke $SERVER"

scp -q "$OUT/app-release.apk" "$OUT/output-metadata.json" "$SERVER:/tmp/"
EXTRA=""
[ "$MANDATORY" = "--wajib" ] && EXTRA="--wajib-sejak=$CODE"
# printf %q: catatan dengan spasi / tanda kutip tetap utuh di shell server.
ssh -t "$SERVER" "cd $APP_DIR && sudo -u www-data php artisan aplikasi:rilis /tmp/app-release.apk --catatan=$(printf %q "$NOTES") $EXTRA && rm -f /tmp/app-release.apk /tmp/output-metadata.json"
