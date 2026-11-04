# GBA-Webseite – Anleitung

## Ordner

| Ordner / Datei | Inhalt |
| --- | --- |
| `site/` | Die fertige Webseite. **Dieser Ordner kommt auf den Webserver.** |
| `src/pages/` | Inhalte der einzelnen Seiten |
| `src/styles.css` | Design (Farben, Schriften, Abstände) |
| `src/php/` | Backend: Admin-Bereich, Datenbank-Funktionen |
| `src/img/`, `src/docs/` | Fotos und Formulare (PDF) |
| `build.py` | Setzt aus `src/` den Ordner `site/` zusammen |
| `passwort-setzen.php` | Legt den Admin-Zugang fest |
| `build/preview/` | Statische Vorschau ohne Backend (nur für den Claude-Link) |

Nach jeder Änderung in `src/`:

```bash
python3 build.py
```

## Einmalig: Admin-Zugang festlegen

```bash
php passwort-setzen.php
```

Benutzername und ein Passwort mit mindestens 12 Zeichen eingeben. Das Passwort wird nur verschlüsselt gespeichert.

## Hochladen auf das Webhosting

Voraussetzung: PHP 8.1 oder neuer mit SQLite (bei IONOS, Strato, all-inkl. Standard).

1. `python3 build.py` ausführen.
2. Den **Inhalt** von `site/` per FTP/SFTP in das Hauptverzeichnis der Domain hochladen.
3. Die Ordner `data/` und `uploads/` müssen für PHP beschreibbar sein (meist automatisch, sonst Rechte 755).
4. `https://gbaboxing.de/admin/` aufrufen und anmelden.

Beim ersten Aufruf legt die Seite die Datenbank an und übernimmt die drei bisherigen Mitteilungen.

**Bei späteren Updates `data/` und `uploads/` nicht überschreiben.** Dort liegen die Mitteilungen und Anhänge aus dem Admin-Bereich.

### Sicherheit auf dem Server prüfen

Nach dem Hochladen müssen diese Adressen **gesperrt** sein (Fehler 403 oder 404):

- `https://gbaboxing.de/data/gba.sqlite`
- `https://gbaboxing.de/lib/zugang.php` (darf keinen Inhalt anzeigen)

Die Sperren stehen in `.htaccess`-Dateien und funktionieren auf Apache-Servern (Standard bei den genannten Anbietern). Läuft das Hosting mit nginx, muss der Anbieter `data/` und `lib/` sperren.

Die Seite sollte nur über **https** erreichbar sein, sonst wird das Passwort unverschlüsselt übertragen.

## Mitteilungen verwalten

`https://gbaboxing.de/admin/` → anmelden → **Neuer Beitrag**.

| Feld | Hinweis |
| --- | --- |
| Titel, Datum, Text | Pflicht. Neueste Mitteilung (nach Datum) steht oben. |
| Etikett | „Neu“ wird rot hervorgehoben, alle anderen golden. |
| Rubrik | Kleine Zeile über dem Titel, z. B. „boxrec.com · Kostenumlage“. |
| Unterschrift | Z. B. „Der GBA-Vorstand · Jörg Milcke“. |
| PDF-Anhang | Max. 10 MB, mit eigenem Linktext. |
| Foto | JPG, PNG oder WebP, max. 8 MB. Beschreibung ist Pflicht. |

Textformat:

```
Leerzeile = neuer Absatz.

- Zeilen mit Bindestrich und Leerzeichen
- werden zur Aufzählung

**fett** wird fett.
```

Die zwei neuesten Mitteilungen erscheinen automatisch auf der Startseite, die neueste zusätzlich im Schnellzugriff.

Falls der Hoster kleinere Upload-Grenzen hat (z. B. 2 MB), in den PHP-Einstellungen des Hostings `upload_max_filesize` und `post_max_size` auf mindestens 20 MB setzen.

## Lokal testen

```bash
python3 build.py
php -S localhost:8000 -t site
```

Dann http://localhost:8000 öffnen. Die lokale Datenbank liegt in `site/data/` und wird nicht hochgeladen.

## Admin-Bereich auf GitHub Pages (ohne Server)

Auf GitHub Pages läuft kein PHP. Dort gibt es deshalb einen zweiten Admin-Bereich unter `/admin/`, der nur im Browser arbeitet:

- Anmeldung mit **einem Passwort**.
- Beiträge stehen in `src/data/beitraege.json`, PDFs und Fotos in `src/uploads/`.
- Beim Speichern schreibt die Admin-Seite die Änderung als Commit ins GitHub-Repo. GitHub Pages baut die Seite danach neu: **nach 1–2 Minuten sichtbar**.

### Einmalig einrichten

1. Auf GitHub einen Zugangsschlüssel erstellen: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**
   - Repository access: **Only select repositories** → dieses Repo (für beide Fassungen: `gba` und `gba-hell`)
   - Permissions → Repository permissions → **Contents: Read and write**
   - Ablaufdatum nach Wunsch (danach neu einrichten)
2. Im Projektordner ausführen und Schlüssel sowie Admin-Passwort eingeben:
   ```bash
   php admin-einrichten.php
   ```
3. Veröffentlichen:
   ```bash
   git add src/static-admin/zugang.json && git commit -m "Admin-Zugang eingerichtet" && git push
   ```

Danach: `https://www.mikailoeztuerk.de/gba/admin/` aufrufen und mit dem Passwort anmelden.

### Sicherheit

- Der GitHub-Schlüssel liegt nur **verschlüsselt** im Repo (AES-256, Schlüssel aus dem Passwort abgeleitet mit 600.000 PBKDF2-Runden). Ohne Passwort ist er nutzlos.
- Weil die verschlüsselte Datei öffentlich ist, kann jemand das Passwort offline durchprobieren. Darum ein **langes Passwort** wählen (z. B. vier zufällige Wörter).
- Der Schlüssel darf nur die beiden GBA-Repos ändern. Im schlimmsten Fall könnte jemand mit dem Passwort diese Webseiten verändern, nichts anderes.
- Passwort oder Schlüssel ändern: `php admin-einrichten.php` erneut ausführen, committen und pushen. Den alten Schlüssel auf GitHub löschen.

Wenn du lokal Änderungen machst, vorher `git pull` ausführen, damit du die Beiträge aus dem Admin-Bereich hast.
