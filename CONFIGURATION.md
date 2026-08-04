# Ersteinrichtung und Konfiguration

Die Git-Version enthält keine persönlichen Betreiber-, Veranstaltungs- oder Kontaktdaten. Diese Checkliste schließt die dadurch bewusst entstandenen Lücken.

Die technische Herstellerangabe verweist auf **[MOWST — Digitale Werkstatt](https://mowst.de)**. Sie ist bewusst von den individuellen Betreiberangaben getrennt und ersetzt kein eigenes Impressum.

## 1. Zugang absichern

Beim ersten Aufruf von `admin.php` den einmaligen Einrichtungscode aus dem Installer verwenden und ein nur hier verwendetes Admin-Passwort mit mindestens 12 Zeichen festlegen. Der Code wird nach erfolgreicher Einrichtung ungültig. Den Adminbereich anschließend nur berechtigten Personen zugänglich machen.

Bei einer neuen Installation öffnet sich anschließend automatisch der Einrichtungsassistent. Er kann jederzeit über `System → Einrichtungsassistent` erneut geöffnet werden. Bestehende Installationen werden nicht zwangsweise umgestellt, sondern erhalten im Adminbereich einen sichtbaren Einstieg.

## 2. Allgemeine Angaben

Unter `System → Einstellungen` ausfüllen:

- App-Name, zum Beispiel `Helferliste`
- Name der Organisation
- Name und Zeitraum der Veranstaltung
- Status `Entwurf`, `Veröffentlicht` oder `Abgeschlossen`
- öffentliche Basis-URL mit `https://`, ohne abschließenden Schrägstrich
- optionaler zusätzlicher Hinweis auf der Anmeldeseite

Die Basis-URL wird für persönliche Einladungslinks benötigt. Bleibt sie leer, versucht die Anwendung die aktuell aufgerufene Adresse zu verwenden.

Neue Veranstaltungen starten nach einem Archivabschluss grundsätzlich als Entwurf. Vor der Veröffentlichung prüft die Anwendung Veranstaltungsname, Organisation, Beginn, Basis-URL, aktive Schichten, Impressum und Datenschutzkontakt. Entwürfe und abgeschlossene Veranstaltungen zeigen öffentlich nur einen konfigurierbaren Hinweis und nehmen keine Codes oder Rückmeldungen an.

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

Die mitgelieferten Schichten sind nur Beispiele und zählen nicht als veröffentlichungsfertige Planung. Der Assistent deaktiviert unbenutzte Beispiele automatisch, sobald die erste konkrete Schicht angelegt wird. Vor dem Echtbetrieb:

1. Beispiele bearbeiten oder löschen.
2. verständlichen Titel vergeben.
3. Datum, Beginn, Ende und Ort in den dafür vorgesehenen Feldern pflegen.
4. optional einen öffentlich sichtbaren Hinweis ergänzen.
5. maximale Personenzahl und Reihenfolge festlegen.
6. wiederkehrende Schichten bei Bedarf duplizieren und anschließend anpassen.
7. Die Namen beider Schichtkategorien lassen sich über die öffentlichen Seitentexte frei an den eigenen Ablauf anpassen.

## 7. Kontakte, Codes und Einladungen

Zugangscodes können einzeln, aus einer eingefügten E-Mail-Liste oder aus einer CSV-Datei erzeugt werden.

Für den CSV-Import:

1. Datei als CSV UTF-8 mit einer Kopfzeile speichern.
2. Die E-Mail-Spalte `E-Mail`, `E-Mail-Adresse`, `Email`, `Emailadresse` oder `Mail` nennen.
3. Datei unter `Verwaltung → Zugangscodes` auswählen und zuerst prüfen lassen.
4. Vorschau kontrollieren. Ungültige, doppelte und bereits vorhandene Adressen werden nicht importiert.
5. Erst danach den Import bestätigen.

Komma, Semikolon und Tabulator werden automatisch erkannt. Weitere Spalten wie Name, Organisation oder Gruppe werden aus Gründen der Datenminimierung nicht gespeichert. Die Datei darf höchstens 1.000 Datenzeilen und 2 MB enthalten. Sie wird nicht dauerhaft auf dem Server abgelegt.

Vor dem Versand:

- Basis-URL prüfen
- einen Testcode erzeugen
- kompletten Ablauf auf Smartphone und Desktop testen
- Zu- und Absage testen
- volle Schicht testen
- Änderungswunsch testen
- Export kontrollieren

## 8. Schichtplan und Anwesenheitslisten drucken

Unter `Auswertung → Drucklisten` stehen zwei Ansichten bereit:

- Der **Schichtplan** fasst alle aktiven normalen und flexiblen Schichten mit Datum, Uhrzeit, Ort, Kapazität und den eingeteilten Namen zusammen.
- Die **Anwesenheitslisten** erzeugen je aktiver Schicht eine eigene Seite. Neben den eingeteilten Namen gibt es Papierfelder für Anwesenheit, Beginn, Ende und Bemerkung sowie bis zu drei freie Zeilen für kurzfristige Ergänzungen.

Mit `Jetzt drucken` öffnet sich die Druckvorschau des Browsers. Dort kann die Ausgabe gedruckt oder als PDF gespeichert werden. Die Drucklisten enthalten bewusst keine E-Mail-Adressen, Zugangscodes oder privaten Hinweise.

Ausdrucke enthalten trotzdem personenbezogene Namen. Nur berechtigte Personen dürfen sie erhalten. Nach dem Einsatz müssen sie entsprechend der festgelegten Aufbewahrung sicher verwahrt oder vernichtet werden.

## 9. Betrieb und Veranstaltungsende

- Datenbank und Uploads regelmäßig sichern.
- Adminzugang nur berechtigten Personen geben.
- Änderungswünsche zeitnah bearbeiten.
- Aufbewahrungsdauer festlegen und in den Datenschutzhinweisen nennen.
- Nach der Veranstaltung unter `System → Veranstaltung abschließen` die angezeigten Summen und offenen Änderungswünsche prüfen.
- Entscheiden, ob das Archiv ausschließlich anonyme Ergebnisse oder vorübergehend auch personenbezogene Daten enthalten darf. Die datensparsame Voreinstellung ist anonym.
- Schichten, Seitentexte und Gestaltung bei Bedarf als Vorlage für die nächste Veranstaltung übernehmen.
- Nicht mehr benötigte personenbezogene Archivdaten kontrolliert exportieren und danach aus dem Archiv löschen.
