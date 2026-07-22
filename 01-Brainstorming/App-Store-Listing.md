# App Store (iOS) – Store-Eintrag & Einreichungs-Notizen

Stand: 2026-07-16. Ergänzt [Play-Store-Listing.md](Play-Store-Listing.md) um die Apple-spezifischen Felder/Anforderungen (andere Zeichenlimits, zusätzliche Felder wie Untertitel/Keywords, andere Screenshot-Formate).

## App-Name (max. 30 Zeichen)

```
Südsalat
```

## Untertitel (max. 30 Zeichen, genutzt: 27)

```
Folgen, Termine & Filmtipps
```

Erscheint direkt unter dem App-Namen auf der Store-Seite — Apple-spezifisches Feld, gibt es bei Google Play nicht in dieser Form.

## Werbetext / Promotional Text (max. 170 Zeichen, optional)

```
Die offizielle Begleit-App zum Südsalat-Podcast: keine Folge, keinen Termin und keinen Filmtipp mehr verpassen. Kostenlos & werbefrei.
```

Kann jederzeit ohne neue Review geändert werden (z. B. für Hinweise auf eine neue Staffel) — anders als Name/Untertitel/Beschreibung, für die eine neue Version eingereicht werden muss.

## Beschreibung (max. 4000 Zeichen)

Inhaltlich identisch zur Play-Store-Beschreibung übernommen (siehe [Play-Store-Listing.md](Play-Store-Listing.md)), da die App-Funktionen plattformunabhängig sind:

```
Die offizielle Begleit-App zum Podcast „Südsalat" von Jenny & Thorsten.

Im Südsalat-Podcast dreht sich alles um den ganz normalen Alltagswahnsinn – mit dieser App verpasst du keine Folge, keinen Termin und keinen Tipp mehr.

DAS BIETET DIR DIE APP

• Folgen
Alle Episoden zum Nachhören direkt in der App, inklusive eigenem Player. Du siehst auf einen Blick, welche Folgen du schon gehört hast und welche neu sind.

• Veranstaltungen
Alle Termine rund um den Podcast auf einen Blick – mit Poster, Beschreibung und Datum. Wurde ein Termin in einer Folge besprochen, springst du mit einem Tipp direkt an die passende Stelle im Player.

• Filmtipps
Jennys Filmempfehlungen mit Poster, Beschreibung und Link zum Film – ebenfalls verknüpft mit der Folge, in der der Tipp gefallen ist.

• Locationtipps
Restaurants, Museen und Ausflugsziele, die im Podcast empfohlen wurden – mit Beschreibung, Foto und Link.

• Mikro-Bewertungen
Bewerte Film- und Locationtipps mit 1 bis 5 Mikros und lies, was andere Hörer:innen dazu geschrieben haben. Jede Rezension wird vor der Veröffentlichung kurz geprüft.

• Galerie
Fotos und kurze Videos rund um den Podcast, von Jenny & Thorsten und aus der Community.

• Feedback
Schreib oder sprich uns direkt aus der App: einen Veranstaltungs-, Film- oder Locationtipp, einen Fotovorschlag, eine Frage oder einfach eine Nachricht – optional mit mehreren Fotos, einem Video oder einer Sprachnachricht. Wir lesen und hören jede Nachricht persönlich.

• Push-Benachrichtigungen
Verpasse keine neue Folge, keinen neuen Termin und keinen neuen Tipp – die App informiert dich automatisch, sobald es etwas Neues gibt.

• Newsletter
Wer's klassisch mag: bleib zusätzlich per E-Mail auf dem Laufenden.

Die App ist komplett kostenlos und enthält keine Werbung. Wir sammeln keine Nutzungsdaten für Werbezwecke und verwenden keine Analyse- oder Tracking-Tools.

Hör direkt rein und werde Teil der Südsalat-Community!
```

## Keywords (max. 100 Zeichen gesamt, kommagetrennt, keine Leerzeichen nach Komma sparen Platz)

```
podcast,südsalat,folgen,termine,film,filmtipps,galerie,newsletter,alltag,verein
```

(79 Zeichen — Reserve für Anpassungen vorhanden. Keywords sind bei Apple nicht öffentlich sichtbar, nur für die Suche relevant.)

## Support-URL / Marketing-URL / Datenschutz-URL

- Datenschutzerklärung: `https://www.xn--sdsalat-n2a.eu/seiten/datenschutz.html` (bereits live, siehe [[project_suedsalat_app]])
- Support-URL: noch zu klären — Homepage-Kontaktseite oder eigene Support-Mail?
- Marketing-URL (optional): z. B. südsalat.eu

## Offene Punkte / Entscheidungen

1. **Screenshots** — Apple verlangt mindestens einen Satz für **6,7″-iPhones** (Pflicht, z. B. iPhone 15/16 Pro Max), zusätzlich ggf. für kleinere Formate falls gewünscht. Da `TARGETED_DEVICE_FAMILY = "1,2"` im Xcode-Projekt gesetzt ist (iPhone **und** iPad), verlangt Apple aktuell vermutlich auch **iPad-Screenshots** (12,9″) — falls niemand ein iPad zum Testen/Screenshotten hat, wäre die einfachste Lösung, iPad-Unterstützung zu entfernen (nur `"1"` = iPhone-only setzen). **Zu klären:** Soll die App offiziell iPad-fähig sein, oder auf iPhone beschränkt werden?
2. Wer hat ein 6,7″-iPhone (Pro Max Modell) griffbereit, um die Pflicht-Screenshots direkt am Gerät zu machen (einfachste Methode ohne Simulator/Mac)?
3. **Apple Age Rating**-Fragebogen (Apples eigenes Pendant zur Play-Store-IARC-Einstufung) muss separat in App Store Connect ausgefüllt werden — inhaltlich dieselben Angaben wie bei Google (siehe Play-Store-Listing.md: keine Gewalt/sexuelle Inhalte/Glücksspiel, nutzergenerierte Inhalte werden moderiert).
4. **App-Icon** (1024×1024 px, ohne Transparenz/abgerundete Ecken) — vermutlich schon vorhanden, da der Build bereits läuft; nur zur Sicherheit gegenprüfen, ob es in App Store Connect korrekt hochgeladen ist.
5. Copyright-Zeile (z. B. "© 2026 Thorsten Koch") — noch einzutragen.
