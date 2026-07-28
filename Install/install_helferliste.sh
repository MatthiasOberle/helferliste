#!/usr/bin/env bash
set -euo pipefail

APP_NAME="helferliste"
APP_ROOT="/var/www/${APP_NAME}"
PUBLIC_DIR="${APP_ROOT}/public"
DATA_DIR="${APP_ROOT}/data"
DB_FILE="${DATA_DIR}/helferliste.sqlite"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WEB_SOURCE="${PACKAGE_ROOT}/www"
DOMAIN=""
USE_WWW="N"
PHP_FPM_SOCKET=""
PHP_VERSION=""
BASE_URL=""

red='\033[0;31m'; green='\033[0;32m'; yellow='\033[1;33m'; blue='\033[0;34m'; nc='\033[0m'
info(){ echo -e "${blue}[INFO]${nc} $1"; }
ok(){ echo -e "${green}[OK]${nc} $1"; }
warn(){ echo -e "${yellow}[HINWEIS]${nc} $1"; }
fail(){ echo -e "${red}[FEHLER]${nc} $1"; exit 1; }

need_root(){
  [[ "${EUID}" -eq 0 ]] || fail "Bitte mit sudo starten: sudo bash Install/install_helferliste.sh"
}

ask_questions(){
  echo
  echo "Helferliste Installation fuer Ubuntu/Debian"
  echo "------------------------------------------"
  read -rp "Domain oder IP ohne http:// oder Pfad (z.B. verein.de oder 192.0.2.10): " DOMAIN
  DOMAIN="${DOMAIN:-localhost}"
  [[ "${DOMAIN}" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Ungueltige Domain/IP. Erlaubt sind nur Buchstaben, Zahlen, Punkt und Bindestrich."
  [[ "${DOMAIN}" != .* && "${DOMAIN}" != *. ]] || fail "Domain/IP darf nicht mit einem Punkt beginnen oder enden."
  read -rp "Auch www.${DOMAIN} in Nginx eintragen? [j/N]: " USE_WWW
  USE_WWW="${USE_WWW:-N}"
}

install_packages(){
  info "Installiere benoetigte Pakete ..."
  apt update
  apt install -y nginx php-fpm php-cli php-sqlite3 sqlite3 unzip curl ca-certificates rsync
  ok "Pakete installiert."
}

detect_php_socket(){
  PHP_FPM_SOCKET="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n 1 || true)"
  [[ -n "${PHP_FPM_SOCKET}" ]] || fail "Kein PHP-FPM Socket gefunden. Ist php-fpm installiert?"
  PHP_VERSION="$(basename "${PHP_FPM_SOCKET}")"
  PHP_VERSION="${PHP_VERSION#php}"
  PHP_VERSION="${PHP_VERSION%-fpm.sock}"
  [[ "${PHP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]] || fail "PHP-Version konnte aus ${PHP_FPM_SOCKET} nicht ermittelt werden."
  ok "PHP-FPM Socket: ${PHP_FPM_SOCKET}"
}

configure_php_uploads(){
  local php_upload_config="/etc/php/${PHP_VERSION}/fpm/conf.d/99-helferliste-uploads.ini"
  info "Konfiguriere Bild-Uploads bis 8 MB ..."
  cat > "${php_upload_config}" <<PHPINI
upload_max_filesize = 8M
post_max_size = 10M
max_file_uploads = 20
PHPINI
  systemctl reload "php${PHP_VERSION}-fpm"
  ok "PHP-Uploadgrenzen wurden gesetzt."
}

create_directories(){
  info "Erstelle Ordnerstruktur ..."
  mkdir -p "${PUBLIC_DIR}" "${DATA_DIR}"
  ok "Ordner erstellt: ${APP_ROOT}"
}

copy_project_files(){
  if [[ -d "${WEB_SOURCE}" ]]; then
    info "Kopiere Webdateien aus ${WEB_SOURCE} nach ${PUBLIC_DIR} ..."
    rsync -a --delete \
      --exclude 'web.config' \
      --exclude 'assets/uploads/*.png' \
      --exclude 'assets/uploads/*.jpg' \
      --exclude 'assets/uploads/*.jpeg' \
      --exclude 'assets/uploads/*.webp' \
      --exclude 'assets/uploads/*.gif' \
      "${WEB_SOURCE}/" "${PUBLIC_DIR}/"
    ok "Webdateien kopiert."
  else
    fail "Der Ordner www wurde nicht gefunden. Bitte das komplette aktuelle Paket hochladen."
  fi
}

create_database(){
  info "Erstelle/pruefe und migriere SQLite-Datenbank ..."
  [[ -f "${SCRIPT_DIR}/migrate.php" ]] || fail "migrate.php wurde nicht gefunden. Bitte komplettes Paket hochladen."
  command -v php >/dev/null 2>&1 || fail "PHP-CLI wurde nicht gefunden."
  php "${SCRIPT_DIR}/migrate.php" "${DB_FILE}" "${DATA_DIR}/backups"
  ok "Datenbank bereit: ${DB_FILE}"
}

set_initial_settings(){
  BASE_URL="http://${DOMAIN}"
  sqlite3 "${DB_FILE}" <<SQL
INSERT INTO app_settings (setting_key, setting_value, updated_at)
VALUES ('public_base_url', '${BASE_URL}', datetime('now'))
ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at;
SQL
}

set_permissions(){
  info "Setze Dateirechte ..."
  chown -R www-data:www-data "${APP_ROOT}"
  find "${APP_ROOT}" -type d -exec chmod 755 {} \;
  find "${APP_ROOT}" -type f -exec chmod 644 {} \;
  chmod 775 "${DATA_DIR}"
  chmod 664 "${DB_FILE}"
  mkdir -p "${PUBLIC_DIR}/assets/uploads"
  chmod 775 "${PUBLIC_DIR}/assets/uploads"
  ok "Rechte gesetzt."
}

create_nginx_config(){
  local server_names="${DOMAIN}"
  if [[ "${USE_WWW}" =~ ^[JjYy]$ ]]; then server_names="${DOMAIN} www.${DOMAIN}"; fi
  local nginx_file="/etc/nginx/sites-available/${APP_NAME}"
  info "Erstelle Nginx-Konfiguration ..."
  cat > "${nginx_file}" <<NGINX
server {
    listen 80;
    server_name ${server_names};

    root ${PUBLIC_DIR};
    index index.php index.html;

    client_max_body_size 10M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~* ^/assets/uploads/.*\.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(sqlite|db)$ {
        deny all;
    }
}
NGINX
  ln -sf "${nginx_file}" "/etc/nginx/sites-enabled/${APP_NAME}"
  nginx -t
  systemctl reload nginx
  ok "Nginx ist eingerichtet."
}

offer_ssl(){
  echo
  read -rp "SSL mit Let's Encrypt einrichten? DNS muss bereits auf diesen Server zeigen. [j/N]: " SETUP_SSL
  SETUP_SSL="${SETUP_SSL:-N}"
  [[ "${SETUP_SSL}" =~ ^[JjYy]$ ]] || { warn "SSL uebersprungen. Du kannst es spaeter mit certbot nachholen."; return; }
  apt install -y certbot python3-certbot-nginx
  if [[ "${USE_WWW}" =~ ^[JjYy]$ ]]; then
    certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}"
  else
    certbot --nginx -d "${DOMAIN}"
  fi
  BASE_URL="https://${DOMAIN}"
  sqlite3 "${DB_FILE}" <<SQL
INSERT INTO app_settings (setting_key, setting_value, updated_at)
VALUES ('public_base_url', '${BASE_URL}', datetime('now'))
ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at;
SQL
  ok "SSL wurde eingerichtet."
}

print_summary(){
  echo
  echo "============================================================"
  ok "Fertig."
  echo "============================================================"
  echo "Webroot:       ${PUBLIC_DIR}"
  echo "Datenbank:     ${DB_FILE}"
  echo "Adminseite:    ${BASE_URL}/admin.php"
  echo "Benutzername:  admin"
  echo "Standardpasswort: GetYourOwnWebsite"
  echo
  echo "Wichtig: Bitte direkt nach dem ersten Login das Admin-Passwort aendern."
  echo "Danach im Adminbereich Einstellungen, Schichten und Zugangscodes pruefen."
}

main(){
  need_root
  ask_questions
  install_packages
  detect_php_socket
  configure_php_uploads
  create_directories
  copy_project_files
  create_database
  set_initial_settings
  set_permissions
  create_nginx_config
  offer_ssl
  print_summary
}

main "$@"
