# Hausschrift „Suedsalat“

Eigene Schrift des Podcasts Südsalat in zwei Schnitten: **Südsalat Bold** (Überschriften, wie
„SÜDSALAT“ im Logo) und **Südsalat Regular** (Untertitel und Text, wie „THEMEN AUS DEM LEBEN“).
Im Schriftmenü heißt sie „Südsalat“; nur der technische PostScript-Name ist „Suedsalat-Bold“ /
„Suedsalat-Regular“ (dort sind keine Umlaute erlaubt), ebenso die Dateinamen. Version 1.002.

## Herkunft und Lizenz

Abgeleitet von **Libre Franklin** (The Libre Franklin Project Authors), einer freien Nachbildung von
Franklin Gothic, unter der **SIL Open Font License 1.1** (`Suedsalat-OFL.txt`). Die Lizenz erlaubt
Verändern, Umbenennen und Weitergeben – auch in der App. Bedingung: Der Lizenztext geht mit (in der
App unter Einstellungen › Lizenzen) und die Schrift wird nicht allein verkauft.

Die Original-Franklin-Gothic aus dem Logo (ITC/Monotype) ist nur für die Nutzung auf dem PC
lizenziert und darf nicht in die App. Ihre Umrisse wurden **nicht** übernommen – nur Maße, die
aus dem Logo-Bild gemessen wurden.

## Auf das Logo abgestimmt (U:/Logo/Suedsalat_Logo.png)

- Strichstärke, Breite der Buchstaben, Laufweite und jeder Buchstabenabstand in SÜDSALAT
- **L–A-Abstand** wie Thorstens Leerzeichen im Logo (fest eingebaut, gilt auch für LÄ)
- **Schwärze wie im Logo:** Das Logo ist PowerPoints künstliches Fett auf Franklin Gothic Medium,
  das auch waagerechte Striche verdickt. Deshalb Stärke 668 plus gezielte Verdickung in der Höhe
  (wie FreeTypes Fettrechnen) – Tintenmenge 48,7 % gegenüber 48,8 % im Logo
- spitze Ecken (Libre Franklin rundet sie leicht ab), auch Innenecken von M, W, N ohne
  „Tintenfallen“; runde Punkte (Umlaute, i, j, Satzzeichen)
- Ü-Punkte in Größe, Abstand und Höhe wie im Logo
- **S-Enden wie im Logo:** flach abgeschnitten (13,1° oben, 11,8° unten, aus dem Logo gemessen),
  der Bogen läuft steil (66°) in die Ecke – in beiden Schnitten
- alle Paare aus Groß-, Kleinbuchstaben und Ziffern geprüft: mindestens 3 % der Versalhöhe
  Abstand, nichts berührt sich

Vergleiche: `Logo-Vergleich.png` (ganzes Logo, auch U:\Suedsalat-Logo-Vergleich.png),
`Vergleich-mit-Logo.png` (übereinandergelegt, auch U:\Suedsalat-Schrift-Vergleich.png), `Probeblatt.png`.
Verbleibende Unterschiede sind kleine Formdetails; SÜDSALAT deckt sich zu 90 % mit dem Original.

## In Windows installiert

Seit 25.09.2026 für Thorstens Windows-Benutzer installiert (ohne Admin-Rechte):
`%LOCALAPPDATA%\Microsoft\Windows\Fonts\Suedsalat-*.ttf`, eingetragen unter
`HKCU\Software\Microsoft\Windows NT\CurrentVersion\Fonts`. Auf einem anderen PC: beide
`.ttf` rechts anklicken › „Installieren“. Eine neuere Fassung einfach genauso darüber installieren
(laufende Programme vorher schließen).

## Logo in PowerPoint mit Südsalat setzen

- **SÜDSALAT:** Schrift „Südsalat“, **fett**, **88 pt** (entspricht den bisherigen 98 pt
  Franklin Gothic Medium – Südsalat ist bei gleicher Punktgröße etwas größer).
- **THEMEN AUS DEM LEBEN:** „Südsalat“, normal, **36,5 pt** (statt 40,5 pt Franklin Gothic Book).
- **Das Leerzeichen zwischen L und A entfernen** – der Abstand steckt jetzt in der Schrift,
  sonst ist er doppelt.
- Falls der L-A-Abstand fehlt: Start › Schriftart › Zeichenabstand › „Unterschneidung für
  Schriftarten ab … Pt“ einschalten (die Paar-Abstände der Schrift brauchen das).
- Beim Speichern „Schriftarten in der Datei einbetten“ wählen, dann sieht die Datei auch auf
  PCs ohne installierte Südsalat richtig aus (die Lizenz erlaubt das Einbetten).

## Neu bauen

Werkzeuge in `werkzeug/` (Python 3 mit `fonttools uharfbuzz freetype-py pillow`; U:/Logo muss erreichbar sein):

```
python final3.py          # baut Suedsalat-Bold.ttf und Suedsalat-Regular.ttf
python kollision.py Suedsalat-Bold.ttf Suedsalat-Regular.ttf
python vergleichsbild2.py
```

Vorher `VERSION` in `bauen.py` erhöhen. Danach beide Dateien nach `04-App/assets/fonts/` kopieren
und in Windows neu installieren.
