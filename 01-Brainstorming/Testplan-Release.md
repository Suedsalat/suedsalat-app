# Testplan für das gesammelte Release

Stand: 2026-09-24 · Bei den Testern läuft: **1.3.2 (Code 13)**, seit 12.09.2026 · In Prüfung: **1.3.6 (Code 17)** · Nächste Fassung: **2.0.0 (Code 18)**

**Wichtig zur Ausgangslage:** Die Tester sitzen noch auf 1.3.2. Die Fassungen 1.3.3, 1.3.4 und 1.3.5
sind nie bei ihnen angekommen — jede wurde durch den nächsten Upload ersetzt, während sie noch in
der Prüfung war. Wenn 1.3.6 freigegeben wird (oder später 2.0.0), springen die Tester deshalb über
vier Versionen auf einmal. Sie bekommen dann auch alles zu sehen, was sie bisher nie hatten.

Der Testplan ist danach geteilt: **Teil A** ist neu gebaut und von niemandem geprüft. **Teil B** ist
schon eine Weile fertig, für die Tester aber trotzdem brandneu. **Teil C** betrifft nur den
Adminbereich.

> Solange 1.3.6 in Prüfung ist, wird **nichts** hochgeladen — sonst wird sie wie ihre drei Vorgänger
> ersetzt und die Tester bleiben auf 1.3.2.

---

## Teil A — Neu in 2.0.0, noch von niemandem geprüft

### 1. Zurückwischen schließt die App nicht mehr

Vorher wurde die App beim Zurückwischen sofort beendet, egal wo man gerade war.

**So prüfst du es:** Geh unten auf einen beliebigen Bereich (Folgen, Filmtipps, Galerie …) und geh
zurück — per Wischgeste vom Bildschirmrand **oder** mit der Zurück-Taste unten.

**Erwartet:** Du landest auf der Startseite. Gehst du dort noch einmal zurück, schließt sich die App
wie gewohnt. Öffnest du vorher noch einen einzelnen Eintrag, bringt dich Zurück erst wieder in die
Liste und von dort auf die Startseite.

**Betrifft beide Bedienarten:** Wischgeste und Zurück-Taste lösen unter Android denselben Vorgang aus.
Gemeldet von Daniel (Pixel 9a, Wischgeste) und Angela (Zurück-Taste).

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

### 5. In der Galerie von Bild zu Bild wischen

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

### 7. Bei jedem Tipp und Foto steht, von wem er ist

Vorher trugen nur eingereichte Beiträge einen Namen, und zwar vorne im Beschreibungstext („von Angela: …“).
Eigene Beiträge von Thorsten und Jenny hatten keinen. Gemeldet von Angela.

**So prüfst du es:** Öffne Veranstaltungen, Filmtipps, Locationtipps und die Galerie und tippe jeweils
auch einmal einen Eintrag an.

**Erwartet:** Klein und mit Personensymbol steht „Tipp von …“ bei Veranstaltungen, Film- und
Locationtipps und „Foto von …“ in der Galerie, sowohl in der Übersicht als auch in der geöffneten
Ansicht. Bei Einsendungen steht der Name des Einsenders, bei eigenen Beiträgen immer „Südsalat“. Keine
Beschreibung beginnt mehr mit „von …:“. Ältere Einsendungen ohne Namen zeigen keine solche Zeile.

**Außerdem neu im Feedback-Formular:** Der Name ist jetzt bei **allen** Einsendungen Pflicht. Absenden
ohne Namen muss die Meldung „Bitte gib deinen Namen ein.“ zeigen. Bei Tipps steht unter dem Feld, dass der
Name öffentlich beim Tipp erscheint, bei allgemeinem Feedback und Fragen nur „Ein Spitzname geht auch.“

**Und bei den Bewertungen:** Der Name steht jetzt oben klein mit Personensymbol, rechts daneben das Datum,
darunter die Mikrofone und der Text.

### 8. Kopieren und Einfügen im Newsletter-Feld

Vorher ließ sich im E-Mail-Feld der Newsletter-Anmeldung nichts kopieren oder einfügen (gemeldet auf
einem Samsung-Gerät).

