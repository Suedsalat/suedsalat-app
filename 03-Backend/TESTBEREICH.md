# Testbereich auf Strato

Eigene Kopie des Backends unter **https://www.xn--sdsalat-n2a.eu/APP-test/** (Ordner `APP-test/`
neben `APP/`), mit eigener Datenbank `dbs16162719`. Hier wird 2.0 auf echten Handys getestet,
bevor irgendetwas live geht. Eingerichtet am 25.09.2026.

Nicht zu verwechseln mit der **lokalen** Testumgebung (siehe `TESTUMGEBUNG.md`) - die ist für die
automatischen Tests auf dem PC.

## Was getrennt ist, was geteilt

| | Testbereich | geteilt mit Live |
|---|---|---|
| Datenbank | eigene (`dbs16162719`) | – |
| Hochgeladene Bilder | eigener Ordner `APP-test/uploads/` | Bilder aus der Kopie zeigen auf `APP/uploads/` (nur lesend – löschen im Test-Admin erreicht sie nicht, weil ihre Adresse nicht zum Testbereich gehört) |
| App-Schlüssel, JWT, Cron | eigene | – |
| Mails | Betreff beginnt mit **[TEST]** | derselbe Mail-Versand |
| Push | nur an Geräte, die sich im Testbereich angemeldet haben | derselbe Firebase-Zugang |
| Admin-Konten | Kopie von Live (gleiche Anmeldung, Stand 25.09.2026) | – |
| Newsletter-Anmeldung in der Test-App | – | **geht an den echten Newsletter** |

Kopiert wurden Folgen, Veranstaltungen, Tipps, Fotos und Rezensionen. Von Nachrichten nur Name und
Art (ohne Text), von Geräten nur Platzhalter – keine E-Mail-Adressen, keine Newsletter-Daten.
Danach liefen die Release-Migrationen (`_add-tip-submitter-name-once.php`, dann
`_migrate-2-0-once.php`) genau wie beim späteren Release.

## Test-App bauen

Die App spricht normalerweise mit dem Live-Server. Für den Testbereich wird sie mit zwei Schaltern
gebaut und zeigt dann oben links ein rotes **TEST**-Band:

```bash
cd D:/Suedsalat-App/04-App
flutter build apk --release \
  --dart-define=API_BASE_URL=https://www.xn--sdsalat-n2a.eu/APP-test/api \
  --dart-define=APP_SECRET=$(tr -d '\r\n' < U:/App-Programmierung/Testbereich-App-Secret.txt)
```

Die APK kommt nach `U:\App-Programmierung\` (nie auf den Webserver). Sie hat dieselbe App-Kennung wie
die Store-App, ist aber anders signiert: **vorher die Store-App deinstallieren** (lokale Daten wie
Hörpositionen gehen dabei verloren), danach für den Normalbetrieb wieder aus dem Store installieren.

## Code aktualisieren

Das Hilfsskript liegt im Scratchpad der Sitzung (`deploy/testbereich.js`) und schreibt
ausschließlich unter `APP-test/`:

```bash
node testbereich.js code     # Backend-Code + vendor hochladen (ohne tests/, alte sql-Skripte)
```

Neue Migrationen (z. B. spätere 2.0-Etappen) einzeln hochladen, im Testbereich aufrufen und
wieder löschen – so, wie es beim Release geplant ist.

## Neu aufsetzen

In phpMyAdmin bei Strato alle Tabellen von `dbs16162719` löschen, `APP-test/.env` löschen, dann
`zugang`, `schema` und `sql/_testbereich-einrichten-once.php` erneut hochladen und das Skript
aufrufen (`?secret=` steht im Skript). Es läuft nur im Ordner `APP-test` und fasst die
Live-Datenbank nur lesend an.
