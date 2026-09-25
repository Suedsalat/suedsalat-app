# Konzept Version 2.0.0 – Gast und registrierter Hörer

Stand: 2026-09-25 · Status: **Alle Entscheidungen getroffen – bereit zum Bauen**

**Veröffentlichung:** Es gibt kein eigenes 1.3.7. Alles, was für das gesammelte Release gebaut ist,
geht zusammen mit dem Hörerkonto als **2.0.0 (Android-Code 18)** raus, am Ende der Testphase. Bis dahin
wird 2.0.0 vorbereitet.

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
- **Konto löschen** – direkt in der App, Pflicht bei Apple und Google. Standard: **30 Tage Rückkehrfrist**,
  wahlweise **sofort endgültig** (Einzelheiten in Abschnitt 10, Punkt 7). Im Löschvorgang wird
  **angeboten**, die eigenen öffentlichen Beiträge **mitzulöschen** – Standard ist: nur das Konto.
  Bleiben Beiträge stehen, erscheinen sie ohne Spitznamen als **„Ehemaliges Mitglied“**: Der Spitzname
  wird dadurch wieder frei, und niemand kann später unter demselben Namen scheinbar die alten Beiträge
  fortsetzen.
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

**Sperren** (nur Thorsten): Ein gesperrter Hörer wird praktisch zum Gast – er kann weiter hören,
alles lesen und schriftliches allgemeines Feedback oder Fragen senden, aber nichts mehr einreichen
(keine Tipps, Fotos, Rezensionen, Kommentare) und nicht mehr melden.

Wann sperren: nur als letzter Schritt, wenn jemand wiederholt beleidigt, Werbung oder Spam postet,
unangemessene oder fremde Fotos hochlädt, andere Hörer belästigt oder sich als jemand anderes ausgibt.
Empfohlene Reihenfolge: Beitrag löschen → Hinweis per Mail an den Hörer → erst dann sperren.
Apple und Google verlangen die Möglichkeit; sie soll aber die Ausnahme bleiben.

**Nutzer ausblenden:** Jeder registrierte Hörer kann einen anderen für sich ausblenden, dessen Beiträge
sieht er dann nicht mehr. Apple erwartet das für Apps mit Nutzerbeiträgen.

**Wortfilter:** Offensichtliche Beleidigungen werden vor dem Veröffentlichen abgefangen.

Apple und Google moderieren nichts in der App. Sie verlangen nur, dass diese Werkzeuge da sind und
genutzt werden (Apple-Richtlinie 1.2, Google-Richtlinie zu nutzergenerierten Inhalten).

---

## 6. Weitere Inhalte von 2.0.0

Alles von der bisherigen Liste, für beide Rollen, soweit nicht anders vermerkt:

- **Alles aus dem gesammelten Release** (Zurück-Geste, Galerie wischen, Videos, „Tipp von …“,
  eingebettete Schrift, Namenspflicht, Rezensionen sofort sichtbar – siehe `Testplan-Release.md`).
  Das bleibt das Fundament; bestehende Rezensionen und Tipps bleiben unverändert stehen.
- **CarPlay-Menü** mit Folgenliste und Kapiteln, gleichwertig zu Android Auto (Apple-Freigabe liegt
  seit 2026-09-15 vor) – Apple und Android sollen gleich sein.
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
| Name automatisch bei Beiträgen | `submitted_by_name`, „Tipp von …“, Namenspflicht (fertig gebaut) | Name aus dem Konto statt aus dem Formular |
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

## 10. Entscheidungen (2026-09-25)

1. **Veröffentlichung:** kein eigenes 1.3.7 – alles zusammen als 2.0.0 am Ende der Testphase, bis dahin
   wird vorbereitet.
2. **Konto löschen:** standardmäßig nur das Konto; im Löschvorgang wird angeboten, die eigenen Beiträge
   mitzulöschen. Verbleibende Beiträge als „Ehemaliges Mitglied“ (Vorschlag, siehe Abschnitt 4).
