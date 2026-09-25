# Konzept Version 2.0.0 – Gast und registrierter Hörer

Stand: 2026-09-25 · Status: **Brainstorming abgeschlossen, noch nicht gebaut**

Mit 2.0.0 bekommt die App zwei Wege: als **Gast** wie bisher anonym, oder als **registrierter Hörer**
mit Konto. Öffentliche Beiträge (Tipps, Fotos, Rezensionen, Kommentare) gibt es dann nur noch mit
Konto, und der Spitzname erscheint automatisch dabei.

---

## 1. Rollen und Rechte

| | Gast | Registrierter Hörer |
|---|---|---|
| Folgen hören, Android Auto, CarPlay | ja | ja |
| Wiedergabeposition merken, Kapitel | ja | ja |
| Veranstaltungen, Film-/Locationtipps, Galerie, Rezensionen **ansehen** | ja | ja |
| Bereich **„Bonus und Outtakes“** | **unsichtbar** | ja |
| **Schriftliches** allgemeines Feedback und Fragen senden | ja | ja |
| Sprachnachricht senden | nein | ja |
| Veranstaltungs-, Film-, Location-, Fototipps einreichen | nein | ja |
| Rezensionen schreiben | nein | ja |
| Kommentare unter Fotos (neu) | nein | ja |
| Beiträge **melden** | ja | ja |
| Andere Nutzer ausblenden | – | ja |
| Anonyme Statistik (nur mit Einwilligung) | ja | ja |

Wo ein Gast etwas nicht darf, sieht er den Knopf trotzdem, bekommt aber den Hinweis
„Zum Mitmachen kostenlos registrieren“ mit Weg zur Registrierung. Nur „Bonus und Outtakes“ ist für
Gäste ganz ausgeblendet.

**Admins:** Thorsten (Rolle `owner`) und Jenny (`member`) wie bisher. **Sperren darf nur Thorsten.**
Eigene Beiträge der beiden erscheinen als „Südsalat“.

---

## 2. Startbildschirm

Beim ersten Start nach dem Update, und so lange man weder angemeldet ist noch „Als Gast“ gewählt hat:

- **Als Gast weiter**
- **Anmelden** (wer schon ein Konto hat)
- **Registrieren**

Als Gast kann man jederzeit später in den Einstellungen ein Konto anlegen oder sich anmelden. Lokal
Gespeichertes (Wiedergabepositionen, gemerkter Name, Einstellungen) bleibt dabei erhalten.

Bestehende Nutzer und Tester starten nach dem Update als Gast, bis sie sich registrieren.

---

## 3. Registrierung und Anmeldung

**Anmeldung ohne Passwort, per Code:** E-Mail-Adresse eingeben → sechsstelliger Code per Mail →
Code in der App eintippen → angemeldet. Die Anmeldung hält wie heute ein halbes Jahr und erneuert sich
bei Nutzung. Es gibt kein Passwort, also auch kein „Passwort vergessen“.

**Bei der Registrierung angegeben:**

| Feld | Pflicht | Öffentlich? | Zweck |
|---|---|---|---|
| Vorname, Nachname | ja | **nie** | Damit wir wissen, wer hinter einem Konto steht, falls es Ärger gibt |
| E-Mail-Adresse | ja | nie | Anmeldung per Code, Benachrichtigungen zum Konto |
| Spitzname | ja | **ja** | Erscheint automatisch bei Tipps, Fotos, Rezensionen, Kommentaren |

Hinweis direkt am Feld Spitzname: *„Unter diesem Namen erscheinen deine Tipps, Fotos, Rezensionen und
Kommentare in der App.“*

**Spitznamen** sind eindeutig (jeder nur einmal). **Gesperrt** für Nutzer: Südsalat, Suedsalat,
Süd-Salat und Ähnliches, Thorsten, Jenny, Thorsten Koch, Jenny Fourate, Thorsten K., Jenny F. und
Ähnliches. Verglichen wird eine vereinfachte Form (klein geschrieben, ä→ae usw., ohne Leerzeichen,
Punkte und Bindestriche), sodass auch „SÜD-salat“ oder „jenny.f“ nicht durchkommen. Die Admin-Konten
dürfen diese Namen nutzen.

**Zustimmungen bei der Registrierung**, jede einzeln und nichts vorangekreuzt:

1. **Nutzungsbedingungen akzeptieren** – Pflicht (Google verlangt das vor dem ersten Beitrag).
2. **Hinweis auf die Datenschutzerklärung** – kein Häkchen, nur ein Link; eine Datenschutzerklärung
   wird nicht „akzeptiert“, sondern zur Kenntnis gegeben.
