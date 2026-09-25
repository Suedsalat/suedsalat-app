# Lokale Testumgebung für das Backend

Zum Ausprobieren von Server-Änderungen, ohne den Live-Server oder die echte `.env` zu berühren.
Liegt außerhalb des Repos unter `D:\Suedsalat-Testumgebung\` und nutzt die MariaDB aus XAMPP
(`D:\Programme\xampp`), aber mit **eigenem Datenordner und Port 3307** – das XAMPP selbst bleibt unberührt.

| Was | Wo |
|---|---|
| Datenbank-Daten | `D:\Suedsalat-Testumgebung\db\` (Port 3307, Nutzer `suedsalat` / `suedsalat`, DB `suedsalat_test`) |
| Test-Konfiguration | `D:\Suedsalat-Testumgebung\.env.test` – keine echten Zugangsdaten |
| Abgefangene Mails | `D:\Suedsalat-Testumgebung\mails\` – je Mail eine HTML-Datei, Empfänger und Betreff im Kopf-Kommentar |
| Live-Tabellenstruktur | `D:\Suedsalat-Testumgebung\live-schema.sql` (nur Struktur, keine Daten) |

## Zwei Weichen im Code

- `SUEDSALAT_ENV_FILE` (Umgebungsvariable) → `config.php` liest diese Datei statt `03-Backend/.env`.
  **Live nie gesetzt.**
- `MAIL_CAPTURE_DIR` (in der Test-`.env`) → `Mailer::send()` schreibt Mails als Datei, statt sie zu
  verschicken. **Live nie gesetzt.**

## Starten

```bash
# Datenbank (im Hintergrund laufen lassen)
D:/Programme/xampp/mysql/bin/mysqld.exe --defaults-file="D:\Suedsalat-Testumgebung\db\my.ini" --console

# PHP-Server für API und Admin-Bereich auf http://127.0.0.1:8088
cd D:/Suedsalat-App/03-Backend
SUEDSALAT_ENV_FILE="D:/Suedsalat-Testumgebung/.env.test" php -S 127.0.0.1:8088
```

## Neu aufsetzen

Datenbank leeren und die Live-Struktur neu einspielen:

```bash
M=D:/Programme/xampp/mysql/bin/mysql.exe
$M -h127.0.0.1 -P3307 -uroot -ptestroot -e "DROP DATABASE suedsalat_test; CREATE DATABASE suedsalat_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$M -h127.0.0.1 -P3307 -usuedsalat -psuedsalat suedsalat_test < D:/Suedsalat-Testumgebung/test-schema.sql
```

`test-schema.sql` ist `live-schema.sql` mit `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` (MariaDB kennt
die MySQL-8-Sortierregel nicht) und abgeschalteter Fremdschlüssel-Prüfung beim Einspielen.

## Tests

Bei laufender Datenbank und laufendem PHP-Server (beide oben):

```bash
cd D:/Suedsalat-App/03-Backend
export SUEDSALAT_ENV_FILE="D:/Suedsalat-Testumgebung/.env.test"
for t in ListenerFlowTest PermissionsTest ModerationTest AdminPagesTest StatsConsentTest CommentsTest PruefkontoTest WebLoeschenTest BonusTest; do php tests/$t.php; done
```

Die Tests leeren die betroffenen Tabellen selbst. `AdminPagesTest` meldet sich über eine Sitzungsdatei
im `session.save_path` von PHP als Admin an und prüft am Ende `php-server.log` auf PHP-Warnungen.
