# Mitwirken

Beiträge zur Helferliste sind willkommen.

Die Helferliste wird von **[MOWST — Digitale Werkstatt](https://mowst.de)** entwickelt und gepflegt.

## Fehler melden

Vor einem neuen Issue bitte prüfen, ob der Fehler bereits gemeldet wurde. Eine gute Fehlermeldung enthält:

- eingesetzte Version
- Betriebssystem, Webserver und PHP-Version
- genaue Schritte zum Nachstellen
- erwartetes und tatsächliches Verhalten
- relevante Fehlermeldungen ohne persönliche Daten oder Zugangsdaten

Sicherheitslücken bitte nach [SECURITY.md](SECURITY.md) vertraulich melden.

## Änderungen einreichen

1. Repository forken.
2. Einen eigenen Branch für die Änderung erstellen.
3. Änderung möglichst klein und nachvollziehbar halten.
4. PHP-Dateien mit `php -l` prüfen.
5. Installation und betroffene Bedienabläufe testen.
6. Dokumentation bei verändertem Verhalten aktualisieren.
7. Pull Request mit kurzer Begründung und Testbeschreibung öffnen.

Bitte keine echten E-Mail-Adressen, Namen, Zugangscodes, Passwörter, Datenbanken, Protokolle oder hochgeladenen Betreiberbilder einreichen.

## Stil

- PHP ohne zusätzliche Framework-Abhängigkeit
- verständliche deutsche Oberfläche
- sichere Datenbankzugriffe mit vorbereiteten SQL-Anweisungen
- Ausgaben aus Nutzereingaben HTML-escapen
- zustandsändernde Adminaktionen mit CSRF-Schutz
- neue Funktionen auch auf schmalen Bildschirmen prüfen
