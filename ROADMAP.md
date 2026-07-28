# Produkt-Roadmap

Diese Roadmap beschreibt den Ausbau der Helferliste von einer bewährten Einzellösung zu einem universell einsetzbaren, selbst gehosteten Produkt.

## Produktkern

Die Helferliste bleibt bewusst schlank:

- selbst gehostet und ohne Cloud-Zwang
- ein Betreiber pro Installation
- keine Benutzerkonten oder App für Helferinnen und Helfer
- PHP und SQLite ohne Framework, Composer oder Node.js
- mobil bedienbar und ohne Programmierkenntnisse konfigurierbar
- Datenschutz und Datensparsamkeit als Voreinstellung

Die erste Ausbaustufe wird nicht zu einem zentralen SaaS, einem Mandantensystem oder einer Vereinsverwaltung erweitert. Solche Funktionen werden erst aufgenommen, wenn ein konkreter Bedarf den zusätzlichen Betriebs- und Sicherheitsaufwand rechtfertigt.

## Stufe 0: Belastbare Entwicklungsbasis

Ziel: Neue Funktionen lassen sich sicher entwickeln, aktualisieren und testen.

- getrennte Entwicklungsstrecke ohne Verbindung zu Produktivdaten
- PHP 8.3 bis 8.5 als geprüfte Laufzeitbasis
- reproduzierbare Tests für Installation, Datenbankschema und Kernabläufe
- eindeutige Anwendungsversion und kontrollierte Datenbankmigrationen
- dokumentierter Backup- und Wiederherstellungstest
- Sicherheitsprüfung von Anmeldung, Sitzungen, Formularen, Uploads und Direktlinks

## Stufe 1: Veranstaltung wiederverwenden

Ziel: Ein Betreiber kann aufeinanderfolgende Veranstaltungen ohne technische Hilfe verwalten.

- Veranstaltung mit Name, Zeitraum und Status verwalten
- abgeschlossene Veranstaltung mit ihren Ergebnissen archivieren
- neue Veranstaltung aus einer vorhandenen Vorlage erstellen
- Schichten, Texte und Gestaltung wahlweise übernehmen
- personenbezogene Daten kontrolliert exportieren und löschen
- klare Vorschau vor Zurücksetzen oder Archivieren

Zunächst gibt es eine aktive Veranstaltung je Installation. Mehrere gleichzeitig aktive Veranstaltungen sind eine spätere, gesonderte Architekturentscheidung.

## Stufe 2: Flexible Planung und einfache Einrichtung

Ziel: Vereine und Organisationen mit unterschiedlichen Abläufen können die Anwendung selbst einrichten.

- geführte Ersteinrichtung mit sicherem Admin-Passwort
- frei benennbare Schichtarten statt fest eingebauter Fachbegriffe
- Datum, Beginn, Ende, Ort, Kapazität und Hinweise je Schicht
- Schichten duplizieren und gesammelt sortieren
- Import von Kontakten aus CSV
- verständliche Prüfung auf fehlende Pflichtangaben vor Veröffentlichung
- Druckansicht für Schichtpläne und Anwesenheitslisten

## Stufe 3: Einladen und erinnern

Ziel: Einladungen lassen sich schnell verteilen, ohne einen bestimmten E-Mail-Anbieter vorzuschreiben.

- QR-Code und Direktlink pro Einladung
- kopierfertige Einladungs- und Erinnerungstexte
- Filter für Personen ohne Rückmeldung
- optionaler SMTP-Versand mit klarer Zustell- und Fehleranzeige
- kein Versand und kein Tracking ohne ausdrückliche Einrichtung des Betreibers

## Stufe 4: Optionale Erweiterungen

Diese Punkte werden erst nach Rückmeldungen aus realen Einsätzen priorisiert:

- mehrere gleichzeitig aktive Veranstaltungen
- mehrere Admin-Konten und Rollen
- echter XLSX-Export
- konfigurierbare Benachrichtigungen
- Programmierschnittstelle für andere Systeme

## Verbindliche Qualitätsziele

Jede Ausbaustufe muss:

- bestehende Installationen ohne Datenverlust aktualisieren können
- Datenbank und Uploads vor einer Migration sichern
- mit erfundenen Testdaten geprüft werden
- öffentliche Seite, Adminbereich, Exporte und mobile Bedienung abdecken
- die jeweils unterstützten PHP-Versionen automatisch prüfen
- WCAG 2.2 als technischen Zielstandard berücksichtigen
- Sicherheitsanforderungen an OWASP ASVS 5.0 ausrichten
- Datenminimierung, Löschbarkeit und sichere Voreinstellungen erhalten

## Nächstes Arbeitspaket

Abgeschlossen:

- Testmatrix auf PHP 8.3, 8.4 und 8.5 umgestellt
- lokalen Installations-Smoke-Test automatisiert
- Versions- und Migrationsmechanismus mit Sicherung und Datenerhalt eingeführt

Als Nächstes:

1. Admin-Ersteinrichtung ohne allgemein bekanntes Standardpasswort entwickeln.
2. Backup-Wiederherstellung und zentrale Kernabläufe weiter automatisieren.
3. Danach den sicheren Veranstaltungsabschluss mit Archiv und Vorlage umsetzen.
