# Änderungsverlauf

Alle wichtigen Änderungen dieses Projekts werden hier dokumentiert.

## Unveröffentlicht

### Geändert

- getrennten Entwicklungszweig für den Ausbau zum universellen Produkt vorbereitet
- Produkt-Roadmap mit klarer Abgrenzung zu SaaS und Mandantenbetrieb ergänzt
- Mindestversion auf PHP 8.3 angehoben
- automatische Prüfmatrix auf PHP 8.3, 8.4 und 8.5 aktualisiert
- Linux- und Windows-Installer auf den gemeinsamen Migrationsmechanismus umgestellt
- allgemein bekanntes Standardpasswort durch einmaligen, zufälligen Einrichtungscode ersetzt
- Mindestlänge für neue Admin-Passwörter auf 12 Zeichen angehoben

### Ergänzt

- wiederholbaren Smoke-Test für Neuinstallation, Datenbankschema, Fremdschlüssel und Einstellungen ergänzt
- Anwendungsversion und fortlaufende Datenbank-Schema-Version eingeführt; Entwicklungsstand auf `1.2.0-dev` angehoben
- konsistente SQLite-Sicherung vor notwendigen Migrationen ergänzt
- Aktualisierungstest mit Erhalt vorhandener Zugangscodes, Rückmeldungen und Einstellungen ergänzt
- sicheren serverseitigen Admin-Reset mit Vorab-Sicherung und neuem Einrichtungscode ergänzt
- Integrationstest für Ersteinrichtung, Anmeldung, Reset und Entzug bestehender Sitzungen ergänzt
- geprüftes Wiederherstellungswerkzeug mit automatischer Sicherung des zuvor aktiven Stands ergänzt
- sicheren Veranstaltungsabschluss mit Vorab-Sicherung, Ergebnisarchiv und direktem Start der nächsten Veranstaltung ergänzt
- anonyme Archivierung als Voreinstellung sowie optionalen Export und kontrollierte Löschung personenbezogener Archivdaten ergänzt
- wiederverwendbare Archivvorlagen für Schichten, Texte, Gestaltung und Einstellungen ergänzt
- automatisierte Tests für Wiederherstellung, Archiv, Vorlagen und Datenschutz ergänzt

## 1.0.1 – 2026-07-27

### Geändert

- MOWST als Entwickler und Herausgeber der Helferliste ergänzt
- sichtbare Trennung zwischen Veranstalter, Betreiber und technischer Umsetzung geschaffen
- Copyright, Projektbeschreibung, Rechtstext-Hinweise und öffentliche Fußzeile auf MOWST ausgerichtet
- Kontakt und Projektwebsite mit `hallo@mowst.de` und `mowst.de` ergänzt

## 1.0.0 – 2026-07-24

Erste für eine öffentliche Git-Veröffentlichung vorbereitete Version.

### Enthalten

- aussagekräftige GitHub-Screenshots mit ausschließlich frei erfundenen Testdaten
- öffentliche Rückmeldung per Code oder Direktlink
- normale und flexible Schichten mit Kapazitätsprüfung
- Adminverwaltung für Rückmeldungen, Schichten, Codes und Änderungswünsche
- TSV-Exporte und zusammengefasste Statistiken
- vollständig anpassbare öffentliche Seitentexte und Gestaltung
- Kopfbild-Galerie mit Feuerwehr-, THW- und Notarztmotiv
- eigener Bild-Upload per Drag-and-drop
- neutrale Startwerte ohne persönliche Betreiberangaben
- verständliche Hinweise bei noch fehlendem Impressum oder Datenschutzkontakt
- getrennte Funktions-, Installations- und Konfigurationsdokumentation
- Installationshilfen für Linux und Windows
- automatische Syntax- und Schemaprüfung über GitHub Actions
- MIT-Lizenz
