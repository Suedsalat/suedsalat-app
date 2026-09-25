# Datenschutz-Angaben in den Stores

Stand: 2026-09-25 · Gehört zur Datenschutzerklärung `seiten/datenschutz.html` (Abschnitt 2) und zur
iOS-Datei `04-App/ios/Runner/PrivacyInfo.xcprivacy`. Alle drei müssen dasselbe sagen — wer eines
ändert, prüft die anderen beiden mit.

Die Angaben beschreiben, was die App **heute** erhebt. Sie sind daher **jetzt** einzutragen, nicht erst
mit 2.0.0. Geändert werden können sie in beiden Konsolen jederzeit, ohne neue App-Version.

---

## 1. App Store Connect → deine App → „App-Datenschutz“

**„Erfassen Sie oder Ihre Drittanbieter Daten aus dieser App?“** → **Ja**

Folgende Datentypen anhaken und für jeden die drei Fragen so beantworten:

| Kategorie | Datentyp | Zweck | Mit Identität verknüpft? | Tracking? |
|---|---|---|---|---|
| Kontaktinformationen | **Name** | App-Funktionalität | **Ja** | Nein |
| Kontaktinformationen | **E-Mail-Adresse** | Werbung oder Marketing durch den Entwickler | Nein | Nein |
| Nutzerinhalte | **Fotos oder Videos** | App-Funktionalität | Nein | Nein |
| Nutzerinhalte | **Audiodaten** | App-Funktionalität | Nein | Nein |
| Nutzerinhalte | **Andere Nutzerinhalte** | App-Funktionalität | **Ja** | Nein |
| Kennungen | **Geräte-ID** | App-Funktionalität **und** Analyse | **Ja** | Nein |
| Nutzungsdaten | **Produktinteraktion** | Analyse | Nein | Nein |

Warum so:

- **Name:** bei Bewertungen Pflicht, bei Einsendungen freiwillig. Eine Bewertung wird zusammen mit
  der Installations-Kennung gespeichert, deshalb „verknüpft“.
- **E-Mail-Adresse:** nur bei der Newsletter-Anmeldung in der App. Apple zählt einen Newsletter als
  „Marketing durch den Entwickler“.
- **Andere Nutzerinhalte:** Feedback- und Bewertungstexte. Bewertungstexte hängen an der Kennung.
- **Geräte-ID:** die zufällige Installations-Kennung (Anmeldung am Server) und das Push-Token.
  „Analyse“, weil daraus die Zahl eindeutiger Hörer pro Folge gezählt wird.
- **Produktinteraktion:** geöffnete Bereiche, Wiedergaben, Hördauer-Stufen — nur als Summen,
  deshalb „nicht verknüpft“.
- **Tracking** heißt bei Apple: Daten mit Daten anderer Firmen für Werbung zusammenführen. Das tun wir
  nirgends, also überall „Nein“. „Ohne Tracking“ im Werbetext bleibt damit richtig.

Nicht ankreuzen: Standort, Kontakte, Gesundheit, Finanzen, Browserverlauf, Suchverlauf, Käufe,
Diagnose, sensible Daten, sonstige Daten.

---

## 2. Play Console → deine App → „App-Inhalte“ → „Datensicherheit“

**Erhebt oder teilt deine App erforderliche Nutzerdatentypen?** → **Ja**
**Werden alle erhobenen Nutzerdaten bei der Übertragung verschlüsselt?** → **Ja** (HTTPS)
**Welche Kontoerstellungsmethoden unterstützt deine App?** → keine (die App hat keine Konten)
**URL für Löschanfragen:** Datenschutzerklärung angeben, dort steht der Weg per E-Mail an
`info@südsalat.eu`.

**Datentypen** — jeweils **erhoben: ja**, **geteilt: nein**, **flüchtig verarbeitet: nein**:

| Kategorie | Datentyp | Erforderlich oder optional? | Zwecke |
|---|---|---|---|
| Personenbezogene Daten | **Name** | Optional | App-Funktionen |
| Personenbezogene Daten | **E-Mail-Adresse** | Optional | Entwicklerkommunikation |
| Fotos und Videos | **Fotos** | Optional | App-Funktionen |
| Fotos und Videos | **Videos** | Optional | App-Funktionen |
| Audiodateien | **Sprach- oder Tonaufnahmen** | Optional | App-Funktionen |
| App-Aktivitäten | **App-Interaktionen** | Erforderlich | Analyse |
| App-Aktivitäten | **Sonstige nutzergenerierte Inhalte** | Optional | App-Funktionen |
| Geräte- oder andere IDs | **Geräte- oder andere IDs** | Erforderlich | App-Funktionen, Analyse, Betrugsprävention, Sicherheit und Compliance |

Warum „geteilt: nein“, obwohl Firebase (Google) das Push-Token bekommt: Google zählt die Weitergabe an
einen Dienstleister, der nur in deinem Auftrag arbeitet, ausdrücklich **nicht** als „Teilen“.

„Betrugsprävention, Sicherheit und Compliance“ bei den IDs, weil die Installations-Kennung die
App-Schnittstelle vor Missbrauch schützt.

---

## 3. Store-Beschreibung

Der Satz über Tracking ist in `App-Store-Listing.md` und `Play-Store-Listing.md` bereits angepasst:

> Wir sammeln keine Daten für Werbezwecke und setzen keine Analyse- oder Tracking-Dienste von
> Drittanbietern ein. Welche Bereiche und Folgen genutzt werden, zählen wir nur als Summen auf unserem
> eigenen Server.

- **Play Store:** jetzt ändern, die Beschreibung ist jederzeit bearbeitbar.
- **App Store:** Apple lässt die Beschreibung nur zusammen mit einer neuen Version ändern — also beim
  Einreichen von 2.0.0 mit eintragen.

---

## 4. Erst beim Release 2.0.0

- **Altersfreigabe / Inhaltseinstufung** in beiden Stores neu beantworten: Ab 2.0.0 erscheinen
  Bewertungen sofort für alle sichtbar und werden nur noch nachträglich geprüft. Bei den Fragen zu
  nutzergenerierten Inhalten bzw. dazu, ob Nutzer Inhalte für andere sichtbar veröffentlichen können,
  also mit **Ja** antworten. Einsendungen (Fotos, Tipps) werden weiterhin vorher geprüft.
- **Datenschutzerklärung:** den Übergangsabsatz „2 h) Schriftart“ entfernen, sobald keine ältere
  App-Version mehr die Schrift von Google lädt (siehe Release-Checkliste im Testplan).
- **Die Angaben aus Abschnitt 1 und 2 durch Abschnitt 5 ersetzen** (Hörerkonto).

---

## 5. Ab 2.0.0: Angaben mit Hörerkonto (Stand 25.09.2026)

Mit dem Hörerkonto hängt alles, was jemand einreicht, an seinem Konto – deshalb wird fast überall
„verknüpft“ zu **Ja**. Die iOS-Datei `PrivacyInfo.xcprivacy` ist schon auf diesem Stand.

### App Store Connect → „App-Datenschutz“

| Kategorie | Datentyp | Zweck | Mit Identität verknüpft? | Tracking? |
|---|---|---|---|---|
| Kontaktinformationen | **Name** | App-Funktionalität | **Ja** | Nein |
| Kontaktinformationen | **E-Mail-Adresse** | App-Funktionalität **und** Werbung oder Marketing durch den Entwickler | **Ja** | Nein |
| Kennungen | **Nutzer-ID** | App-Funktionalität | **Ja** | Nein |
| Kennungen | **Geräte-ID** | App-Funktionalität **und** Analyse | **Ja** | Nein |
| Nutzerinhalte | **Fotos oder Videos** | App-Funktionalität | **Ja** | Nein |
| Nutzerinhalte | **Audiodaten** | App-Funktionalität | **Ja** | Nein |
| Nutzerinhalte | **Andere Nutzerinhalte** | App-Funktionalität | **Ja** | Nein |
| Nutzungsdaten | **Produktinteraktion** | Analyse | Nein | Nein |