**So prüfst du es:** Öffne die Newsletter-Anmeldung, halte das E-Mail-Feld gedrückt und füge eine
kopierte Adresse ein.

**Erwartet:** Das Menü mit Einfügen/Kopieren erscheint und funktioniert. Die Tastatur bietet die
hinterlegte E-Mail-Adresse zum Ausfüllen an.

---

## Teil B — Aus 1.3.3 bis 1.3.6, für die Tester zum ersten Mal sichtbar

Diese Dinge sind seit Mitte September fertig, kamen aber nie bei den Testern an. Wer von 1.3.2
kommt, sieht sie jetzt zum ersten Mal — deshalb gehören sie in den Test.

### 9. Android Auto

Die größte Neuerung dieses Sprungs. Die App meldet sich beim Auto als Medien-App an.

**So prüfst du es:** Handy mit Android Auto verbinden und im Auto die Medien-Auswahl öffnen.

**Erwartet:**

- Südsalat taucht in der App-Liste von Android Auto auf.
- Es gibt eine **Folgenliste**, aus der sich eine Folge direkt starten lässt — nicht nur ein
  Wiedergabebildschirm.
- Die Liste lässt sich **durchsuchen**.
- Play, Pause und Spulen lassen sich über die Autotasten und das Lenkrad bedienen.
- Die Oberfläche ist im Südsalat-Grün eingefärbt, nicht grau.
- Folgen ohne eigenes Bild zeigen das Südsalat-Logo statt einer leeren Fläche.

**Bitte unbedingt melden, wenn** "Auswahl konnte nicht geladen werden" erscheint — das war der Fehler,
der mehrere Anläufe gekostet hat, und der Fix ist in der Fassung, die ihr bekommt.

### 10. Wiedergabe-Anzeige auf dem Sperrbildschirm

**Erwartet:** Beim Abspielen erscheint eine Benachrichtigung mit Titel, Bild und Steuerung. Das
Symbol in der Statusleiste ist einfarbig, nicht das bunte App-Icon. Läuft eine Folge ohne eigenes
Bild, steht dort das quadratische Südsalat-Logo mit Schriftzug, kein einzelnes Mikrofon auf leerem
Grund.

### 11. Feedback einer Folge zuordnen

**So prüfst du es:** Öffne das Feedback-Formular.

**Erwartet:** Es gibt ein zusätzliches, freiwilliges Auswahlfeld für die Folge, um die es geht.
Leerlassen muss weiterhin möglich sein.

### Nichts zu sehen, und das ist richtig so

In diesen Fassungen steckt zusätzlich eine anonyme Nutzungsstatistik (welche Folgen wie lange gehört
werden). Davon ist in der App **nichts** sichtbar, und es werden keine persönlichen Daten erhoben —
also bitte nicht danach suchen.

---

## Teil C — Adminbereich (Thorsten und Jenny, nicht für Tester)

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
- **Neues Feld „Tipp von“ bzw. „Foto von“** bei Veranstaltungen, Film- und Locationtipps und in der
  Galerie (alle vier Galerie-Formulare). Beim Übernehmen einer Einsendung mit dem Namen des Einsenders
  vorbelegt, beim eigenen Anlegen immer mit „Südsalat“ (egal ob Thorsten oder Jenny). Jederzeit
  nachträglich änderbar, leer heißt: ohne Namen. Beschreibungen bekommen kein „von …:“ mehr vorangestellt.
- **Rezensionen im Admin-Bereich:** Der Name ist in allen drei Formularen Pflicht und mit „Südsalat“
  vorbelegt; auch die Rezension per Häkchen beim Anlegen eines Tipps heißt „Südsalat“. Beim Bearbeiten
  einer Rezension lässt sich der Name nicht leeren.

### Bereits live, nicht Teil dieses Release-Tests

- Push-Nachrichten nennen den tatsächlich handelnden Admin statt immer "Jenny".
- Aus einem eingereichten Locationtipp lässt sich ein Locationtipp anlegen (war nie gebaut).
- Breite, greifbare Scrollleisten an den Tabellen im Adminbereich.
- Alle "Abbrechen"/"Zurück"-Links sind oliv statt grün.
- Die Code-Ordner `lib/` und `config/` sind von außen gesperrt.

