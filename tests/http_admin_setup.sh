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

setup_post_status="$(curl -sS -D "${test_root}/setup-post-headers.txt" -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/setup-post.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode 'action=complete_setup' \
  --data-urlencode "setup_token=${setup_token}" \
  --data-urlencode "new_password=${test_password}" \
  --data-urlencode "repeat_password=${test_password}" \
  "http://127.0.0.1:${test_port}/admin.php")"
[[ "${setup_post_status}" == "302" ]]
grep -qi '^Location: setup_wizard.php' "${test_root}/setup-post-headers.txt"

wizard_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/wizard-event.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/setup_wizard.php")"
[[ "${wizard_status}" == "200" ]]
grep -q '1. Veranstaltung' "${test_root}/wizard-event.html"
wizard_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/wizard-event.html" | head -n 1)"
[[ -n "${wizard_csrf}" ]]

event_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=${wizard_csrf}" \
  --data-urlencode 'action=save_event' \
  --data-urlencode 'app_name=HTTP Helferliste' \
  --data-urlencode 'event_organizer=HTTP Beispielverein' \
  --data-urlencode 'event_name=HTTP Testfest 2026' \
  --data-urlencode 'event_start_date=2026-07-10' \
  --data-urlencode 'event_end_date=2026-07-12' \
  "http://127.0.0.1:${test_port}/setup_wizard.php?step=1")"
[[ "${event_status}" == "302" ]]

curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/wizard-legal.html" "http://127.0.0.1:${test_port}/setup_wizard.php?step=2"
legal_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/wizard-legal.html" | head -n 1)"
[[ -n "${legal_csrf}" ]]
legal_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=${legal_csrf}" \
  --data-urlencode 'action=save_legal' \
  --data-urlencode "public_base_url=http://127.0.0.1:${test_port}" \
  --data-urlencode 'imprint_name=HTTP Beispielverein' \
  --data-urlencode 'imprint_address=Musterweg 1, 12345 Musterstadt' \
  --data-urlencode 'imprint_email=kontakt@example.org' \
  --data-urlencode 'privacy_contact_email=datenschutz@example.org' \
  "http://127.0.0.1:${test_port}/setup_wizard.php?step=2")"
[[ "${legal_status}" == "302" ]]

curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/wizard-shift.html" "http://127.0.0.1:${test_port}/setup_wizard.php?step=3"
shift_wizard_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/wizard-shift.html" | head -n 1)"
[[ -n "${shift_wizard_csrf}" ]]
shift_wizard_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=${shift_wizard_csrf}" \
  --data-urlencode 'action=create_shift' \
  --data-urlencode 'type=normal' \
  --data-urlencode 'title=Aufbau' \
  --data-urlencode 'shift_date=2026-07-10' \
  --data-urlencode 'start_time=16:00' \
  --data-urlencode 'end_time=20:00' \
  --data-urlencode 'location=Festplatz' \
  --data-urlencode 'note=Handschuhe mitbringen' \
  --data-urlencode 'max_slots=8' \
  "http://127.0.0.1:${test_port}/setup_wizard.php?step=3")"
[[ "${shift_wizard_status}" == "302" ]]

curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/wizard-review.html" "http://127.0.0.1:${test_port}/setup_wizard.php?step=4"
grep -q '4. Einrichtung prüfen' "${test_root}/wizard-review.html"
review_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/wizard-review.html" | head -n 1)"
[[ -n "${review_csrf}" ]]
finish_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=${review_csrf}" \
  --data-urlencode 'action=finish' \
  --data-urlencode 'event_status=draft' \
  "http://127.0.0.1:${test_port}/setup_wizard.php?step=4")"
[[ "${finish_status}" == "302" ]]

admin_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/admin.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/admin.php")"
[[ "${admin_status}" == "200" ]]
grep -q 'Adminbereich' "${test_root}/admin.html"
grep -q 'Einrichtung ist abgeschlossen' "${test_root}/admin.html"

