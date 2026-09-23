# Testplan für das gesammelte Release

Stand: 2026-09-23 · Letzte ausgelieferte Testfassung: **1.3.6+17** · Nächste Fassung: voraussichtlich **1.3.7+18**

Diese Liste sammelt alles, was seit der Fassung 1.3.6+17 in Git liegt, aber noch in keinem Build
bei den Testern angekommen ist. Sie wächst bis zum Release weiter. Beim Bauen der neuen Fassung
dient Teil A als Prüfliste für die Tester, Teil B prüfen Thorsten und Jenny selbst im Adminbereich.

---

## Teil A — Für die Tester (in der App sichtbar)

### 1. Zurückwischen schließt die App nicht mehr

Vorher wurde die App beim Zurückwischen sofort beendet, egal wo man gerade war.

**So prüfst du es:** Geh unten auf einen beliebigen Bereich (Folgen, Filmtipps, Galerie …) und wisch
vom Bildschirmrand nach innen zurück.

**Erwartet:** Du landest auf der Startseite. Wischst du dort noch einmal zurück, schließt sich die App
wie gewohnt. Öffnest du vorher noch einen einzelnen Eintrag, bringt dich Zurück erst wieder in die
Liste und von dort auf die Startseite.

**Besonders wichtig für:** alle mit Wischgeste statt der drei Tasten unten. Gemeldet von Daniel auf
dem Pixel 9a.

### 2. Folge springt nicht mehr fälschlich auf "beendet"

Vorher konnte eine Folge mitten drin plötzlich als komplett gehört gelten und zur nächsten springen —
vermutlich nach einem kurzen Netzaussetzer.

**So prüfst du es:** Hör eine längere Folge, am besten unterwegs bei wechselndem Empfang. Episode 35
war der ursprüngliche Fall.

**Erwartet:** Die Folge läuft an derselben Stelle weiter. Kein plötzlicher Sprung zur nächsten Folge,
keine Meldung "beendet" mitten im Hören.

### 3. Bewertungen erscheinen sofort

Vorher musste jede Rezension erst im Adminbereich freigegeben werden und war bis dahin unsichtbar.

**So prüfst du es:** Geh auf einen Filmtipp oder Locationtipp, tippe auf die Mikrofon-Bewertung und
gib eine eigene Bewertung mit Namen ab.

**Erwartet:** Deine Bewertung steht direkt danach in der Liste, ohne Wartezeit. Der Name bleibt Pflicht.

### 4. Bewertung ist als antippbar erkennbar

Vorher war nicht zu sehen, dass man die Mikrofone antippen kann, um selbst zu bewerten.

**Erwartet:** Neben der Bewertung steht jetzt ein Pfeil als Hinweis. Ein Tipp darauf öffnet die
Bewertungen.

### 5. In der Galerie von Foto zu Foto wischen

Vorher musste man nach jedem Bild zurück in die Übersicht, um das nächste zu öffnen. Wunsch von Sarah.

**So prüfst du es:** Öffne in der Galerie ein Foto und wisch nach links oder rechts.

**Erwartet:** Du kommst direkt zum nächsten beziehungsweise vorherigen Eintrag. Oben links steht die
Position, zum Beispiel "3 / 12". Beschreibung und Datum wechseln passend mit.

**Bitte auch das mitprüfen:**

- Zoom: In ein Foto hineinzoomen und den Ausschnitt verschieben. Solange du hineingezoomt bist, darf
  das Verschieben *nicht* zum nächsten Foto blättern. Erst wieder herausgezoomt soll das Wischen
  wieder blättern.
- Nach dem Blättern ist das nächste Foto wieder normal groß, nicht im Zoom des vorherigen.
- Die grünen "Neu"-Punkte verschwinden auch an den Fotos, die du nur durchgewischt hast.

### 6. Videos sind ins Blättern eingebunden

Videos liegen in derselben Reihenfolge zwischen den Fotos und lassen sich im Viewer direkt abspielen.

**So prüfst du es:** Wisch in der Galerie über ein Video hinweg und spiel es dort auch einmal ab.

**Erwartet:**