3. **Anonyme Statistik erlauben** – freiwillig. Registrieren muss ohne dieses Häkchen möglich sein
   (Kopplungsverbot, Art. 7 Abs. 4 DSGVO).

**Mindestalter:** 16 Jahre, festgehalten in den Nutzungsbedingungen.

Der Newsletter bleibt getrennt mit eigener Anmeldung (Double-Opt-In). Ein Konto meldet niemanden
automatisch zum Newsletter an.

---

## 4. Einstellungen

- **Abmelden**
- **Spitzname ändern** (mit denselben Regeln wie bei der Registrierung)
- **Konto löschen** – direkt in der App, Pflicht bei Apple und Google. Löscht Konto, Name und
  E-Mail-Adresse; öffentliche Beiträge werden entweder mitgelöscht oder bleiben als „gelöschter
  Nutzer“ stehen (**noch zu entscheiden**, siehe Abschnitt 10).
- **Anonyme Statistik** ein/aus
- Gäste: **gemerkter Name** für Feedback ändern/löschen; **Anmelden / Registrieren**

---

## 5. Melden und Moderation

**Melden** an jedem öffentlichen Beitrag (Tipps, Fotos, Rezensionen, Kommentare), **auch für Gäste**.

- Kategorie ist Pflicht: *Beleidigung/Hass · Spam/Werbung · Unangemessenes Bild · Verletzt meine Rechte
  (Foto von mir, Urheberrecht) · Sonstiges*
- Freitext freiwillig
- Geht an eine Liste **„Meldungen“** im Admin-Bereich, dazu eine E-Mail an die Admins

**Automatisch ausblenden:** Melden **drei verschiedene registrierte Hörer** denselben Beitrag,
verschwindet er sofort für alle, bis Thorsten entscheidet (wiederherstellen oder löschen). Meldungen von
Gästen landen ebenfalls in der Liste, zählen aber nicht für die Automatik – sonst könnte eine einzelne
Person per Neuinstallation mehrfach melden.

**Reaktionszeit:** keine feste gesetzliche Frist, „zeitnah und sorgfältig“. Ziel: Meldungen innerhalb von
ein bis zwei Tagen ansehen. **Offensichtlich rechtswidrige** Beiträge unverzüglich entfernen, sobald man
davon weiß – sonst haftet man selbst.

**Sperren** (nur Thorsten): Ein gesperrter Hörer kann nichts mehr veröffentlichen und nicht melden.
Hören und Lesen bleibt möglich (**Vorschlag**, noch zu bestätigen).

**Nutzer ausblenden:** Jeder registrierte Hörer kann einen anderen für sich ausblenden, dessen Beiträge
sieht er dann nicht mehr. Apple erwartet das für Apps mit Nutzerbeiträgen.

**Wortfilter:** Offensichtliche Beleidigungen werden vor dem Veröffentlichen abgefangen.

Apple und Google moderieren nichts in der App. Sie verlangen nur, dass diese Werkzeuge da sind und
genutzt werden (Apple-Richtlinie 1.2, Google-Richtlinie zu nutzergenerierten Inhalten).

---

## 6. Weitere Inhalte von 2.0.0

Alles von der bisherigen Liste, für beide Rollen, soweit nicht anders vermerkt:

- **Alles aus dem gesammelten Release 1.3.7** (Zurück-Geste, Galerie wischen, Videos, „Tipp von …“,
  eingebettete Schrift, Namenspflicht, Rezensionen sofort sichtbar – siehe `Testplan-Release.md`)
- **Wiedergabeposition merken** (lokal)
- **Kapitel pro Folge** aus der `podcast.rss`, Anzeige im Player und in Android Auto (siehe Notizen zum
  Kapitel-Plan; Thorsten bekommt beim Bauen eine Anleitung, wie er die RSS-Datei erweitert)
- **Name lokal merken** für Gäste
- **Kommentare unter Fotos** – nur Registrierte, Spitzname oben klein, sofort sichtbar, Admins können
  löschen, Mail an Admins
- **„Bonus und Outtakes“** – nur Registrierte sehen den Bereich, nur Thorsten lädt hoch (Icon macht
  Thorsten)
- **Statistik nur mit Einwilligung** – Dialog beim Start, Schalter in den Einstellungen, Einwilligung
  pro Installation mit Zeitpunkt und Textversion gespeichert (Nachweispflicht), im Admin nur Zahlen
  (zugestimmt / abgelehnt / nicht gefragt), keine Namen; Plattform schickt die App selbst mit;
  „eindeutige Hörer“ nur bei Einwilligung. **Keine persönliche Verfolgung** von Hörverhalten, auch
  nicht bei Registrierten.