3. **Gesperrte Hörer:** hören und lesen weiter, dürfen wie Gäste nur schriftliches allgemeines Feedback
   oder Fragen senden.
4. **Bestehendes bleibt das Fundament**, vorhandene Rezensionen und Tipps bleiben unverändert.
5. **CarPlay-Menü kommt mit in 2.0.0**, gleichwertig zu Android Auto.

6. **„Ehemaliges Mitglied“ bestätigt.** Wer sein Konto löscht, erscheint bei stehengebliebenen
   Beiträgen nicht mehr mit Spitznamen. Eine **Neuregistrierung** mit derselben E-Mail ist jederzeit
   möglich – als neues Mitglied; der alte Spitzname nur, wenn er noch frei ist; alte Beiträge werden
   nicht wieder zugeordnet.

7. **Konto löschen mit 30 Tagen Rückkehrfrist, wahlweise sofort endgültig:**
   - **Standard – 30 Tage Rückkehrfrist:** Das Konto wird sofort stillgelegt. Der Spitzname ist nirgends
     mehr zu sehen, Beiträge zeigen „Ehemaliges Mitglied“, die Person ist auf allen Geräten abgemeldet.
     Wer sich innerhalb von 30 Tagen mit derselben E-Mail wieder anmeldet, bekommt alles zurück: Konto,
     Spitzname, Zuordnung der Beiträge. Nach 30 Tagen löscht der Server alles endgültig.
   - **Wahlweise „Sofort endgültig löschen“:** Konto, Name, E-Mail und die Verknüpfungen werden sofort und
     unwiderruflich gelöscht, auch aus dem Admin-Bereich.
   - **Zwei getrennte Häkchen im Lösch-Dialog**, beide standardmäßig aus:
     - ☐ **Meine Texte löschen** – Kommentare, Rezensionen, Veranstaltungs-, Film- und Locationtipps
     - ☐ **Meine Fotos löschen** – Fotos und Videos in der Galerie

     Überschneidung bei Tipps mit eingereichtem Bild: *nur Fotos* angehakt → der Tipp bleibt als
     „Ehemaliges Mitglied“, sein Bild wird entfernt; *nur Texte* angehakt → der Tipp verschwindet samt
     Bild. Was nicht angehakt ist, bleibt ohne Namen als „Ehemaliges Mitglied“ stehen.
   - Bei der Rückkehrfrist wird Angehaktes sofort ausgeblendet und erst nach 30 Tagen gelöscht (bei
     Rückkehr wieder sichtbar); bei „sofort endgültig“ sofort gelöscht.
   - **Nicht öffentliche Nachrichten** (allgemeines Feedback, Fragen): bei der Rückkehrfrist bleiben sie
     im Admin-Bereich, nach Ablauf ohne Namen („Ehemaliges Mitglied“); bei „sofort endgültig“ werden sie
     mitgelöscht (Vorschlag, bis Thorsten etwas anderes sagt).
   - **Rechtliche Voraussetzungen, dass Unangehaktes stehen bleiben darf:** keinerlei Verknüpfung mehr
     zum alten Konto oder Gerät; Nutzungsrecht über die Kontolöschung hinaus in den
     Nutzungsbedingungen; Hinweis im Lösch-Dialog und in der Datenschutzerklärung. Einzelne Beiträge, die
     die Person trotzdem erkennbar machen (vor allem Fotos, auf denen sie zu sehen ist), auf Anfrage
     per Mail löschen.
   - **Im Admin-Bereich** stehen Konten in der Rückkehrfrist sichtbar als *„Löschung vorgemerkt –
     Rückkehrfrist bis TT.MM.JJJJ“*, samt gewählter Option zu den Beiträgen.
   - Der Lösch-Dialog erklärt beide Wege in klaren Worten; die Datenschutzerklärung nennt die 30 Tage.

Alle Punkte sind entschieden – das Konzept ist bereit zum Bauen.

**Grobe Schätzung:** Server 2–3 Tage, App 3–4 Tage, dazu Nutzungsbedingungen, Datenschutz, Store-Angaben
und Testen.
