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

echo 'OK: Admin-Ersteinrichtung, Anmeldung, Reset und Sitzungsentzug funktionieren.'