database_file="${test_root}/data/helferliste.sqlite"
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_setup_token_hash';")" == "0" ]]
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_password_hash';")" == "1" ]]
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM app_settings WHERE setting_key='admin_auth_generation';")" == "1" ]]
[[ "$(sqlite3 "${database_file}" "SELECT setting_value FROM app_settings WHERE setting_key='setup_wizard_completed';")" == "1" ]]
shift_id="$(sqlite3 "${database_file}" "SELECT id FROM shifts WHERE active=1 AND title='Aufbau' ORDER BY id DESC LIMIT 1;")"
[[ -n "${shift_id}" ]]

codes_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/codes.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/codes.php")"
[[ "${codes_status}" == "200" ]]
codes_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/codes.html" | head -n 1)"
[[ -n "${codes_csrf}" ]]
import_preview_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/import-preview.html" -w '%{http_code}' \
  -F "csrf_token=${codes_csrf}" \
  -F 'action=preview_contact_import' \
  -F "contacts_csv=@${project_root}/tests/fixtures/kontakte.csv;type=text/csv" \
  "http://127.0.0.1:${test_port}/codes.php")"
[[ "${import_preview_status}" == "200" ]]
grep -q '2 Kontakte jetzt importieren' "${test_root}/import-preview.html"
grep -q 'ungültige E-Mail-Adresse' "${test_root}/import-preview.html"
import_token="$(sed -n 's/.*name="import_token" value="\([^"]*\)".*/\1/p' "${test_root}/import-preview.html" | head -n 1)"
[[ -n "${import_token}" ]]
import_confirm_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/import-confirm.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${codes_csrf}" \
  --data-urlencode 'action=confirm_contact_import' \
  --data-urlencode "import_token=${import_token}" \
  "http://127.0.0.1:${test_port}/codes.php")"
[[ "${import_confirm_status}" == "200" ]]
grep -q '2 Kontakte wurden importiert' "${test_root}/import-confirm.html"
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM access_codes WHERE email IN ('csv-eins@example.org', 'csv-zwei@example.org');")" == "2" ]]
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(DISTINCT code) FROM access_codes;")" == "2" ]]

sqlite3 "${database_file}" "
  INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_status', 'published', datetime('now'));
  INSERT INTO access_codes (email, code, created_at) VALUES ('http@example.org', '7171', datetime('now'));
  INSERT INTO entries (name, status, note, created_at, access_code_id) VALUES ('HTTP Beispiel', 'help', 'Testhinweis', datetime('now'), last_insert_rowid());
  INSERT INTO entry_shifts (entry_id, shift_id) VALUES (last_insert_rowid(), ${shift_id});
  INSERT INTO shifts (title, max_slots, sort_order, active, shift_date, start_time, end_time, location, note)
  VALUES ('Abbau', 8, 2, 1, '2026-07-12', '18:00', '20:00', 'Festplatz', 'Gemeinsam aufräumen');
"
entry_id="$(sqlite3 "${database_file}" "SELECT id FROM entries WHERE name='HTTP Beispiel' ORDER BY id DESC LIMIT 1;")"
additional_shift_id="$(sqlite3 "${database_file}" "SELECT id FROM shifts WHERE active=1 AND title='Abbau' ORDER BY id DESC LIMIT 1;")"
[[ -n "${entry_id}" ]]
[[ -n "${additional_shift_id}" ]]

unauthorized_invitations_status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${test_port}/invitations.php")"
[[ "${unauthorized_invitations_status}" == "302" ]]

invitations_status="$(curl -sS -D "${test_root}/invitations-headers.txt" -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/invitations.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/invitations.php?filter=pending")"
[[ "${invitations_status}" == "200" ]]
grep -qi '^Cache-Control: no-store' "${test_root}/invitations-headers.txt"
grep -qi '^Referrer-Policy: no-referrer' "${test_root}/invitations-headers.txt"
grep -q 'Einladen &amp; erinnern' "${test_root}/invitations.html"
grep -q 'csv-eins@example.org' "${test_root}/invitations.html"
grep -q 'Noch keine Rückmeldung' "${test_root}/invitations.html"
grep -q 'assets/vendor/qrcodegen.js' "${test_root}/invitations.html"
invitations_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/invitations.html" | head -n 1)"
[[ -n "${invitations_csrf}" ]]

