# Design-System — extrahiert aus www.südsalat.eu/style/style.css (Stand: 2026-07-12)

## Farben

**Primär:**
- `#77B538` — Hauptfarbe/Akzent (Buttons, Links, Hover, Header-Hintergrund)
- `#55832e` — Hover-Zustand (dunkleres Grün), z.B. Social-Icons
- `#E2DDBF` — Sekundär-/Card-Hintergrund
- `#102024` — Primäre Textfarbe (dunkel)
- `#fff` — Helle Schrift, Footer-Hintergrund

**Funktional:**
- `rgba(0,0,0,0.15)` — Box-Shadow (hell)
- `rgba(0,0,0,0.1)` — Card-Shadow
- `rgba(15,25,28,0.95)` — Cookie-Banner-Overlay
- `rgba(0,0,0,0.9)` — Lightbox-Hintergrund
- `#f0f0f0` — Audio-Player-Hintergrund

**Dark Mode:**
- `#121212` — Body-Hintergrund
- `#1b1b1b` — Header/Footer
- `#1f1f1f` — Karten
- `#eee` — Text

## Typografie
- Schriftart: `"Open Sans", Arial, sans-serif`
- h1: 2rem / 1.8rem (Header)
- h2: 1.3rem
- Buttons: 1rem
- Kleintext (Datenschutzhinweise): 0.75rem

## Layout & Spacing
- Body-Padding: 20px
- Container-Padding: 30px
- Section-Margin/Padding: 35px auto / 20px
- Grid-Gap: 15px (Standard), 10px (Mobile)
- Border-Radius: 12px (Container), 6px (Inputs/Buttons), 10px (Bilder/Audio-Player)
- Episode-Grid: `repeat(auto-fit, minmax(250px, 1fr))`
- Breakpoint: `@media (max-width: 480px)` — Mobile-Anpassungen
- Transitions: 0.2s Standard, 0.3s ease (Archiv-Interaktionen)

## Ton & Branding
- Lockerer, informeller Ton ("bunte Tüte Alltagswahnsinn")
- Logo: 300x300px auf der Homepage
- Tagline: "Stories, conversations, and thoughts – topics from everyday life! A new episode every week."
- Social Links: Instagram, Facebook, Spotify, Amazon Music, Apple Podcasts

## Homepage-Struktur (Referenz)
- Header mit Logo + Titel
- Episodenliste neueste zuerst, ältere in einklappbarem Archiv
- Newsletter-Anmeldung
- Footer mit Impressum/Datenschutz/Cookie-Einstellungen

## Logo & Branding-Assets
Alle aktuellen Logo-/Branding-Dateien liegen jetzt unter `../05-Assets/` (kopiert aus `U:\Logo`, Stand 2026-07-12):
- `05-Assets/Logo/` — Haupt-Logo (Suedsalat_Logo, in Farbe/Weiß/Transparent, JPG & PNG)
- `05-Assets/Mikro/` — Mikrofon-Icon-Varianten
- `05-Assets/Fonts/` — Franklin Gothic Book & Demi (TTF)
- `05-Assets/Social-Badges/` — Spotify- (PNG/SVG/EPS), Apple- und Instagram-Badges
- `05-Assets/QR-Code/` — QR-Codes zur Homepage inkl. Kostüm-Vorlagen
- `05-Assets/Quelldateien/` — PowerPoint-Ursprungsdateien der Logos

Ältere/verworfene Logo-Entwürfe liegen weiterhin unter `U:\Logo\Alt` (nicht kopiert, da veraltet).

**Schrift-Klärung (2026-07-12):** Franklin Gothic ist ausschließlich für das Logo-Branding bestimmt — also den Schriftzug "SÜDSALAT" und den Claim "Themen aus dem Leben". Für alle übrigen Texte (App-UI, Fließtext, Termine, Beschreibungen etc.) wird eine gut lesbare Standardschrift verwendet — hier bleibt "Open Sans" (wie auf der Website) die naheliegende Wahl.

## To-Do
- [x] Logo-Dateien von Thorsten eingeholt (2026-07-12, aus U:\Logo übernommen)
- [x] Schrift-Frage geklärt: Franklin Gothic nur für Logo/Schriftzug "SÜDSALAT" + Claim "Themen aus dem Leben", sonst Open Sans für UI-Texte