- Blätterst du auf ein Video, startet es **nicht** von allein — du tippst auf Play. Nur wenn du ein
  Video in der Übersicht direkt antippst, läuft es sofort los, wie bisher.
- Blätterst du bei laufendem Video weiter, hört der Ton sofort auf. Es darf nichts im Hintergrund
  weiterlaufen, während du schon das nächste Bild ansiehst.
- Blätterst du zum Video zurück, steht es noch an der Stelle, an der du es verlassen hast.
- Die Fortschrittsleiste des Videos lässt sich ziehen, **ohne** dass dabei zum nächsten Bild
  geblättert wird. Bitte hier besonders genau hinschauen — diese beiden Gesten liegen dicht
  beieinander.

### 7. Kopieren und Einfügen im Newsletter-Feld

Vorher ließ sich im E-Mail-Feld der Newsletter-Anmeldung nichts kopieren oder einfügen (gemeldet auf
einem Samsung-Gerät).

**So prüfst du es:** Öffne die Newsletter-Anmeldung, halte das E-Mail-Feld gedrückt und füge eine
kopierte Adresse ein.

**Erwartet:** Das Menü mit Einfügen/Kopieren erscheint und funktioniert. Die Tastatur bietet die
hinterlegte E-Mail-Adresse zum Ausfüllen an.

### Bitte nebenbei mitprüfen

Diese Bereiche wurden nicht geändert, hängen aber an denselben Stellen — ein kurzer Blick lohnt:

- Android Auto: Folgenliste im Auto öffnen und eine Folge starten.
- Wiedergabe läuft weiter, wenn der Bildschirm sich sperrt.
- Der Home-Button oben links führt weiterhin zur Startseite.
- Die grünen "Neu"-Punkte an den Bereichen unten verschwinden nach dem Ansehen.

---

## Teil B — Adminbereich (Thorsten und Jenny, nicht für Tester)

Diese Änderungen liegen in Git, sind aber noch nicht auf dem Server. Beim Release per SFTP hochladen.

- **Passwort-Reset ohne E-Mail-Abfrage:** Code kommt direkt, maskierte Anzeige der Adresse, Auto-Login
  nach dem Zurücksetzen.
- **2FA-Code statt Passwort beim Löschen** an allen Stellen mit Löschbestätigung.
- **Newsletter-Verlauf löschbar.**
- **Alle Transaktionsmails im Südsalat-Briefkopf:** Freischaltungscode, Passwort-Reset,
  Feedback-Benachrichtigung an die Admins.
- **Newsletter-Formular neu sortiert:** Absender → An wen → Betreff → Überschrift → Foto → Text →
  Folgen-Link. Die Überschrift steht jetzt im Briefkopf und nicht mehr doppelt.
- **Rezensionsverwaltung ohne Freigabeschritt:** nur noch Bearbeiten und Löschen, eine gemeinsame
  Liste statt getrennt "Ausstehend"/"Freigegeben".
- **Galerie:** Button-Farben von Retuschieren/Abbrechen korrigiert.
- **Noch offen, vor dem Release erledigen:** grüne "Abbrechen"/"Zurück"-Buttons auf oliv
  (`button-secondary`) umstellen in `events.php`, `location-tips.php`, `movie-tips.php`,
  `newsletter-lists.php`, `tip-reviews.php` und `newsletter.php`.

### Bereits live, nicht Teil dieses Release-Tests

- Push-Nachrichten nennen den tatsächlich handelnden Admin statt immer "Jenny" (wurde auf
  ausdrückliche Bitte sofort deployt).

---

## Beim Release nicht vergessen

1. Versionsnummer in `04-App/pubspec.yaml` hochsetzen — der Android-`versionCode` muss immer steigen.
2. Vor dem Codemagic-Build `git push`, sonst baut Codemagic den alten Stand.
3. Die Admin- und API-Dateien vollständig aus Git per SFTP hochladen, nicht nur einzelne — Live-Server
   und `main` sind derzeit bewusst auseinander.
4. Release-Notes decken alles seit der letzten wirklich veröffentlichten Store-Fassung ab, nicht nur
   seit der letzten Testfassung.
