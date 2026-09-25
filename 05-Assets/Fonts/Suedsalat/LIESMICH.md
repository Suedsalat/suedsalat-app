# Hausschrift „Suedsalat“

Eigene Schrift des Podcasts Südsalat in zwei Schnitten: **Fett** (Überschriften, wie „SÜDSALAT“
im Logo) und **Normal** (Untertitel und Text, wie „THEMEN AUS DEM LEBEN“). Technischer Name
„Suedsalat“ ohne ü, weil Schriftnamen nur einfache Buchstaben enthalten sollten.

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
- spitze Ecken (Libre Franklin rundet sie leicht ab), runde Punkte (Umlaute, i, j, Satzzeichen)
- Ü-Punkte in Größe, Abstand und Höhe wie im Logo
- alle Großbuchstaben-Paare geprüft: mindestens 3 % der Versalhöhe Abstand, nichts berührt sich

Vergleich: `Vergleich-mit-Logo.png` (auch auf U:\Suedsalat-Schrift-Vergleich.png), Probeblätter.
Verbleibender sichtbarer Unterschied: die Form der S-Enden.

## Neu bauen

Werkzeuge in `werkzeug/` (Python 3 mit `fonttools uharfbuzz freetype-py pillow`):

```
python final2.py          # baut Suedsalat-Bold.ttf und Suedsalat-Regular.ttf
python kollision.py Suedsalat-Bold.ttf Suedsalat-Regular.ttf
python vergleichsbild2.py
```

Danach beide Dateien nach `04-App/assets/fonts/` kopieren.