template_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/invitations-saved.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${invitations_csrf}" \
  --data-urlencode 'action=save_templates' \
  --data-urlencode 'invitation_subject=Persönliche Einladung zu {veranstaltung}' \
  --data-urlencode $'invitation_body=Hallo,\n\nbitte antworte hier: {link}\n\n{organisation}' \
  --data-urlencode 'reminder_subject=Kurze Erinnerung zu {veranstaltung}' \
  --data-urlencode $'reminder_body=Hallo,\n\nuns fehlt noch deine Rückmeldung: {link}\nCode: {code}' \
  "http://127.0.0.1:${test_port}/invitations.php?filter=pending")"
[[ "${template_status}" == "200" ]]
grep -q 'Einladungs- und Erinnerungstexte wurden gespeichert' "${test_root}/invitations-saved.html"
[[ "$(sqlite3 "${database_file}" "SELECT setting_value FROM app_settings WHERE setting_key='invitation_subject';")" == 'Persönliche Einladung zu {veranstaltung}' ]]

pending_card_id="$(sqlite3 "${database_file}" "SELECT id FROM access_codes WHERE email='csv-eins@example.org';")"
card_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/invitation-card.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/invitations.php?card=${pending_card_id}&kind=reminder")"
[[ "${card_status}" == "200" ]]
grep -q 'Kurze Erinnerung zu HTTP Testfest 2026' "${test_root}/invitation-card.html"
grep -q 'index.php?code=' "${test_root}/invitation-card.html"
grep -q 'csv-eins@example.org' "${test_root}/invitation-card.html"
! grep -q 'http@example.org' "${test_root}/invitation-card.html"

qr_script_status="$(curl -sS -o "${test_root}/qrcodegen.js" -w '%{http_code}' "http://127.0.0.1:${test_port}/assets/vendor/qrcodegen.js")"
[[ "${qr_script_status}" == "200" ]]
grep -q 'Project Nayuki. (MIT License)' "${test_root}/qrcodegen.js"

direct_link_status="$(curl -sS -D "${test_root}/direct-link-headers.txt" -c "${test_root}/direct-link-cookies.txt" -o /dev/null -w '%{http_code}' "http://127.0.0.1:${test_port}/index.php?code=7171")"
[[ "${direct_link_status}" == "302" ]]
grep -qi '^Location: index.php' "${test_root}/direct-link-headers.txt"
grep -qi '^Cache-Control: no-store' "${test_root}/direct-link-headers.txt"
grep -qi '^Referrer-Policy: no-referrer' "${test_root}/direct-link-headers.txt"
direct_link_clean_status="$(curl -sS -b "${test_root}/direct-link-cookies.txt" -o "${test_root}/direct-link-clean.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/index.php")"
[[ "${direct_link_clean_status}" == "200" ]]
grep -q 'Deine Rückmeldung' "${test_root}/direct-link-clean.html"

# Geschützte Diensteinteilung: Admin-Upload, öffentliche Berechtigung,
# serverseitige Sperre und PDF-Auslieferung.
roster_pdf="${test_root}/diensteinteilung-test.pdf"
printf '%s\n' '%PDF-1.4' '1 0 obj' '<< /Type /Catalog >>' 'endobj' 'trailer' '<< /Root 1 0 R >>' '%%EOF' > "${roster_pdf}"

roster_admin_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-admin.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster_admin.php")"
[[ "${roster_admin_status}" == "200" ]]
grep -q 'Diensteinteilung' "${test_root}/roster-admin.html"
roster_admin_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/roster-admin.html" | head -n 1)"
[[ -n "${roster_admin_csrf}" ]]

printf 'keine pdf' > "${test_root}/keine-pdf.pdf"
invalid_roster_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-invalid.html" -w '%{http_code}' \
  -F "csrf_token=${roster_admin_csrf}" \
  -F 'action=upload' \
  -F "duty_roster_pdf=@${test_root}/keine-pdf.pdf;type=application/pdf" \
  "http://127.0.0.1:${test_port}/duty_roster_admin.php")"
[[ "${invalid_roster_status}" == "200" ]]
grep -q 'nicht als PDF erkannt' "${test_root}/roster-invalid.html"
[[ ! -e "${test_root}/data/duty-roster/current.pdf" ]]

