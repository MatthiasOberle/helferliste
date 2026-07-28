#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
test_port="$((18000 + $$ % 1000))"
test_password='Sehr-Sicheres-Testpasswort-2026!'

mkdir -p "${test_root}/data" "${test_root}/www/assets/uploads"
cp -R "${project_root}/www/." "${test_root}/www/"

migration_output="$(php "${project_root}/Install/migrate.php" "${test_root}/data/helferliste.sqlite" "${test_root}/backups")"
setup_token="$(printf '%s\n' "${migration_output}" | grep -Eo '([A-F0-9]{4}-){7}[A-F0-9]{4}' | head -n 1)"
[[ -n "${setup_token}" ]]

php -S "127.0.0.1:${test_port}" -t "${test_root}/www" > "${test_root}/server.log" 2>&1 &
server_pid=$!
trap 'kill "${server_pid}" 2>/dev/null || true' EXIT

setup_status="000"
for attempt in 1 2 3 4 5; do
  setup_status="$(curl -sS -c "${test_root}/cookies.txt" -o "${test_root}/setup.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/admin.php" 2>/dev/null || true)"
  [[ "${setup_status}" == "200" ]] && break
  sleep 1
done
[[ "${setup_status}" == "200" ]]
grep -q 'Sichere Ersteinrichtung' "${test_root}/setup.html"

csrf_token="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/setup.html" | head -n 1)"
[[ -n "${csrf_token}" ]]

setup_post_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/setup-post.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode 'action=complete_setup' \
  --data-urlencode "setup_token=${setup_token}" \
  --data-urlencode "new_password=${test_password}" \
  --data-urlencode "repeat_password=${test_password}" \
  "http://127.0.0.1:${test_port}/admin.php")"
[[ "${setup_post_status}" == "302" ]]

admin_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/admin.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/admin.php")"
[[ "${admin_status}" == "200" ]]
grep -q 'Adminbereich' "${test_root}/admin.html"

database_file="${test_root}/data/helferliste.sqlite"
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_setup_token_hash';")" == "0" ]]
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_password_hash';")" == "1" ]]
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_auth_generation';")" == "1" ]]

sqlite3 "${database_file}" "
  INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_name', 'HTTP Testfest 2026', datetime('now'));
  INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_organizer', 'HTTP Beispielverein', datetime('now'));
  INSERT INTO access_codes (email, code, created_at) VALUES ('http@example.org', '7171', datetime('now'));
  INSERT INTO entries (name, status, note, created_at, access_code_id) VALUES ('HTTP Beispiel', 'help', 'Testhinweis', datetime('now'), last_insert_rowid());
  INSERT INTO entry_shifts (entry_id, shift_id) VALUES (last_insert_rowid(), 1);
"

archive_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/archive.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/event_archive.php")"
[[ "${archive_status}" == "200" ]]
grep -q 'HTTP Testfest 2026' "${test_root}/archive.html"
archive_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/archive.html" | head -n 1)"
[[ -n "${archive_csrf}" ]]

archive_post_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/archive-post.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${archive_csrf}" \
  --data-urlencode 'action=archive_event' \
  --data-urlencode 'confirmation=HTTP Testfest 2026' \
  --data-urlencode 'next_event_name=HTTP Testfest 2027' \
  --data-urlencode 'keep_shifts=1' \
  --data-urlencode 'keep_texts=1' \
  --data-urlencode 'keep_design=1' \
  --data-urlencode 'retain_personal_data=1' \
  "http://127.0.0.1:${test_port}/event_archive.php")"
[[ "${archive_post_status}" == "200" ]]
grep -q 'Veranstaltung wurde als Archiv' "${test_root}/archive-post.html"
[[ "$(sqlite3 "${database_file}" 'SELECT COUNT(*) FROM event_archives;')" == "1" ]]
[[ "$(sqlite3 "${database_file}" 'SELECT COUNT(*) FROM entries;')" == "0" ]]
[[ "$(sqlite3 "${database_file}" "SELECT setting_value FROM app_settings WHERE setting_key='event_name';")" == "HTTP Testfest 2027" ]]
find "${test_root}/data/backups" -type f -name 'helferliste-vor-abschluss-*.sqlite' -print -quit | grep -q .

public_status="$(curl -sS -o "${test_root}/public.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/")"
[[ "${public_status}" == "200" ]]
grep -q 'HTTP Testfest 2027' "${test_root}/public.html"
export_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/export.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/export.php")"
[[ "${export_status}" == "200" ]]
grep -q 'Export' "${test_root}/export.html"

curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o /dev/null "http://127.0.0.1:${test_port}/admin.php?logout=1"
login_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/login-post.html" -w '%{http_code}' \
  --data-urlencode 'login_user=admin' \
  --data-urlencode "login_password=${test_password}" \
  "http://127.0.0.1:${test_port}/admin.php")"
[[ "${login_status}" == "302" ]]

reset_output="$(php "${project_root}/Install/reset_admin.php" "${database_file}" "${test_root}/backups")"
printf '%s\n' "${reset_output}" | grep -q 'Neuer einmaliger Admin-Einrichtungscode'
reset_backup="$(printf '%s\n' "${reset_output}" | sed -n 's/^Sicherung: //p' | head -n 1)"
[[ -f "${reset_backup}" ]]
[[ "$(sqlite3 "${reset_backup}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_password_hash';")" == "1" ]]

after_reset_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/after-reset.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/admin.php")"
[[ "${after_reset_status}" == "200" ]]
grep -q 'Sichere Ersteinrichtung' "${test_root}/after-reset.html"

echo 'OK: Admin-Ersteinrichtung, Veranstaltungsabschluss, öffentliche Seite, Export, Reset und Sitzungsentzug funktionieren.'
