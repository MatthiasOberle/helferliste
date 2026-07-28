# Ersteinrichtung und Konfiguration

Die Git-Version enthält keine persönlichen Betreiber-, Veranstaltungs- oder Kontaktdaten. Diese Checkliste schließt die dadurch bewusst entstandenen Lücken.

Die technische Herstellerangabe verweist auf **[MOWST — Digitale Werkstatt](https://mowst.de)**. Sie ist bewusst von den individuellen Betreiberangaben getrennt und ersetzt kein eigenes Impressum.

## 1. Zugang absichern

Beim ersten Aufruf von `admin.php` den einmaligen Einrichtungscode aus dem Installer verwenden und ein nur hier verwendetes Admin-Passwort mit mindestens 12 Zeichen festlegen. Der Code wird nach erfolgreicher Einrichtung ungültig. Den Adminbereich anschließend nur berechtigten Personen zugänglich machen.

## 2. Allgemeine Angaben

Unter `System → Einstellungen` ausfüllen:

- App-Name, zum Beispiel `Helferliste`
- Name der Organisation
- Name und Zeitraum der Veranstaltung
- öffentliche Basis-URL mit `https://`, ohne abschließenden Schrägstrich
- optionaler zusätzlicher Hinweis auf der Anmeldeseite

Die Basis-URL wird für persönliche Einladungslinks benötigt. Bleibt sie leer, versucht die Anwendung die aktuell aufgerufene Adresse zu verwenden.

## 3. Rechtliche Angaben

Im Bereich `Impressum & Datenschutz` mindestens ergänzen:

- verantwortliche Person oder Organisation
- vollständige ladungsfähige Anschrift
- Kontakt-E-Mail-Adresse
- optional Telefon und Website
- Name und E-Mail-Adresse des Datenschutzkontakts

Solange Pflichtfelder fehlen, erscheint auf den öffentlichen Rechteseiten ein Einrichtungs-Hinweis. Vor der Einladung echter Helfer müssen diese Angaben geprüft und vervollständigt sein.

Die mitgelieferten Texte beschreiben die technischen Grundfunktionen der Anwendung. Sie sind keine Rechtsberatung und müssen an Betreiber, Hosting, Rechtsgrundlage, Empfängerkreis, Aufbewahrungsdauer und den tatsächlichen Einsatz angepasst werden.

## 4. Erscheinungsbild

Im Bereich `Kopfbild & Galerie`:

- mitgeliefertes Feuerwehr-, THW- oder Notarztmotiv wählen, oder
- eigenes Bild per Drag-and-drop hochladen
- aussagekräftigen Alternativtext hinterlegen

Empfohlen ist ein gut freigestelltes, möglichst quadratisches Bild mit mindestens 800 × 800 Pixeln. Zulässig sind PNG, JPG, WebP und GIF bis 8 MB.

Im Bereich `Design` können Farben und Schriftart ohne Quellcodeänderung angepasst werden. Dabei auf ausreichenden Kontrast und gute Lesbarkeit achten.

## 5. Öffentliche Texte

Im Bereich `Seitentexte` alle Überschriften, Hilfetexte, Feldnamen und Schaltflächen prüfen. Besonders wichtig:

- Code-Erklärung passend zum Einladungsweg
- Formulierung für Zusage und Absage
- Erklärung normaler und flexibler Schichten
- Hinweis zur Verbindlichkeit
- Ablauf für Änderungswünsche
- Text der anonymen Belegungsübersicht

## 6. Schichten

Die mitgelieferten Schichten sind nur Beispiele. Vor dem Echtbetrieb:

1. Beispiele bearbeiten oder löschen.
2. Titel mit Datum und Uhrzeit eindeutig formulieren.
3. maximale Personenzahl festlegen.
4. Reihenfolge prüfen.
5. Springer-Schichten nur anlegen, wenn sie organisatorisch verwendet werden.

## 7. Codes und Einladungen

Zugangscodes können einzeln oder aus einer E-Mail-Liste erzeugt werden. Vor dem Versand:

- Basis-URL prüfen
- einen Testcode erzeugen
- kompletten Ablauf auf Smartphone und Desktop testen
- Zu- und Absage testen
- volle Schicht testen
- Änderungswunsch testen
- Export kontrollieren

## 8. Betrieb und Veranstaltungsende

- Datenbank und Uploads regelmäßig sichern.
- Adminzugang nur berechtigten Personen geben.
- Änderungswünsche zeitnah bearbeiten.
- Aufbewahrungsdauer festlegen und in den Datenschutzhinweisen nennen.
- Nach der Veranstaltung unter `System → Veranstaltung abschließen` die angezeigten Summen und offenen Änderungswünsche prüfen.
- Entscheiden, ob das Archiv ausschließlich anonyme Ergebnisse oder vorübergehend auch personenbezogene Daten enthalten darf. Die datensparsame Voreinstellung ist anonym.
- Schichten, Seitentexte und Gestaltung bei Bedarf als Vorlage für die nächste Veranstaltung übernehmen.
- Nicht mehr benötigte personenbezogene Archivdaten kontrolliert exportieren und danach aus dem Archiv löschen.
