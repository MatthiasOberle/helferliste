# Helferliste installieren

Diese Anleitung trennt die technische Installation bewusst von der Funktionsbeschreibung in der [README.md](README.md).

Die Helferliste ist ein Open-Source-Projekt von **[MOWST — Digitale Werkstatt](https://mowst.de)**.

## Vor dem Start

Benötigt werden:

- PHP 8.3 oder neuer
- `pdo_sqlite` und `fileinfo`
- SQLite 3
- ein Webserver mit PHP-Unterstützung
- Schreibrechte für die SQLite-Datenbank und den Upload-Ordner

Für öffentlich erreichbare Installationen werden eine eigene Domain, HTTPS und regelmäßige Backups dringend empfohlen.

## Variante A: Ubuntu oder Debian automatisch

Der Installer ist für einen frischen Ubuntu-/Debian-Server mit Nginx gedacht.

1. Dieses Repository herunterladen oder klonen.
2. In den Projektordner wechseln.
3. Installer starten:

```bash
sudo bash Install/install_helferliste.sh
```

Der Installer fragt nach der Domain oder IP. Diese ohne `http://`, `https://` und ohne Pfad eingeben, zum Beispiel `helfer.example.org`.

Das Script:

- installiert Nginx, PHP-FPM, SQLite und benötigte Werkzeuge
- legt `/var/www/helferliste/public` und `/var/www/helferliste/data` an
- kopiert die Webdateien
- erstellt oder migriert die SQLite-Datenbank kontrolliert
- legt vor einer notwendigen Migration automatisch eine konsistente Datenbanksicherung an
- konfiguriert Uploads bis 8 MB
- setzt Dateirechte
- richtet Nginx ein
- bietet optional ein Let's-Encrypt-Zertifikat an

Danach öffnen:

```text
https://DEINE-DOMAIN/admin.php
```

Erster Zugang:

Der Installer zeigt einen zufälligen, einmaligen Einrichtungscode. Beim ersten Aufruf von `admin.php` wird dieser Code eingegeben und ein eigenes Admin-Passwort mit mindestens 12 Zeichen festgelegt. Der Code wird nur als Hash gespeichert und nach erfolgreicher Einrichtung gelöscht. Es gibt kein allgemein bekanntes Standardpasswort mehr.

## Variante B: Linux manuell

### 1. Pakete installieren

Beispiel für Ubuntu/Debian:

```bash
sudo apt update
sudo apt install nginx php-fpm php-cli php-sqlite3 sqlite3 rsync
```

Prüfen:

```bash
php -v
php -m | grep -E 'PDO|pdo_sqlite|fileinfo'
```

### 2. Ordner anlegen und Dateien kopieren

```bash
sudo mkdir -p /var/www/helferliste/public
sudo mkdir -p /var/www/helferliste/data
sudo rsync -a www/ /var/www/helferliste/public/
```

### 3. Datenbank erstellen oder aktualisieren

```bash
sudo php Install/migrate.php \
  /var/www/helferliste/data/helferliste.sqlite \
  /var/www/helferliste/data/backups
```

Das Migrationswerkzeug prüft die vorhandene Schema-Version und die SQLite-Integrität. Nur wenn eine Änderung notwendig ist, wird zuvor unter `data/backups/` eine konsistente Sicherung erstellt. Bereits aktuelle Datenbanken bleiben unverändert.

### 4. Rechte setzen

```bash
sudo chown -R www-data:www-data /var/www/helferliste
sudo find /var/www/helferliste -type d -exec chmod 755 {} \;
sudo find /var/www/helferliste -type f -exec chmod 644 {} \;
sudo chmod 775 /var/www/helferliste/data
sudo chmod 664 /var/www/helferliste/data/helferliste.sqlite
sudo mkdir -p /var/www/helferliste/public/assets/uploads
sudo chmod 775 /var/www/helferliste/public/assets/uploads
```

### 5. PHP-Uploads konfigurieren

In der von PHP-FPM verwendeten `php.ini` oder einer zusätzlichen INI-Datei:

```ini
upload_max_filesize = 8M
post_max_size = 10M
max_file_uploads = 20
```

Danach PHP-FPM neu laden. Den installierten Versionsnamen verwenden, zum Beispiel:

```bash
sudo systemctl reload php8.3-fpm
```

### 6. Nginx einrichten

Beispiel:

```nginx
server {
    listen 80;
    server_name helfer.example.org;

    root /var/www/helferliste/public;
    index index.php index.html;
    client_max_body_size 10M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~* ^/assets/uploads/.*\.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(sqlite|db)$ {
        deny all;
    }
}
```

Den PHP-Socket an die installierte Version anpassen. Anschließend:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Danach HTTPS einrichten, beispielsweise mit Certbot.

## Variante C: Windows Server und IIS

Voraussetzungen:

- PHP 8.3 oder neuer für IIS/FastCGI
- aktivierte Erweiterungen `pdo_sqlite` und `fileinfo`
- IIS mit CGI/FastCGI
- Schreibrechte für den IIS-Benutzer

Die Datei `Install/install_helferliste_windows.bat` als Administrator starten. Das Script kopiert die Dateien, erstellt die Datenbank und setzt grundlegende Rechte.

Danach im IIS-Manager:

1. Website mit dem physischen Pfad `C:\inetpub\helferliste\public` anlegen.
2. Domain/IP als Bindung eintragen.
3. PHP/FastCGI prüfen.
4. Kontrollieren, ob `www/web.config` zum installierten PHP-Pfad passt.
5. HTTPS-Bindung und Zertifikat einrichten.
6. `admin.php` öffnen, einmaligen Einrichtungscode eingeben und eigenes Passwort festlegen.

Falls Bilder über 8 MB oder bereits kleinere Bilder nicht hochgeladen werden können, in der verwendeten `php.ini` mindestens `upload_max_filesize = 8M` und `post_max_size = 10M` setzen und IIS/PHP-FastCGI neu starten.

## Variante D: XAMPP für lokale Tests

Für einen lokalen Test kann der Projektordner beispielsweise nach `C:\xampp\htdocs\helferliste` kopiert werden. Die Windows-Installationshilfe kann als Ziel diesen Ordner verwenden.

Aufruf:

```text
http://localhost/helferliste/public/admin.php
```

Diese Variante ist ohne zusätzliche Härtung nicht als Empfehlung für einen öffentlich erreichbaren Produktivserver gedacht.

## Erster Login und Pflichtkonfiguration

Nach jeder neuen Installation:

1. Einmaligen Einrichtungscode verwenden und eigenes Admin-Passwort festlegen.
2. `System → Einstellungen` öffnen.
3. App-Name, Organisation und Veranstaltung eintragen.
4. öffentliche Basis-URL kontrollieren.
5. Impressum und Datenschutzkontakt vollständig ausfüllen.
6. Kopfbild, Farben, Schriftart und öffentliche Texte prüfen.
7. Beispielschichten löschen oder bearbeiten.
8. echte Schichten anlegen.
9. Zugangscodes erstellen und einen vollständigen Test durchführen.

Details enthält [CONFIGURATION.md](CONFIGURATION.md).

## Bestehende Installation aktualisieren

Vor jeder Aktualisierung sichern:

```text
/var/www/helferliste/data/helferliste.sqlite
/var/www/helferliste/public/assets/uploads/
```

Danach die neue Version bereitstellen und den Linux-Installer erneut ausführen. Er führt ausstehende Datenbankmigrationen in der richtigen Reihenfolge aus und erhält vorhandene PNG-, JPG-, JPEG-, WebP- und GIF-Dateien im Upload-Ordner.

Bei einer manuellen Aktualisierung wird die Datenbank mit `Install/migrate.php` aktualisiert. Der Windows-Installer verwendet dasselbe Werkzeug. Der Upload-Ordner muss weiterhin separat gesichert werden. Nie eine leere Datenbank oder einen leeren Upload-Ordner ungeprüft über die Produktivdaten kopieren.

## Backup und Wiederherstellung

Für ein vollständiges Backup:

1. SQLite-Datei sichern.
2. Ordner `assets/uploads/` sichern.
3. Version der eingesetzten Anwendung notieren.

Vor dem Kopieren einer aktiven SQLite-Datenbank sollte nach Möglichkeit kurz der Schreibzugriff gestoppt oder die SQLite-Backup-Funktion verwendet werden. Zur Wiederherstellung beide Sicherungen an ihre ursprünglichen Orte zurückkopieren und Besitz/Rechte prüfen.

Vor einer automatischen Schemaänderung erzeugte Datenbanksicherungen liegen standardmäßig unter `data/backups/`. Zur Wiederherstellung zuerst den Webzugriff kurz stoppen, die aktuelle Datenbank zusätzlich sichern, die gewünschte Sicherungsdatei als `helferliste.sqlite` einsetzen und anschließend Rechte sowie `PRAGMA quick_check` und `PRAGMA foreign_key_check` prüfen.

## Häufige Probleme

### Weiße Seite oder Fehler 500

- PHP-Fehlerprotokoll prüfen.
- PHP-Version und Erweiterungen prüfen.
- sicherstellen, dass die Datenbank existiert und vom Webserver les- und beschreibbar ist.

### `could not find driver`

Die PHP-Erweiterung `pdo_sqlite` fehlt oder ist für die vom Webserver verwendete PHP-Version nicht aktiviert.

### Datenbank kann nicht geöffnet werden

Der Webserver benötigt Schreibrechte auf die Datenbank und deren übergeordneten Ordner `data/`.

### Bild-Upload schlägt fehl

- `fileinfo` aktivieren
- PHP-Grenzen auf 8 MB beziehungsweise 10 MB setzen
- Webserver-Grenze auf mindestens 10 MB setzen
- Schreibrechte für `www/assets/uploads/` prüfen

### Direktlinks zeigen auf die falsche Adresse

Unter `System → Einstellungen` die öffentliche Basis-URL einschließlich `https://` eintragen.

### Admin-Passwort vergessen

Es gibt absichtlich keinen öffentlichen automatischen Passwort-Reset. Eine berechtigte Person erzeugt direkt auf dem Server einen neuen einmaligen Einrichtungscode:

```bash
sudo php Install/reset_admin.php \
  /var/www/helferliste/data/helferliste.sqlite \
  /var/www/helferliste/data/backups
```

Vor dem Reset wird automatisch eine konsistente Datenbanksicherung erstellt. Das bisherige Passwort und bestehende Admin-Sitzungen werden ungültig. Anschließend wird unter `admin.php` mit dem neuen Einrichtungscode ein neues Passwort gesetzt.
