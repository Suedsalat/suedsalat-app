# Südsalat App — Technischer Plan (Stand: 2026-07-12)

Aufbauend auf `Konzept.md`. Bitte einmal durchlesen und freigeben, bevor die eigentliche Programmierung startet.

## 1. Gesamtarchitektur

```
┌─────────────────────┐      HTTPS/JSON       ┌──────────────────────────┐
│   Flutter App        │ ───────────────────▶ │  Öffentliche Lese-API     │
│  (iOS & Android)      │ ◀─────────────────── │  /api/*.php (Strato)      │
└─────────────────────┘                        └────────────┬─────────────┘
                                                              │ liest
                                                              ▼
                                                     ┌──────────────────┐
                                                     │  MySQL-Datenbank  │
                                                     └────────┬─────────┘
                                                              │ schreibt
┌─────────────────────┐      HTTPS (Browser)   ┌────────────┴─────────────┐
│  Thorsten & Jenny     │ ───────────────────▶ │  Admin-Bereich /admin/*    │
│  (Browser, Login)      │ ◀─────────────────── │  klassische PHP-Webapp     │
└─────────────────────┘                        └──────────────────────────┘
```

- **App** ruft nur lesende, öffentliche API-Endpunkte auf (kein Login nötig für Hörer).
- **Admin-Bereich** ist eine eigene, separate PHP-Webanwendung mit klassischem Login (Session-Cookie), **kein Bestandteil der App** — Thorsten & Jenny pflegen Inhalte im Browser.
- Beide greifen auf dieselbe MySQL-Datenbank zu.

**Wichtiger Hinweis zu Push-Benachrichtigungen:** Diese laufen technisch zwangsläufig über Apple (APNs) und Google (Firebase Cloud Messaging) — das lässt sich nicht selbst hosten, das ist bei jeder App so. Firebase wird hier **nur als reiner Zustellweg** für Push-Nachrichten genutzt, es werden keine eigenen Daten (Fotos, Termine, Folgen) dort gespeichert — die bleiben komplett auf deinem Strato-Server. Ein kostenloses Firebase-Projekt ist dafür nötig (Google-Konto reicht).

## 2. Datenbank-Schema (MySQL)

```sql
-- Admin-Konten (nur Thorsten & Jenny — kein offenes Registrierungsformular!)
CREATE TABLE admins (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(64) NULL,
  totp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  email_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Passwort-Reset-Tokens (zeitlich begrenzt, einmalig verwendbar)
CREATE TABLE password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  admin_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  FOREIGN KEY (admin_id) REFERENCES admins(id)
);

-- Login-Versuche (für Rate-Limiting/Lockout)
CREATE TABLE login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  admin_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  succeeded BOOLEAN NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Termine/Veranstaltungen
CREATE TABLE events (
  id INT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  event_date DATE NOT NULL,
  event_time TIME NULL,
  description TEXT NULL,
  link VARCHAR(500) NULL,
  image_path VARCHAR(500) NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id)
);

-- Galerie-Fotos
CREATE TABLE photos (
  id INT PRIMARY KEY AUTO_INCREMENT,
  image_path VARCHAR(500) NOT NULL,
  description TEXT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id)
);

-- Zwischenspeicher für RSS-Folgen (Performance, kein wiederholtes Live-Parsen bei jedem App-Aufruf)
CREATE TABLE episodes_cache (
  id INT PRIMARY KEY AUTO_INCREMENT,
  guid VARCHAR(255) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  audio_url VARCHAR(500) NOT NULL,
  image_url VARCHAR(500) NULL,
  duration VARCHAR(20) NULL,
  pub_date DATETIME NOT NULL,
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Geräte-Tokens für Push-Benachrichtigungen
CREATE TABLE push_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  device_token VARCHAR(255) NOT NULL UNIQUE,
  platform ENUM('ios','android') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

## 3. Öffentliche Lese-API (für die App, kein Login nötig)

| Methode | Endpunkt | Beschreibung |
|---|---|---|
| GET | `/api/episodes.php` | Folgen aus `episodes_cache`, neueste zuerst |
| GET | `/api/events.php` | Termine, nächster zuerst (nach `event_date`/`event_time` sortiert) |
| GET | `/api/gallery.php` | Fotos, neuestes zuerst |
| POST | `/api/register-push-token.php` | Speichert/aktualisiert ein Geräte-Token für Push |

Ein Cronjob auf Strato (z. B. alle 15 Min.) liest den RSS-Feed neu ein, aktualisiert `episodes_cache` und löst bei neuen Folgen eine Push-Benachrichtigung aus.

## 4. Admin-Bereich (`/admin/`, Login per Browser)

| Seite | Beschreibung |
|---|---|
| `/admin/login.php` | E-Mail + Passwort, danach TOTP-Code |
| `/admin/forgot-password.php` | Reset-Mail anfordern |
| `/admin/reset-password.php?token=…` | Neues Passwort setzen |
| `/admin/2fa-setup.php` | TOTP einrichten (QR-Code für Authenticator-App) |
| `/admin/dashboard.php` | Übersicht |
| `/admin/events.php` | Termine anlegen/bearbeiten/löschen |
| `/admin/gallery.php` | Fotos hochladen/beschriften/löschen |

**Wichtig:** Da es nur zwei Admins (Thorsten & Jenny) gibt, werden die Konten **einmalig von mir angelegt** (Name + E-Mail), nicht über ein öffentlich erreichbares Registrierungsformular — sonst wäre das ein Sicherheitsrisiko. Ihr bekommt beim ersten Login jeweils einen Bestätigungslink zum Setzen des eigenen Passworts + TOTP-Einrichtung.

## 5. Flutter-App — Screens

1. **Start/Splash** — Logo, danach direkt zur Hauptübersicht
2. **Bottom-Navigation mit 3 Tabs:**
   - **Folgen** — Liste (neueste oben), Tippen öffnet Folgen-Detail mit Player
   - **Termine** — Liste (nächster oben), Tippen öffnet Termin-Detail
   - **Galerie** — Foto-Feed (neuestes oben), Tippen öffnet Vollbild/Lightbox
3. **Einstellungen** (z. B. über Icon oben rechts) — Push-Benachrichtigungen an/aus, Link zur Homepage/Impressum
4. Farbschema & Dark Mode automatisch nach System-Einstellung, Palette & Schrift wie in `Design-System.md`

## 6. Offene Punkte vor Baubeginn

- [x] Freigabe dieses Plans durch Thorsten (2026-07-12)
- [ ] Freies Firebase-Projekt für Push-Zustellung anlegen (Google-Konto)
- [ ] Timeline/Budget
- [ ] Zugang zu Strato (FTP/SSH + phpMyAdmin) für die Einrichtung der Datenbank
