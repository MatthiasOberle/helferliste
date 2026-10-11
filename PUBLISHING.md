# Stabile Version veröffentlichen

Diese Anleitung beschreibt den kontrollierten Release-Weg für das bestehende GitHub-Repository. Eine GitHub-Veröffentlichung aktualisiert keine installierte Helferliste und insbesondere nicht die getrennte Feuerwehr-Installation.

## 1. Ausgangsstand sichern

- Arbeitsverzeichnis und Zweig prüfen.
- Ein vollständiges Git-Bundle des bisherigen Stands außerhalb des Repositorys erstellen und verifizieren.
- Anwendungsversion und Datenbankschema getrennt betrachten: Eine neue Programmversion benötigt nicht automatisch eine neue Schemaversion.

## 2. Release vorbereiten

- `www/version.php` auf die stabile Version ohne `-dev` setzen.
- `CHANGELOG.md`, `README.md` und `ROADMAP.md` auf denselben Stand bringen.
- Keine Datenbank, Uploads, Zugangsdaten oder anderen Produktivdaten in das Paket aufnehmen.
- Drittanbieterhinweise und Lizenzdateien beibehalten.

## 3. Vollständig prüfen

Mindestens ausführen:

```bash
find www -type f -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n Install/install_helferliste.sh
php tests/smoke.php
php tests/migration.php
php tests/restore.php
php tests/event_lifecycle.php
php tests/scheduling.php
php tests/setup_wizard.php
php tests/contact_import.php
php tests/print_lists.php
php tests/invitations.php
bash tests/http_admin_setup.sh
git diff --check
```

Die GitHub-Prüfung muss anschließend unter PHP 8.3, 8.4 und 8.5 erfolgreich sein.

## 4. Über den geschützten Hauptzweig veröffentlichen

- Release-Änderungen menschlich und konkret formulieren.
- Entwicklungszweig zu GitHub pushen.
- Pull Request gegen `main` öffnen.
- Pflichtprüfungen abwarten und erst danach zusammenführen.
- Den veröffentlichten `main` wieder in den Entwicklungszweig übernehmen.

Direkte Pushes, Force-Pushes und Löschungen von `main` bleiben gesperrt.

## 5. Tag, Paket und Prüfsumme erstellen

Der Tag muss exakt auf dem geprüften Release-Commit in `main` liegen:

```bash
git tag -a v1.8.0 <RELEASE-COMMIT> -m "Helferliste 1.8.0"
git push origin v1.8.0
git archive --format=zip --prefix=helferliste-1.8.0/ -o helferliste-1.8.0.zip v1.8.0
shasum -a 256 helferliste-1.8.0.zip > helferliste-1.8.0.zip.sha256
```

Das ZIP aus dem Tag erneut entpacken und mindestens Version, erwartete Dateien, fehlende Produktivdaten, PHP-Syntax, Neuinstallation und Migration prüfen.

## 6. GitHub-Release anlegen

Das Release enthält:

- eine natürliche Zusammenfassung der wichtigsten Neuerungen;
- klare Installations- und Aktualisierungshinweise;
- das geprüfte ZIP-Paket;
- die zugehörige SHA-256-Datei;
- den Hinweis, dass vorhandene Daten und Uploads vor einer Aktualisierung gesichert werden müssen.

Nach der Veröffentlichung Release-Seite, Anlagen, Prüfsumme, Tag-Ziel und abschließende GitHub-Prüfungen erneut kontrollieren.
