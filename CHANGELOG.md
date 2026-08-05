# Änderungsverlauf

Alle wichtigen Änderungen dieses Projekts werden hier dokumentiert.

## Unveröffentlicht

### Korrigiert

- manuelle Schichtzuordnungen im Adminbereich unter PHP 8.3 ohne Transaktionsfehler speicherbar gemacht

### Geändert

- getrennten Entwicklungszweig für den Ausbau zum universellen Produkt vorbereitet
- Produkt-Roadmap mit klarer Abgrenzung zu SaaS und Mandantenbetrieb ergänzt
- Mindestversion auf PHP 8.3 angehoben
- automatische Prüfmatrix auf PHP 8.3, 8.4 und 8.5 aktualisiert
- Linux- und Windows-Installer auf den gemeinsamen Migrationsmechanismus umgestellt
- allgemein bekanntes Standardpasswort durch einmaligen, zufälligen Einrichtungscode ersetzt
- Mindestlänge für neue Admin-Passwörter auf 12 Zeichen angehoben
- Entwicklungsstand auf `1.7.0-dev` und Datenbankschema 5 angehoben
- Beispielschichten von der Veröffentlichungsbereitschaft ausgeschlossen
- neue Installationen bis zum Abschluss des Einrichtungsassistenten sicher im Entwurf gestartet
- GitHub-Qualitätsprüfung auf `actions/checkout@v7` und damit die aktuelle Aktionslaufzeit umgestellt

### Ergänzt

- geschützte Diensteinteilung mit PDF-Upload, Austausch, Vorschau, Veröffentlichung und Löschung im Adminbereich ergänzt
- Zugriff per persönlichem Code oder E-Mail nur nach bereits gespeicherter Zu- oder Absage ergänzt
- PDF außerhalb des Webordners, erneute Berechtigungsprüfung bei jedem Abruf, zeitlich begrenzte Sitzung und Fehlversuchssperre ergänzt
- automatische Aufhebung der PDF-Veröffentlichung beim Start einer neuen Veranstaltung ergänzt
- Funktions- und HTTP-Tests für Upload-Prüfung, Berechtigung, Sperre, Auslieferung und Widerruf ergänzt

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
- Veranstaltungsbeginn, Veranstaltungsende und die Status Entwurf, Veröffentlicht und Abgeschlossen ergänzt
- öffentliche Rückmeldungen für Entwürfe und abgeschlossene Veranstaltungen zuverlässig gesperrt
- Freigabeprüfung für Veranstaltung, URL, Schichten, Impressum und Datenschutzkontakt ergänzt
- strukturierte Schichtfelder für Datum, Beginn, Ende, Ort, Kapazität und öffentlichen Hinweis ergänzt
- Schichten duplizierbar gemacht und beide Schichtkategorien im Adminbereich frei benennbar dargestellt
- Archiv, Vorlagen, Statistik und Exporte um strukturierte Schichtangaben erweitert
- vierstufigen Einrichtungsassistenten für Veranstaltung, Pflichtangaben, erste Schicht und Freigabe ergänzt
- automatisches Deaktivieren unbenutzter Beispielschichten beim Anlegen der ersten konkreten Schicht ergänzt
- automatisierten Funktions- und HTTP-Test für den vollständigen Einrichtungsassistenten ergänzt
- CSV-Import für bis zu 1.000 Kontakte mit Vorschau, automatischer Trennzeichenerkennung und Dublettenprüfung ergänzt
- atomische Code-Erzeugung für eingefügte und importierte E-Mail-Adressen ergänzt
- automatisierten Test für CSV-Auswertung, Zeichencodierung, Dubletten und Import ergänzt
- druckoptimierten Schichtplan mit Zeiten, Orten, Kapazitäten und eingeteilten Namen ergänzt
- getrennte Anwesenheitsliste je aktiver Schicht mit Papierfeldern für Anwesenheit, Beginn, Ende und Bemerkung ergänzt
- E-Mail-Adressen, Zugangscodes und private Hinweise aus den Drucklisten ausgeschlossen
- automatisierte Funktions- und HTTP-Tests für Druckdaten, Datenschutzgrenzen und Druckansichten ergänzt

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