premature_roster_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-premature.html" -w '%{http_code}' \
  -F "csrf_token=${roster_admin_csrf}" \
  -F 'action=upload' \
  -F 'publish_after_upload=1' \
  -F "duty_roster_pdf=@${roster_pdf};type=application/pdf" \
  "http://127.0.0.1:${test_port}/duty_roster_admin.php")"
[[ "${premature_roster_status}" == "200" ]]
grep -q 'erst veröffentlicht werden, wenn die Veranstaltung abgeschlossen' "${test_root}/roster-premature.html"
[[ ! -e "${test_root}/data/duty-roster/current.pdf" ]]

sqlite3 "${database_file}" "INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_status', 'closed', datetime('now'));"

upload_roster_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-upload.html" -w '%{http_code}' \
  -F "csrf_token=${roster_admin_csrf}" \
  -F 'action=upload' \
  -F 'publish_after_upload=1' \
  -F "duty_roster_pdf=@${roster_pdf};type=application/pdf" \
  "http://127.0.0.1:${test_port}/duty_roster_admin.php")"
[[ "${upload_roster_status}" == "200" ]]
grep -q 'sicher hochgeladen und veröffentlicht' "${test_root}/roster-upload.html"
[[ -f "${test_root}/data/duty-roster/current.pdf" ]]
[[ ! -e "${test_root}/www/data/duty-roster/current.pdf" ]]
[[ "$(sha256sum "${roster_pdf}" | awk '{print $1}')" == "$(sha256sum "${test_root}/data/duty-roster/current.pdf" | awk '{print $1}')" ]]

sqlite3 "${database_file}" "
  INSERT INTO access_codes (email, code, created_at) VALUES ('ohne-rueckmeldung@example.org', '8181', datetime('now'));
  INSERT INTO access_codes (email, code, created_at) VALUES ('absage@example.org', '9191', datetime('now'));
  INSERT INTO entries (name, status, created_at, access_code_id) VALUES ('Absage Beispiel', 'no_time', datetime('now'), last_insert_rowid());
"

closed_public_status="$(curl -sS -o "${test_root}/closed-public.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/")"
[[ "${closed_public_status}" == "200" ]]
grep -q 'Diensteinteilung ansehen' "${test_root}/closed-public.html"
! grep -q 'name="status"' "${test_root}/closed-public.html"

roster_public_status="$(curl -sS -c "${test_root}/roster-user-cookies.txt" -o "${test_root}/roster-public.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster.php")"
[[ "${roster_public_status}" == "200" ]]
roster_public_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/roster-public.html" | head -n 1)"
[[ -n "${roster_public_csrf}" ]]

unused_login_status="$(curl -sS -b "${test_root}/roster-user-cookies.txt" -c "${test_root}/roster-user-cookies.txt" -o "${test_root}/roster-unused.html" -w '%{http_code}' \
  --data-urlencode 'action=login' \
  --data-urlencode "csrf_token=${roster_public_csrf}" \
  --data-urlencode 'access=8181' \
  "http://127.0.0.1:${test_port}/duty_roster.php")"
[[ "${unused_login_status}" == "200" ]]
grep -q 'Zugriff ist mit diesen Angaben nicht möglich' "${test_root}/roster-unused.html"

valid_login_status="$(curl -sS -D "${test_root}/roster-login-headers.txt" -b "${test_root}/roster-user-cookies.txt" -c "${test_root}/roster-user-cookies.txt" -o /dev/null -w '%{http_code}' \
  --data-urlencode 'action=login' \
  --data-urlencode "csrf_token=${roster_public_csrf}" \
  --data-urlencode 'access=7171' \
  "http://127.0.0.1:${test_port}/duty_roster.php")"
[[ "${valid_login_status}" == "302" ]]
grep -qi '^Location: duty_roster.php' "${test_root}/roster-login-headers.txt"

roster_file_status="$(curl -sS -D "${test_root}/roster-file-headers.txt" -b "${test_root}/roster-user-cookies.txt" -o "${test_root}/roster-file.pdf" -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster_file.php")"
[[ "${roster_file_status}" == "200" ]]
grep -qi '^Content-Type: application/pdf' "${test_root}/roster-file-headers.txt"
grep -qi '^Cache-Control: private, no-store' "${test_root}/roster-file-headers.txt"
grep -qi '^X-Content-Type-Options: nosniff' "${test_root}/roster-file-headers.txt"
[[ "$(sha256sum "${roster_pdf}" | awk '{print $1}')" == "$(sha256sum "${test_root}/roster-file.pdf" | awk '{print $1}')" ]]

