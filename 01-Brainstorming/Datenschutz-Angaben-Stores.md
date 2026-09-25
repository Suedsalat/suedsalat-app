# Datenschutz-Angaben in den Stores

Stand: 2026-09-25 · Gehört zur Datenschutzerklärung `seiten/datenschutz.html` (Abschnitt 2) und zur
iOS-Datei `04-App/ios/Runner/PrivacyInfo.xcprivacy`. Alle drei müssen dasselbe sagen — wer eines
ändert, prüft die anderen beiden mit.

Die Angaben beschreiben, was die App **heute** erhebt. Sie sind daher **jetzt** einzutragen, nicht erst
mit 1.3.7. Geändert werden können sie in beiden Konsolen jederzeit, ohne neue App-Version.

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
  Einreichen von 1.3.7 mit eintragen.

---

## 4. Erst beim Release 1.3.7

- **Altersfreigabe / Inhaltseinstufung** in beiden Stores neu beantworten: Ab 1.3.7 erscheinen
  Bewertungen sofort für alle sichtbar und werden nur noch nachträglich geprüft. Bei den Fragen zu
  nutzergenerierten Inhalten bzw. dazu, ob Nutzer Inhalte für andere sichtbar veröffentlichen können,
  also mit **Ja** antworten. Einsendungen (Fotos, Tipps) werden weiterhin vorher geprüft.
- **Datenschutzerklärung:** den Übergangsabsatz „2 h) Schriftart“ entfernen, sobald keine ältere
  App-Version mehr die Schrift von Google lädt (siehe Release-Checkliste im Testplan).
