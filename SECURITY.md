# Sicherheit

Die Helferliste wird von **[MOWST — Digitale Werkstatt](https://mowst.de)** gepflegt. Allgemeine Rückfragen erreichen uns unter [hallo@mowst.de](mailto:hallo@mowst.de); vertrauliche Sicherheitsmeldungen bitte ausschließlich über den unten beschriebenen privaten GitHub-Kanal senden.

## Unterstützte Versionen

Sicherheitskorrekturen werden für den jeweils aktuellen Stand des Hauptzweigs und die neueste veröffentlichte Version vorgesehen. Ältere Installationen sollten vor einer Meldung zunächst mit der aktuellen Version verglichen werden.

## Schwachstellen melden

Bitte Sicherheitslücken nicht als öffentliches GitHub-Issue veröffentlichen. Nutze nach Veröffentlichung des Repositories möglichst GitHubs Funktion **Private vulnerability reporting** unter `Security → Advisories → Report a vulnerability`.

Eine Meldung sollte enthalten:

- betroffene Version
- nachvollziehbare Schritte
- mögliche Auswirkungen
- gegebenenfalls einen minimalen Nachweis
- bekannte Gegenmaßnahmen

Keine realen personenbezogenen Daten, Zugangscodes, Passwörter oder vollständigen Datenbanken mitsenden.

## Checkliste für Betreiber

- einmaligen Einrichtungscode nur vertraulich übernehmen und ein eigenes Passwort mit mindestens 12 Zeichen setzen
- Anwendung ausschließlich über HTTPS betreiben
- Adminbereich nicht unnötig öffentlich bewerben
- Betriebssystem, Webserver und PHP aktuell halten
- Datenbank außerhalb des öffentlichen Webroots speichern
- Schreibrechte auf `data/` und `assets/uploads/` beschränken
- Ausführung von Skripten im Upload-Ordner verhindern
- CSV-Kontaktimporte vor dem Bestätigen in der Vorschau kontrollieren und nur notwendige E-Mail-Adressen importieren
- persönliche Direktlinks, Zugangscodes und QR-Codes nur einzeln an die vorgesehene Person geben und nicht öffentlich teilen
- Einladungen und Erinnerungen vor dem Öffnen des eigenen E-Mail-Programms nochmals auf Empfänger und Inhalt prüfen
- gedruckte Schicht- und Anwesenheitslisten nur berechtigten Personen geben und nach dem Einsatz sicher vernichten
- regelmäßige, getestete Backups anlegen
- nur berechtigten Personen Adminzugriff geben
- Impressum, Datenschutz und Aufbewahrungsdauer an den eigenen Einsatz anpassen

Die Anwendung ersetzt keine professionelle Sicherheitsprüfung eines konkreten Serverbetriebs.