denied_file_status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster_file.php")"
[[ "${denied_file_status}" == "403" ]]

curl -sS -A 'Rate-Limit-Test' -c "${test_root}/rate-cookies.txt" -o "${test_root}/rate-start.html" "http://127.0.0.1:${test_port}/duty_roster.php"
rate_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/rate-start.html" | head -n 1)"
for failed_attempt in 1 2 3 4 5; do
  curl -sS -A 'Rate-Limit-Test' -b "${test_root}/rate-cookies.txt" -c "${test_root}/rate-cookies.txt" -o "${test_root}/rate-${failed_attempt}.html" \
    --data-urlencode 'action=login' \
    --data-urlencode "csrf_token=${rate_csrf}" \
    --data-urlencode 'access=0000' \
    "http://127.0.0.1:${test_port}/duty_roster.php"
done
curl -sS -A 'Rate-Limit-Test' -b "${test_root}/rate-cookies.txt" -c "${test_root}/rate-cookies.txt" -o "${test_root}/rate-blocked.html" \
  --data-urlencode 'action=login' \
  --data-urlencode "csrf_token=${rate_csrf}" \
  --data-urlencode 'access=0000' \
  "http://127.0.0.1:${test_port}/duty_roster.php"
grep -q 'Zu viele Fehlversuche' "${test_root}/rate-blocked.html"

roster_admin_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/roster-upload.html" | head -n 1)"
unpublish_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-unpublished.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${roster_admin_csrf}" \
  --data-urlencode 'action=set_publication' \
  --data-urlencode 'published=0' \
  "http://127.0.0.1:${test_port}/duty_roster_admin.php")"