- **Name:** Vor- und Nachname im Konto (nie öffentlich), bei Gästen der Name beim Feedback.
- **E-Mail-Adresse:** für den Anmeldecode und Mails zum Konto (App-Funktionalität) und weiterhin für
  die Newsletter-Anmeldung (Marketing).
- **Nutzer-ID:** der Spitzname (öffentlich sichtbar) und die interne Kontonummer.
- **Andere Nutzerinhalte:** Tipps, Rezensionen, Foto-Kommentare, Feedback, Meldungen.
- **Produktinteraktion:** nur mit Einwilligung und nur als Summen, deshalb „nicht verknüpft“.

**Anmeldung für die Prüfung** (App Store Connect → Version → „App-Prüfungsinformationen“):
„Anmeldung erforderlich“ anhaken, Benutzername = `REVIEW_LOGIN_EMAIL`, Passwort = `REVIEW_LOGIN_CODE`.
Als Hinweis dazuschreiben:

> The app has no passwords. Sign-in works with a 6-digit code sent by e-mail. For this review account
> the code is always the one given above. Einstellungen › Anmelden → enter the e-mail → enter the code.
> Posts made with this account are only visible to the account itself.

### Play Console → „Datensicherheit“

**Welche Kontoerstellungsmethoden unterstützt deine App?** → **Nutzername und andere Authentifizierung**
(E-Mail-Adresse + Einmal-Code per Mail, kein Passwort)
**Link zum Löschen des Kontos:** `https://www.xn--sdsalat-n2a.eu/APP/konto-loeschen.php`
**Können Nutzer einzelne Daten löschen, ohne das Konto zu löschen?** → **Ja** (eigene Kommentare
in der App, sonst per E-Mail an `info@südsalat.eu`)

| Kategorie | Datentyp | Erforderlich oder optional? | Zwecke |
|---|---|---|---|
| Personenbezogene Daten | **Name** | Optional | App-Funktionen, Kontoverwaltung |
| Personenbezogene Daten | **E-Mail-Adresse** | Optional | App-Funktionen, Kontoverwaltung, Entwicklerkommunikation |
| Personenbezogene Daten | **Nutzer-IDs** | Optional | App-Funktionen, Kontoverwaltung |
| Fotos und Videos | **Fotos** | Optional | App-Funktionen |
| Fotos und Videos | **Videos** | Optional | App-Funktionen |
| Audiodateien | **Sprach- oder Tonaufnahmen** | Optional | App-Funktionen |
| App-Aktivitäten | **App-Interaktionen** | **Optional** (nur mit Einwilligung) | Analyse |
| App-Aktivitäten | **Sonstige nutzergenerierte Inhalte** | Optional | App-Funktionen |
| Geräte- oder andere IDs | **Geräte- oder andere IDs** | Erforderlich | App-Funktionen, Analyse, Betrugsprävention, Sicherheit und Compliance |

„Optional“ bei Name, E-Mail und Nutzer-IDs, weil man die App ohne Konto als Gast nutzen kann.

**App-Zugriff** (Play Console → „App-Inhalte“ → „App-Zugriff“): „Alle oder einige Funktionen sind
eingeschränkt“ → Anleitung hinzufügen mit E-Mail und Code des Prüfkontos, gleicher Hinweistext wie bei
Apple.

**Inhaltseinstufung:** Nutzer können miteinander interagieren bzw. Inhalte teilen → **Ja**
(Rezensionen und Kommentare erscheinen sofort, Melden und Ausblenden sind eingebaut).
