# Südsalat App — Konzept (Brainstorming-Stand: 2026-07-12)

## Ausgangslage
- Thorsten betreibt zusammen mit Jenny den Podcast "Südsalat".
- Homepage: https://www.südsalat.eu (selbst gehostet über Strato)
- RSS-Feed: https://www.südsalat.eu/podcast.rss (einzige Schnittstelle zu Spotify, Amazon Music, Apple Podcasts etc.)
- Ziel: Eine eigene App als Ergänzung zur Homepage, mit Folgen, Galerie und Terminübersicht.

## Entscheidungen

### Plattform & Technik
- **Native App** für iOS & Android (kein reines Web/PWA)
- **Framework: Flutter** (ein Dart-Codebase für beide Plattformen)
- Erstmal **nur intern testen** (kein Apple/Google Developer Account vorhanden) — Store-Release ist eine spätere Phase

### Backend & Hosting
- Läuft auf **Thorstens eigenem Strato-Hosting** (unterstützt PHP & MySQL)
- Eigene kleine **REST-API** (PHP), die eine MySQL-Datenbank abfragt
- Kein Drittanbieter-Cloud-Backend (Firebase o.ä.) — passt zur Selbst-Hosting-Präferenz

### Funktionsumfang

**Folgen (Episodes)**
- Automatischer Sync aus dem RSS-Feed (`podcast.rss`)
- Neueste Folge oben, wie auf der Homepage
- **Workflow-Klarstellung (2026-07-12):** Thorstens bisheriger Ablauf ändert sich nicht — er schneidet die Folge, lädt sie hoch und aktualisiert Homepage & RSS-Datei wie gewohnt. Für die App ist danach kein zusätzlicher manueller Schritt nötig; der Cronjob liest den Feed automatisch aus und erkennt neue Folgen (dafür ist der Cache/Abgleich in `episodes_cache` da). Manuelle Pflege im Admin-Bereich gibt es **nur** für Galerie-Fotos und Termine, nicht für Folgen.

**Termine / Veranstaltungen**
- Einfache Liste, **nächster Termin oben** (keine Kalender-Monatsansicht)
- Felder: Titel, Datum (+ optional Uhrzeit), Beschreibung, Link (optional), Bild (optional)

**Galerie**
- Einfacher Feed, **neuestes Foto oben**, ältere darunter
- Je Foto: Veröffentlichungsdatum + kurze Beschreibung (von Admins gepflegt)
- Keine Alben/Kategorien — bewusst simpel gehalten

**Admin-Bereich**
- Eigene kleine Web-Oberfläche (auf Strato) zum Hochladen von Fotos und Eintragen von Terminen
- **Zwei getrennte Logins** (einer für Thorsten, einer für Jenny)
- Änderungen landen in der MySQL-Datenbank → App zieht sich die Daten automatisch
- **Sichere Auth-Variante** (Entscheidung 2026-07-12), statt einfachem Passwort-Login:
  - Passwörter sicher gehasht gespeichert (PHP `password_hash`/`password_verify`, bcrypt/Argon2)
  - Registrierung/Erstanmeldung mit **E-Mail-Bestätigung** (Bestätigungslink)
  - **Passwort-Reset per E-Mail** (zeitlich begrenzter Reset-Token/Link)
  - Login-Versuche werden begrenzt (Rate-Limiting/Lockout gegen Brute-Force)
  - Sitzungen über sichere Session-Tokens, erzwungenes HTTPS
  - **2FA: Ja** — zusätzlich TOTP (Authenticator-App wie Google/Microsoft Authenticator) beim Login erforderlich
  - **Absender-Mail: neue eigene Adresse** (z. B. `noreply@südsalat.eu`), getrennt vom persönlichen Postfach — muss bei Strato noch angelegt werden

**Push-Benachrichtigungen**
- Bei neuer Podcast-Folge
- Bei neuem Termin/Event

## Design
→ siehe `../02-Design/Design-System.md` für die aus der Homepage extrahierten Farben, Schriften und Layout-Werte. Look & Feel der App soll sich an der bestehenden Homepage orientieren (gleiche Palette, Open Sans, abgerundete Ecken, Dark-Mode-Unterstützung).

## Offene Punkte (Stand 2026-07-12)
- [x] Logo-Dateien von Thorsten erhalten (siehe `02-Design/Design-System.md`)
- [x] Admin-Auth-Mechanismus festgelegt: sichere Variante mit E-Mail-Bestätigung, Passwort-Reset & TOTP-2FA (siehe oben)
- [x] Neue E-Mail-Adresse bei Strato eingerichtet (2026-07-12) — SMTP-Zugangsdaten trägt Thorsten selbst in `03-Backend\.env` ein (Vorlage: `03-Backend\.env.example`), niemals im Chat oder in Git
- [ ] Timeline / Budget
- [ ] Datenbank-Schema entwerfen (Termine, Fotos, Admin-User)
- [ ] API-Endpunkte definieren
- [ ] App-Screens/Wireframes
- [ ] Details zu Apple/Google Developer Accounts für spätere Store-Veröffentlichung

## Nächster Schritt
Detaillierter technischer Plan (Projektstruktur, DB-Schema, API-Endpunkte, App-Screens) zur Freigabe durch Thorsten, bevor die eigentliche Programmierung beginnt.
