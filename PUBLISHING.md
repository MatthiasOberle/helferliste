# Repository auf GitHub veröffentlichen

Der Ordner ist bereits als lokales Git-Repository mit dem Hauptzweig `main` vorbereitet. Die Dateien sind geprüft; für den ersten Commit muss Git nur noch den Namen und die E-Mail-Adresse des veröffentlichenden GitHub-Kontos kennen.

## 1. Git-Absender einmalig einrichten

Falls noch nicht geschehen, die eigenen Angaben einsetzen:

```bash
git config --global user.name "DEIN NAME"
git config --global user.email "DEINE GITHUB-EMAIL"
```

Wer die private E-Mail-Adresse nicht in Commits zeigen möchte, kann die von GitHub bereitgestellte `noreply`-Adresse aus den GitHub-Einstellungen verwenden.

## 2. Ersten Commit erstellen

Im Ordner dieser Veröffentlichung:

```bash
git add .
git commit -m "Helferliste 1.0.0"
```

## 3. Neues Repository anlegen

Auf GitHub ein neues, leeres Repository erstellen, zum Beispiel mit dem Namen `helferliste`.

Beim Anlegen **keine** zusätzliche README, `.gitignore` oder Lizenz erzeugen, weil diese Dateien bereits enthalten sind.

## 4. GitHub-Adresse verbinden

Im Ordner dieser Veröffentlichung:

```bash
git remote add origin https://github.com/DEIN-BENUTZERNAME/helferliste.git
```

Alternativ mit SSH:

```bash
git remote add origin git@github.com:DEIN-BENUTZERNAME/helferliste.git
```

## 5. Veröffentlichen

```bash
git push -u origin main
```

Bei HTTPS kann GitHub statt des Kontopassworts eine Browseranmeldung oder ein Personal Access Token verlangen.

## 6. Empfohlene GitHub-Einstellungen

- unter `About` eine kurze Beschreibung und passende Themen ergänzen
- unter `Security` das private Melden von Schwachstellen aktivieren
- GitHub Actions für die Qualitätsprüfung zulassen
- den Hauptzweig `main` optional schützen
- für stabile Stände Releases und Versions-Tags verwenden

Erster Versions-Tag:

```bash
git tag -a v1.0.0 -m "Helferliste 1.0.0"
git push origin v1.0.0
```

Vor jedem Release die Checkliste in [INSTALL.md](INSTALL.md), [CONFIGURATION.md](CONFIGURATION.md) und [SECURITY.md](SECURITY.md) nochmals prüfen.
