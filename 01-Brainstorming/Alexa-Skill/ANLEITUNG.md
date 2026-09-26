# Alexa-Skill „Südsalat“ einrichten

Stand: 26.09.2026. Der Skill selbst ist fertig gebaut und getestet (Backend `03-Backend/alexa/`,
`lib/Alexa/`, Test `tests/AlexaTest.php`). Was fehlt, geht nur mit deinem Amazon-Konto. Alles, was
du einfügen musst, liegt in diesem Ordner.

**Was der Skill kann** („Alexa, öffne Südsalat“):
- „spiel die neueste Folge“ / „spiel Folge zwölf“
- „mach weiter“ – dort, wo du aufgehört hast, auch Tage später und auf einem anderen Echo
- „nächstes Kapitel“ / „voriges Kapitel“ / „welches Kapitel läuft?“
- „was gibt es Neues?“ – nächste Veranstaltung, neuester Film- und Locationtipp, neueste Folge
- Pause, Fortsetzen, „von vorn“; die Tasten Weiter/Zurück springen zwischen Kapiteln
- Nach dem Ende einer Folge läuft die nächste weiter.

---

## Schritt 1: Amazon-Entwicklerkonto (einmalig, kostenlos)

1. https://developer.amazon.com öffnen → **Anmelden** mit deinem normalen Amazon-Konto
   (dem, mit dem auch dein Echo läuft – dann kannst du den Skill sofort selbst testen).
2. Beim ersten Mal fragt Amazon nach Name, Adresse usw. → ausfüllen, Entwicklervereinbarung
   annehmen. Kosten: keine.

## Schritt 2: Skill anlegen

1. Oben **Alexa** → **Alexa Skills Kit** → **Konsole** (developer.amazon.com/alexa/console/ask).
2. **Skill erstellen**:
   - Name: `Südsalat`
   - Primäres Gebietsschema: **Deutsch (DE)**
   - Art: **Andere** → **Benutzerdefiniert** (Custom)
   - Hosting: **Eigenes bereitstellen** (Provision your own)
   - Vorlage: **Von Grund auf neu** (Start from Scratch)

## Schritt 3: Sprachmodell einfügen

1. Links **Interaktionsmodell** → **JSON-Editor**.
2. Den kompletten Inhalt von `interaktionsmodell-de-DE.json` hineinkopieren (alles ersetzen).
3. **Modell speichern**, dann **Modell erstellen** (Build). Dauert ein, zwei Minuten.

## Schritt 4: Audio einschalten

1. Links **Schnittstellen** (Interfaces) → **Audio Player** einschalten.
2. **Schnittstellen speichern**, danach noch einmal **Modell erstellen**.

## Schritt 5: Endpunkt

1. Links **Endpunkt** → **HTTPS**.
2. Standardregion: `https://www.xn--sdsalat-n2a.eu/APP/alexa/`
3. Darunter: **„Mein Entwicklungs-Endpunkt verwendet ein Zertifikat einer vertrauenswürdigen
   Zertifizierungsstelle“**.
4. **Endpunkte speichern**.

## Schritt 6: Skill-ID in den Server eintragen

1. Oben auf der Endpunkt-Seite steht die **Skill-ID** (`amzn1.ask.skill.…`) → kopieren.
2. In der `.env` auf dem Server (Ordner `APP/`) eine Zeile ergänzen:
   `ALEXA_SKILL_ID=amzn1.ask.skill.…`
   Ohne diese Zeile beantwortet der Server keine Anfrage – das ist Absicht.
3. Mir Bescheid geben: Ich lade dann den Skill-Teil des Backends hoch und lege die kleine Tabelle
   für die Hörstellen an (auch vor dem 2.0-Release möglich, unabhängig von der App).
4. Die Datenschutzerklärung bekommt dabei den Absatz „m) Alexa-Skill“ (steht schon im Entwurf
   `datenschutz-2.0.html`; geht der Skill vor 2.0 live, ergänze ich ihn in der jetzigen Fassung).

## Schritt 7: Testen

1. Oben **Test** → Test aktivieren für **Entwicklung** (Development).
2. Links tippen oder sprechen: `öffne südsalat`, dann `spiel die neueste Folge` usw.
   Die Audiowiedergabe selbst spielt der Simulator nicht ab – die Antworten siehst du trotzdem.