[[ "${unpublish_status}" == "200" ]]
[[ "$(curl -sS -b "${test_root}/roster-user-cookies.txt" -o /dev/null -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster_file.php")" == "403" ]]

republish_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/roster-unpublished.html" | head -n 1)"
curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/roster-republished.html" \
  --data-urlencode "csrf_token=${republish_csrf}" \
  --data-urlencode 'action=set_publication' \
  --data-urlencode 'published=1' \
  "http://127.0.0.1:${test_port}/duty_roster_admin.php"
grep -q 'jetzt veröffentlicht' "${test_root}/roster-republished.html"

no_time_cookies="${test_root}/roster-no-time-cookies.txt"
curl -sS -c "${no_time_cookies}" -o "${test_root}/roster-no-time-start.html" "http://127.0.0.1:${test_port}/duty_roster.php"
no_time_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/roster-no-time-start.html" | head -n 1)"
[[ "$(curl -sS -b "${no_time_cookies}" -c "${no_time_cookies}" -o /dev/null -w '%{http_code}' \
  --data-urlencode 'action=login' \
  --data-urlencode "csrf_token=${no_time_csrf}" \
  --data-urlencode 'access=absage@example.org' \
  "http://127.0.0.1:${test_port}/duty_roster.php")" == "302" ]]
[[ "$(curl -sS -b "${no_time_cookies}" -o /dev/null -w '%{http_code}' "http://127.0.0.1:${test_port}/duty_roster_file.php")" == "200" ]]

edit_entry_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/edit-entry.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/edit_entry.php?id=${entry_id}")"
[[ "${edit_entry_status}" == "200" ]]
edit_entry_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/edit-entry.html" | head -n 1)"
[[ -n "${edit_entry_csrf}" ]]

edit_entry_post_status="$(curl -sS -D "${test_root}/edit-entry-post-headers.txt" -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/edit-entry-post.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${edit_entry_csrf}" \
  --data-urlencode "entry_id=${entry_id}" \
  --data-urlencode 'name=HTTP Beispiel' \
  --data-urlencode 'status=help' \
  --data-urlencode 'note=Testhinweis' \
  --data-urlencode "shifts[]=${shift_id}" \
  --data-urlencode "shifts[]=${additional_shift_id}" \
  "http://127.0.0.1:${test_port}/edit_entry.php")"
[[ "${edit_entry_post_status}" == "302" ]]
grep -qi '^Location: admin.php' "${test_root}/edit-entry-post-headers.txt"
[[ "$(sqlite3 "${database_file}" "SELECT COUNT(*) FROM entry_shifts WHERE entry_id=${entry_id};")" == "2" ]]

schedule_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/print-schedule.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/print_lists.php?view=schedule")"
[[ "${schedule_status}" == "200" ]]
grep -q 'Schichtplan' "${test_root}/print-schedule.html"
grep -q 'HTTP Beispiel' "${test_root}/print-schedule.html"
grep -q 'Aufbau' "${test_root}/print-schedule.html"
grep -q '@media print' "${test_root}/print-schedule.html"
! grep -q 'http@example.org' "${test_root}/print-schedule.html"
! grep -q '7171' "${test_root}/print-schedule.html"
! grep -q 'Testhinweis' "${test_root}/print-schedule.html"

attendance_status="$(curl -sS -b "${test_root}/cookies.txt" -o "${test_root}/print-attendance.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/print_lists.php?view=attendance")"
[[ "${attendance_status}" == "200" ]]
grep -q 'Anwesenheitslisten' "${test_root}/print-attendance.html"
grep -q 'HTTP Beispiel' "${test_root}/print-attendance.html"
grep -q 'Anwesend' "${test_root}/print-attendance.html"
! grep -q 'http@example.org' "${test_root}/print-attendance.html"
! grep -q '7171' "${test_root}/print-attendance.html"
! grep -q 'Testhinweis' "${test_root}/print-attendance.html"

shifts_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/shifts.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/edit_shifts.php")"
[[ "${shifts_status}" == "200" ]]
shifts_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "${test_root}/shifts.html" | head -n 1)"
[[ -n "${shifts_csrf}" ]]
shift_post_status="$(curl -sS -b "${test_root}/cookies.txt" -c "${test_root}/cookies.txt" -o "${test_root}/shift-post.html" -w '%{http_code}' \
  --data-urlencode "csrf_token=${shifts_csrf}" \
  --data-urlencode 'action=update' \
  --data-urlencode 'type=normal' \
  --data-urlencode "id=${shift_id}" \
  --data-urlencode 'title=Aufbau' \
  --data-urlencode 'shift_date=2026-07-10' \
  --data-urlencode 'start_time=16:00' \
  --data-urlencode 'end_time=20:00' \
  --data-urlencode 'location=Festplatz' \
  --data-urlencode 'note=Handschuhe mitbringen' \
  --data-urlencode 'max_slots=8' \
  --data-urlencode 'sort_order=1' \
  "http://127.0.0.1:${test_port}/edit_shifts.php")"
[[ "${shift_post_status}" == "200" ]]
grep -q 'Schicht wurde gespeichert' "${test_root}/shift-post.html"
[[ "$(sqlite3 "${database_file}" "SELECT shift_date || '|' || start_time || '|' || location FROM shifts WHERE id=${shift_id};")" == "2026-07-10|16:00|Festplatz" ]]

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
  --data-urlencode 'next_event_start_date=2027-07-09' \
  --data-urlencode 'next_event_end_date=2027-07-11' \
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
[[ "$(sqlite3 "${database_file}" "SELECT setting_value FROM app_settings WHERE setting_key='event_status';")" == "draft" ]]
find "${test_root}/data/backups" -type f -name 'helferliste-vor-abschluss-*.sqlite' -print -quit | grep -q .

public_status="$(curl -sS -o "${test_root}/public.html" -w '%{http_code}' "http://127.0.0.1:${test_port}/")"
[[ "${public_status}" == "200" ]]
grep -q 'HTTP Testfest 2027' "${test_root}/public.html"
grep -q 'noch nicht freigegeben' "${test_root}/public.html"
if grep -q 'name="action" value="login"' "${test_root}/public.html"; then
  echo 'FEHLER: Entwurf zeigt weiterhin das öffentliche Anmeldeformular.' >&2
  exit 1
fi
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

echo 'OK: Adminzugang, Einrichtungsassistent, CSV-Import, Einladungen, Erinnerungen, manuelle Schichtzuordnung, Drucklisten, Veranstaltungsabschluss, öffentliche Seite, Export, Reset und Sitzungsentzug funktionieren.'