---

## Beim Release nicht vergessen

1. **Erst prüfen, ob im Alpha-Track noch etwas "wird überprüft".** Wenn ja: nicht hochladen. Ein
   neuer Upload ersetzt die laufende Prüfung und startet sie von vorn — genau daran sind 1.3.3,
   1.3.4 und 1.3.5 gescheitert.
2. Versionsnummer in `04-App/pubspec.yaml` hochsetzen — der Android-`versionCode` muss immer steigen.
3. Vor dem Codemagic-Build `git push`, sonst baut Codemagic den alten Stand.
4. Die Admin- und API-Dateien vollständig aus Git per SFTP hochladen, nicht nur einzelne — Live-Server
   und `main` sind derzeit bewusst auseinander. `admin/location-tips.php` ist live ein Mischstand und
   wird dabei einmal komplett überschrieben.
4a. **Migration „Tipp von“ ZUERST ausführen**, dann erst die PHP-Dateien hochladen:
   `sql/_add-tip-submitter-name-once.php?secret=suedsalat-tipvon-2026-temp`, danach vom Server löschen.
   Die neuen `api/movie-tips.php`, `api/location-tips.php`, `api/events.php` und `api/gallery.php`
   fragen die Spalte `submitted_by_name` ab — laufen sie vor der Migration, bleiben diese vier Tabs in der
   App leer. Auch `config/config.php` mit hochladen (Konstante `OWN_CONTENT_NAME`). Die Migration entfernt außerdem das
   „von …:“ aus bestehenden Beschreibungen, deshalb erst zusammen mit der neuen App-Version ausführen.
4b. **Danach die 2.0-Migration** `sql/_migrate-2-0-once.php?secret=suedsalat-migrate20-2026-temp`,
   erst dann die PHP-Dateien hochladen; danach die Migration vom Server löschen.
   **Achtung Statistik:** Ab diesem Moment zählt der Server nur noch Geräte, die im neuen
   Einwilligungsdialog zugestimmt haben. Alte App-Versionen (bis 1.3.x) liefern keine Zahlen mehr.
   Die Kurven im Admin fallen deshalb nach dem Release sichtbar ab. Das ist gewollt (§ 25 TDDDG) und
   kein Fehler. Wie viele zugestimmt haben, steht oben auf der Seite „Statistiken“.
5. Release-Notes decken alles seit **1.3.2** ab, nicht nur seit der letzten gebauten Fassung — das ist
   der Stand, von dem die Nutzer tatsächlich kommen.
6. ~~Vor dem Build: Schrift in die App einbetten.~~ **Erledigt im Code (2026-09-25):** Open Sans 400
   liegt unter `assets/google_fonts/`, das Nachladen von Google ist in `main.dart` abgeschaltet. Der
   Test `test/google_fonts_offline_test.dart` schlägt fehl, falls das Theme je eine Variante anfordert,
   die dort nicht liegt. Beim Testen der App kurz auf die Schrift achten: Fließtext muss wie bisher
   aussehen, nicht wie Roboto oder eine andere Systemschrift.
6a. **Mit dem Release: Datenschutzerklärung Abschnitt 2 c anpassen** (`seiten/datenschutz.html`), weil der
   Name bei Tipps dann Pflicht ist und immer öffentlich erscheint. Zwei Stellen ersetzen:
   - „Optional kannst du deinen Namen angeben, die Nachricht …“ →
     „Dazu gibst du einen Namen an – ein Spitzname genügt. Optional kannst du die Nachricht …“
   - „Hast du einen Namen angegeben, nennen wir dich dabei in der Regel als Einsender (z. B. „von Inga“).
     Möchtest du das nicht, lass das Namensfeld einfach leer.“ →
     „Dabei steht dein Name dabei („Tipp von Inga“ bzw. „Foto von Inga“) – du kannst dafür auch einen
     Spitznamen verwenden. Unsere eigenen Beiträge erscheinen als „Südsalat“.“
7. **Nach dem Release beider Stores:** in `seiten/datenschutz.html` den Übergangsabsatz
   „2 h) Schriftart“ entfernen, sobald keine App-Version mehr im Umlauf ist, die die Schrift von
   Google lädt.