3. Am **eigenen Echo** (gleiches Amazon-Konto) funktioniert der Skill jetzt schon:
   „Alexa, öffne Südsalat“. Bitte ausprobieren:
   - neueste Folge, Folge nach Nummer, „mach weiter“ (auch am nächsten Tag)
   - Pause/Fortsetzen, „nächstes Kapitel“, „voriges Kapitel“, „welches Kapitel läuft?“
   - „was gibt es Neues?“
   - „Alexa, stopp“ und danach „Alexa, öffne Südsalat“ → er bietet an, weiterzuhören

## Schritt 8: Veröffentlichen

**Vertrieb** (Distribution) → Store-Vorschau:

| Feld | Eintrag |
|---|---|
| Öffentlicher Name | Südsalat |
| Kurzbeschreibung | Der Podcast Südsalat – Themen aus dem Leben: Folgen hören, weiterhören, Kapitel springen. |
| Ausführliche Beschreibung | siehe unten |
| Beispielsätze | `Alexa, öffne Südsalat` · `Alexa, sag Südsalat, spiel die neueste Folge` · `Alexa, frag Südsalat, was es Neues gibt` |
| Kategorie | Musik & Audio → Podcasts (falls nicht vorhanden: Nachrichten/Unterhaltung) |
| Stichwörter | Podcast, Südsalat, Suedsalat, Alltag, Comedy, Jenny, Thorsten, Weilerswist |
| Kleines Symbol (108 × 108) | `symbol-108.png` |
| Großes Symbol (512 × 512) | `symbol-512.png` |
| Datenschutzerklärung | `https://www.xn--sdsalat-n2a.eu/seiten/datenschutz.html` |

Ausführliche Beschreibung:

> Mit diesem Skill hörst du den Podcast „Südsalat – Themen aus dem Leben“ von Jenny und Thorsten auf
> deinem Echo. Sag „Alexa, öffne Südsalat“ und dann zum Beispiel „spiel die neueste Folge“ oder
> „spiel Folge zwölf“. Hast du eine Folge unterbrochen, macht „mach weiter“ genau dort weiter – auch
> Tage später. Viele Folgen haben Kapitel: Mit „nächstes Kapitel“ springst du zum nächsten Thema,
> „welches Kapitel läuft?“ verrät dir, worüber wir gerade reden. Und „was gibt es Neues?“ liest dir
> die nächste Veranstaltung sowie unseren neuesten Film- und Locationtipp vor. Kostenlos und werbefrei.

**Datenschutz und Compliance:**
- Käufe im Skill: **Nein** · Personenbezogene Daten erfassen: **Nein** (nur Amazons zufällige
  Skill-Kennung, als Prüfwert) · An Kinder unter 13 gerichtet: **Nein** · Werbung: **Nein**
- Exportbestimmungen: **Ja** · Test-Hinweise für Amazon: „Keine Anmeldung nötig. Folgen kommen aus
  unserem RSS-Feed; manche Folgen haben Kapitel (z. B. per ‚nächstes Kapitel‘ testen).“

Dann **Zertifizierung** → **Validierung** (Amazon prüft automatisch) → **Einreichen**.
Die Prüfung dauert meist wenige Tage.

**Falls Amazon den Aufrufnamen ablehnt:** Ein einzelnes Wort ist nur für eigene Marken erlaubt.
Dann im JSON `"invocationName": "südsalat podcast"` eintragen, neu erstellen und erneut einreichen
(Aufruf dann „Alexa, öffne Südsalat Podcast“).

---

## Technik (für mich)

- Endpunkt `APP/alexa/index.php`: prüft jede Anfrage (Zertifikat von Amazon, Signatur-256,
  Zeitstempel ≤ 150 s, Skill-ID) – `lib/Alexa/RequestVerifier.php`. Ohne Prüfung geht es nur in der
  lokalen Testumgebung (`ALEXA_SKIP_SIGNATURE`, wirkt nur mit `MAIL_CAPTURE_DIR`).
- Antworten: `lib/Alexa/Skill.php`. Folgen und Kapitel aus `podcast.rss` (`Feed::parse`).
- Hörstelle: Tabelle `alexa_positions` (HMAC der Alexa-Kennung), 12 Monate nach letzter Nutzung
  gelöscht (`Listener::runMaintenance`, ab 2.0 im Cronjob).
- Vorab live (vor 2.0): `alexa/index.php`, `lib/Alexa/*`, `lib/Feed.php`/`Homepage.php` (schon
  live), `sql/_add-alexa-once.php` ausführen und löschen, `ALEXA_SKILL_ID` in der `.env`,
  Absatz „m) Alexa-Skill“ in die Live-Datenschutzerklärung.