---

## 7. Was wir schon haben

| Baustein | Vorhanden | Neu zu bauen |
|---|---|---|
| Anmeldung am Server, Schlüssel, Erneuerung | Geräte-Anmeldung; `refresh.php` ist ausdrücklich für einen späteren Nutzertyp vorbereitet | Nutzertyp „Hörer“, Verknüpfung Konto ↔ Geräte |
| Code per Mail, Code-Prüfung | Admin-Bereich: Freischaltungs- und Reset-Codes, Mails im Südsalat-Briefkopf | Übertragen auf Hörer |
| Schutz vor Missbrauch | Mengenbegrenzung, Sperre nach Fehlversuchen | Übertragen |
| Name automatisch bei Beiträgen | `submitted_by_name`, „Tipp von …“, Namenspflicht (für 1.3.7 fertig) | Name aus dem Konto statt aus dem Formular |
| Anzeige-Element für Namen | `TipSubmitterLine` | – |
| Löschen durch Admins, Passwort-/2FA-Bestätigung | vorhanden | Meldungsliste, Sperren, Hörerliste |
| Mails an Admins bei Einsendungen | vorhanden | Mail bei Meldungen |
| Einstellungsseite | vorhanden | Kontobereich |
| Owner-only-Bereiche | Newsletter als Vorbild | Sperren, Outtakes |
| Sichere Ablage auf dem Gerät | `FlutterSecureStorage` | – |

---

## 8. Datenmodell (Skizze)

- `listeners` – Vorname, Nachname, E-Mail, Spitzname, vereinfachter Spitzname (eindeutig), E-Mail
  bestätigt am, gesperrt am/Grund, angelegt am
- `listener_login_codes` – Code (nur als Hash), gültig bis, Fehlversuche
- Verknüpfung Hörer ↔ Installation (ein Hörer, mehrere Geräte)
- `listener_id` an Rezensionen, Einsendungen, Kommentaren, Tipps
- `reports` – Beitragsart, Beitrag, meldender Hörer oder Gerät, Kategorie, Text, Zeitpunkt, Status
- `listener_hidden` – wer blendet wen aus
- `statistics_consents` – Installation, ggf. Hörer, erlaubt/abgelehnt, Textversion, Zeitpunkt
- `bonus_content`, `gallery_comments` – für die neuen Bereiche

---

## 9. Dokumente und Stores

- **Nutzungsbedingungen – neu:** Regeln für Beiträge, was nicht erlaubt ist, Moderation, Melden,
  Sperren, Mindestalter 16, Kündigung/Löschung. Entwurf schreibt Claude, rechtliche Prüfung empfohlen.
- **Datenschutzerklärung:** neuer Abschnitt „Hörerkonto“ (Vor-/Nachname, E-Mail, Spitzname, Codes,
  Meldungen, Sperren, Ausblenden, Löschung), Statistik-Einwilligung, Kommentare
- **App Store Connect / Play Console:** Konto, E-Mail und Name mit Identität verknüpft; bei Google
  zusätzlich eine **Web-Adresse zum Löschen des Kontos**; bei Apple ein **Demo-Konto** für die Prüfer
  (mit festem Code, da Anmeldung per Mail-Code)
- Kein Login über Google/Facebook – sonst verlangt Apple zusätzlich „Mit Apple anmelden“

---

## 10. Noch offen

1. **Veröffentlichungsplan:** 1.3.7 wie geplant nach der Freigabe von 1.3.6 veröffentlichen und 2.0.0
   danach bauen – oder alles zusammen als 2.0.0? Empfehlung: erst 1.3.7, damit die fertigen Fixes nicht
   wochenlang warten.
2. **Konto löschen:** öffentliche Beiträge mitlöschen oder als „gelöschter Nutzer“ stehen lassen?
3. **Gesperrte Hörer:** weiter hören und lesen dürfen (Vorschlag) oder ganz ausgesperrt?
4. **Bestehende Rezensionen und Tipps von Gästen** bleiben unverändert stehen (Vorschlag).
5. **CarPlay-Menü** (Apple-Freigabe liegt vor) in 2.0.0 aufnehmen oder später?

**Grobe Schätzung:** Server 2–3 Tage, App 3–4 Tage, dazu Nutzungsbedingungen, Datenschutz, Store-Angaben
und Testen.
