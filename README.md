# CleverReach für Craft CMS

DSGVO-konforme CleverReach-Newsletter-Integration für Craft CMS: Double-Opt-in-Anmeldung, Attribut-Sync und optionaler Craft-Commerce-Order-Push für CleverReach-Automation-Flows.

Umgesetzt gemäß [Feinkonzept](../docs/cleverreach/feinkonzept.md) — aktuell **Phase 1 + 2 + 3** (Grundgerüst; siehe "Status" unten).

## Voraussetzungen

- Craft CMS ^5.0.0
- Ein CleverReach-Account mit OAuth-App (Account → Extras → REST API), Grant-Typ **Client Credentials**
- Optional: Craft Commerce ^5.0.0 für den Order-Push (Baustein C) und den Kunden-Import

## Installation

```bash
composer require fipschen95/craft-cleverreach
php craft plugin/install cleverreach
```

Die Installation legt die Tabelle `cleverreach_consentlog` an (eigener DSGVO-Nachweis für erteilte Einwilligungen, unabhängig vom CleverReach-eigenen DOI-Log).

## Konfiguration

1. In CleverReach unter *Account → Extras → REST API* eine OAuth-App mit Grant-Typ **Client Credentials** anlegen.
2. Client-ID/-Secret als Umgebungsvariablen hinterlegen, z. B. in `.env`:
   ```
   CLEVERREACH_CLIENT_ID="..."
   CLEVERREACH_CLIENT_SECRET="..."
   ```
3. In Craft unter *Einstellungen → Plugins → CleverReach* die Variablennamen (`$CLEVERREACH_CLIENT_ID` / `$CLEVERREACH_CLIENT_SECRET`), die Standard-Zielgruppe (Group-ID), die Double-Opt-in-Formular-ID sowie optional das Attribut-Mapping und den Order-Push-Schalter eintragen.

Es findet **kein** OAuth-Browser-Handshake statt: Der Client-Credentials-Grant authentifiziert das Plugin direkt server-seitig gegen den CleverReach-Account, der die OAuth-App angelegt hat.

## Newsletter-Anmeldung einbinden

Jedes Formular kann per POST gegen den Anmelde-Endpoint senden:

```html
<form method="post" action="/">
    {{ csrfInput() }}
    {{ actionInput('cleverreach/subscribe/subscribe') }}
    {{ redirectInput('danke') }}

    <input type="email" name="email" required>
    <label><input type="checkbox" name="consent" value="1" required> Ich möchte den Newsletter erhalten.</label>
    <input type="hidden" name="consentTextVersion" value="2026-07">

    <!-- optionale, im Attribut-Mapping konfigurierte Zusatzfelder -->
    <input type="text" name="fields[firstName]">

    <button type="submit">Anmelden</button>
</form>
```

Ablauf: Empfänger wird bei CleverReach als **inaktiv** angelegt, die Double-Opt-in-Mail wird ausgelöst, und ein eigener Consent-Nachweis (E-Mail, IP, Quelle, Zeitstempel, Consent-Text-Version) wird in `cleverreach_consentlog` gespeichert. Die eigentliche Aktivierung erfolgt bei CleverReach über den Bestätigungslink in der DOI-Mail.

## Formie-Integration (optional, alternative zum generischen Endpoint)

Ist [Formie](https://verbb.io/craft-plugins/formie) installiert, erscheint unter *Formie → Integrationen → Email Marketing* zusätzlich **"CleverReach (Double-Opt-in)"** — auswählbar im normalen Formie-Feld-Mapping-Editor (Zielgruppe + Felder werden live aus der CleverReach-API geladen).

**Wichtig:** Formie bringt bereits eine eigene, eingebaute "CleverReach"-Integration mit. Der Unterschied:

| | Formies eingebaute "CleverReach" | Dieses Plugin: "CleverReach (Double-Opt-in)" |
|---|---|---|
| Aktivierung | Sofort (`activated: time()`), **kein** Double-Opt-in | Immer als DOI-pending angelegt, Aktivierung nur über Bestätigungslink |
| Zugangsdaten | Eigene, separate OAuth-Verbindung pro Formular-Integration | Nutzt die zentral im CleverReach-Plugin konfigurierten Zugangsdaten |
| Consent-Nachweis | Keiner | Schreibt in `cleverreach_consentlog`, wie Baustein A |

Wer keine DSGVO-Nachweispflicht-Garantien braucht, kann einfach Formies eingebaute Integration nutzen. Für alles, was mit der restlichen Plugin-Logik (Consent-Log, Commerce-Order-Push) konsistent sein soll, dieses Plugin verwenden. Beide Wege — generischer Endpoint und native Formie-Integration — landen im selben `SubscriberService` und verhalten sich identisch.

## Craft-Commerce-Order-Push (optional)

Wenn Craft Commerce installiert und der Schalter "Bestellungen an CleverReach senden" aktiv ist, wird bei jedem abgeschlossenen Order (`Order::EVENT_AFTER_COMPLETE_ORDER`) geprüft, ob für die Bestell-E-Mail bereits ein Consent-Nachweis vorliegt. Nur dann werden die Bestelldaten (Nummer, Datum, Summe, Positionen) an CleverReach übertragen — **es wird nie automatisch ein neuer Empfänger allein aufgrund einer Bestellung angelegt.**

Die eigentlichen Automations (Willkommensmail nach Erstkauf, Reaktivierung, Post-Purchase) werden CleverReach-seitig als Flows konfiguriert — das Plugin liefert nur die Datenbasis.

## Craft-User-Verknüpfung

Jeder Consent-Log-Eintrag (`cleverreach_consentlog.userId`) wird automatisch mit einem bestehenden Craft-User verknüpft, sofern die Anmelde-E-Mail zu einem Account passt (`craft\services\Users::getUserByUsernameOrEmail()`) — unabhängig davon, ob die Person beim Anmelden eingeloggt war. Die Verknüpfung ist rein informativ/für Reporting (Fremdschlüssel mit `ON DELETE SET NULL`, damit der Consent-Nachweis auch nach einer gelöschten Nutzer:in erhalten bleibt) und beeinflusst das Double-Opt-in-Verhalten nicht.

Darauf aufbauend zwei Features, die automatisch aktiv sind (kein Schalter, kein Commerce nötig):

- **Sync bei User-Save:** Wird ein Craft-User gespeichert (CP oder Frontend-Profil), werden dessen Attribute (über dasselbe Attribut-Mapping wie bei der Anmeldung) an CleverReach übertragen — **aber nur**, wenn für diesen User bereits ein Consent-Log-Eintrag existiert. Ein Speichern legt niemals eine neue Anmeldung an. CleverReach-Ausfälle blockieren dabei nie das Speichern des Profils — Fehler werden geloggt, nicht geworfen. Wie beim Commerce-Order-Push wird dabei `activated: true` gesendet (siehe `UserSyncService`); dieselbe bewusste Kompromiss-Entscheidung wie bei Baustein C.
- **Newsletter-Status im User-CP-Profil:** Existiert ein Consent-Log-Eintrag, erscheint im "Details"-Bereich der User-Bearbeitungsseite eine Zeile "Newsletter (CleverReach)" mit Anmeldedatum und Quelle. Für User ohne Consent-Eintrag wird nichts angezeigt (kein "Nicht angemeldet", um die Detailleiste bei vielen Nicht-Abonnent:innen nicht zu überfrachten).

## Import von Alt-Kontakten

`php craft cleverreach/import/{users|customers|csv}` importiert bestehende Kontakte einmalig in CleverReach. Ohne `--confirm` ist jeder Lauf ein Dry-Run (nur Zählung, keine Schreibvorgänge).

Der Consent-Umgang ist pro Lauf frei wählbar über `--consentMode`, keine feste Vorgabe des Plugins:

| Modus | Verhalten |
|---|---|
| `require-consent` | Nur Kontakte mit belegbarem Bestandsconsent (Craft-Feld bzw. CSV-Spalte) werden importiert — direkt aktiviert, kein erneutes DOI. Alle anderen werden übersprungen. |
| `doi` | Alle Kontakte werden wie Neuanmeldungen behandelt: inaktiv angelegt, CleverReach verschickt die Bestätigungsmail. Niemand wird ohne frische Bestätigung aktiv. |
| `activate` | Alle Kontakte werden sofort aktiviert, ohne Prüfung. Erfordert zusätzlich `--acceptResponsibility=1` als bewusste Bestätigung, dass außerhalb des Plugins eine Rechtsgrundlage vorliegt — das Plugin verifiziert hier nichts automatisch. |

Beispiele:

```bash
# Craft-User mit vorhandenem Opt-in-Feld "newsletterOptIn" importieren
php craft cleverreach/import/users --consentMode=require-consent --consentField=newsletterOptIn --confirm

# Alle Commerce-Kunden (aus abgeschlossenen Bestellungen) per DOI anschreiben
php craft cleverreach/import/customers --consentMode=doi --confirm

# CSV-Export aus einem Alt-System, frei konfigurierbares Spalten-Mapping
php craft cleverreach/import/csv \
  --file=/pfad/legacy.csv \
  --mapping="E-Mail:email,Vorname:firstname,Opt-In:consent" \
  --consentMode=require-consent --confirm
```

`--mapping` ist eine kommagetrennte Liste `Spaltenname:Ziel`. Die Ziele `email` und `consent` sind reserviert (E-Mail-Spalte bzw. Consent-Flag-Spalte, Werte wie `1`/`true`/`yes`/`ja` gelten als vorhanden); alle anderen Ziele werden 1:1 als CleverReach-Attribut übernommen. `--groupId` überschreibt bei Bedarf die Standard-Zielgruppe. Fehlgeschlagene Einzelkontakte (z. B. API-Fehler) brechen den Lauf nicht ab, sondern werden am Ende gesammelt gemeldet.

## Produktkatalog ("My Content", Baustein D)

Stellt Craft-Commerce-Produkte über CleverReachs [„My Content“-Schnittstelle](https://developers.cleverreach.com/mycontent/) bereit, damit Redakteur:innen im CleverReach-Editor direkt im Shop-Katalog suchen und Treffer per Klick in die Kampagne einfügen können — ohne manuelles Kopieren von Produktdaten.

**Einrichtung:**

1. In den Plugin-Settings *Produktkatalog aktivieren*, optional ein Passwort (Umgebungsvariable) sowie das Assets-Feld-Handle fürs Produktbild, einen Bild-Transform und ein Beschreibungs-Feld-Handle hinterlegen.
2. Bei CleverReach unter *Eigener Content* die Produktsuch-URL eintragen:
   ```
   https://ihre-domain.tld/index.php?p=actions/cleverreach/catalog/search&password=IHR_PASSWORT
   ```
   (Ohne `omitScriptNameInUrls`/Pretty-URLs entsprechend anpassen. Das `password` ist nur nötig, wenn eines konfiguriert ist.)

**Wie es funktioniert:** Eine einzige URL beantwortet zwei von CleverReach gesendete Aufrufe, unterschieden über den `get`-Query-Parameter:

- `?get=filter` — liefert die im Editor angezeigten Suchfilter: ein Freitext-Suchfeld (sucht im Produkttitel) und ein Dropdown mit den echten Commerce-Produkttypen des Shops.
- `?get=search` — bekommt die gewählten Filterwerte als POST-Parameter zurück (`q`, `productTypeId`) und liefert passende, veröffentlichte Produkte mit Titel, Live-URL, Preis (`getBasePrice()`, formatiert in der Store-Währung) sowie optional Bild und Beschreibung.

Der Endpoint ist bewusst nur lesend und **ohne Craft-CSRF-Schutz** (CleverReachs Server rufen ihn ohne Craft-Session auf) — Absicherung erfolgt über das optionale Passwort. Nur wirksam, wenn Craft Commerce installiert und der Schalter aktiv ist.

## Status / offene Punkte

- Grundgerüst für Baustein A (Double-Opt-in-Anmeldung), B (Attribut-Mapping) und C (Commerce-Order-Push) ist umgesetzt.
- **Praxisgetestet:** Plugin wurde in einer echten Craft-5-/Commerce-5-Installation (Composer-Path-Repository, PostgreSQL) installiert, aktiviert und durchlaufen: CP-Settings-Seite rendert und persistiert korrekt, der Subscribe-Endpoint validiert CSRF/E-Mail/Consent und ruft tatsächlich `https://rest.cleverreach.com/oauth/token.php` auf (mit Test-Credentials korrekt mit `invalid_client` abgelehnt, sauber als Fehler behandelt statt zu crashen), und die Commerce-Order-Push-Wiring lädt ohne Fataler-Fehler, wenn Commerce installiert ist. Alle in `CommerceOrderPushService` verwendeten Order-/LineItem-Properties (`totalPrice`, `salePrice`, `qty` — Yii-"virtuelle" Getter-Properties) wurden per Reflection gegen die echte Commerce-5-Klasse verifiziert.
- **Formie-Integration ebenfalls praxisgetestet:** In einer separaten Craft-5-/Formie-3.1-Installation registriert sich `CleverReachEmailMarketing` nachweislich korrekt in Formies `emailMarketing`-Integrationsliste (per Reflection gegen `Formie::$plugin->getIntegrations()->getAllIntegrationTypes()` geprüft), `fetchFormSettings()` erreicht ebenfalls den echten CleverReach-Endpoint, und die `listId`-Pflichtfeld-Validierung greift. Dabei fiel ein echter Bug auf und wurde behoben: `CleverReachApiService::getAttributes()` kollidierte mit `yii\base\Model::getAttributes()` (da `craft\base\Component` von `Model` erbt) und verursachte einen Fatal Compile Error — umbenannt zu `getReceiverAttributes()`.
- **Endpoint-Korrektur durch Recherche:** Der Receiver-Endpoint ist jetzt `POST /v3/groups/{group}/receivers/upsert` (Batch-Payload `[[...]]`) statt des ursprünglich vermuteten `POST /v3/groups/{group}/receivers` — verifiziert durch Lektüre des Quellcodes von Formies eigener, offiziell mitgelieferter CleverReach-Integration (`vendor/verbb/formie/src/integrations/emailmarketing/CleverReach.php`), die exakt dieselbe API anspricht. Der frühere "nicht verifiziert"-Hinweis zum Upsert-Verhalten ist damit durch eine glaubwürdige Quelle (nicht die offizielle CleverReach-Doku selbst, aber ein produktiv genutztes drittes Integrations-Codebase) aufgelöst.
- **Import-Command praxisgetestet:** Alle drei Quellen (`users`, `customers`, `csv`) in derselben Craft-5-/Commerce-5-Installation durchlaufen — Dry-Run-Zählung, `require-consent`-Skip-Logik, die `--acceptResponsibility`-Sperre für `activate`, und ein echter `--confirm`-Lauf (CleverReach-API korrekt erreicht, Fehler pro Kontakt gesammelt statt Abbruch, kein Consent-Log-Eintrag bei fehlgeschlagenem API-Call). Dabei fiel ein weiterer echter Bug auf und wurde behoben: Eine private Methode `run()` im Controller kollidierte mit der öffentlichen `yii\base\Controller::run()` (Fatal Compile Error) — umbenannt zu `processContacts()`.
- **Produktkatalog praxisgetestet:** Echtes Produkt mit Variante in einer Craft-5-/Commerce-5-Installation angelegt und den kompletten HTTP-Roundtrip durchgespielt (`?get=filter`, `?get=search` mit/ohne/falschem Passwort, Treffer- und Leer-Suche). Dabei fielen zwei echte Bugs auf und wurden behoben:
  1. `Variant::getPrice()` liefert Commerce 5s berechneten Catalog-Pricing-Preis (abhängig von einem Regel-Cache, kann `null` sein) — für den tatsächlich am Produkt hinterlegten Preis ist `getBasePrice()` richtig, jetzt entsprechend umgestellt.
  2. `Store::getCurrency()` liefert kein Währungscode-String, sondern ein `Money\Currency`-Objekt (stringifiziert sich unauffällig zu z. B. "USD", daher beim bloßen Anschauen der Ausgabe leicht zu übersehen) — führte zu einem Fatal Error tief in Yiis Zahlenformatierung. Jetzt explizit auf `string` gecastet.
  3. Der Endpoint schlug mit HTTP 400 fehl, weil Crafts CSRF-Schutz standardmäßig aktiv ist — CleverReachs Server senden aber keinen Session-gebundenen CSRF-Token. `CatalogController` hat jetzt `$enableCsrfValidation = false` (unkritisch, da rein lesend; Absicherung läuft über das Passwort).
- Nur Craft CMS 5 wird unterstützt (siehe "Status" oben).
- Katalog-Bildpfad (`getFieldValue()`/`Asset::getUrl()`) ist per Reflection gegen echte Craft-5-Klassen verifiziert, aber nicht mit einem echten Assets-Volume durchgespielt (Setup dafür war im Testrahmen zu aufwändig für den Nutzen) — bei Bedarf vor Produktivbetrieb einmal mit einem echten Produktbild gegentesten.
- **User-Sync/Status-Anzeige praxisgetestet, inkl. Negativpfad:** In einer echten Craft-5-Installation einen Consent-Log-Eintrag für den Admin-User angelegt, die CP-Profilseite abgerufen (Newsletter-Zeile erscheint korrekt mit Datum/Quelle) und das Profil per echtem CP-Save-Request gespeichert — der Sync griff, erreichte tatsächlich die CleverReach-API (mit Test-Credentials korrekt mit `invalid_client` abgelehnt), und der Fehler wurde geloggt statt das Speichern zu blockieren (HTTP 200, "User saved."). `ConsentLogRecord::dateCreated` ist entgegen einer möglichen Annahme kein `DateTime`-Objekt, sondern ein roher Datums-String — `Craft::$app->getFormatter()->asDate()` verarbeitet das aber korrekt, kein Bug. Der Negativpfad ließ sich zunächst wegen einer Craft-Solo-Edition-Beschränkung (nur ein User-Account erlaubt) nicht mit einem zweiten echten User durchspielen — Craft erlaubt aber auf privaten/Dev-Domains (`localhost`, `127.0.0.1`, `.test`, `.ddev.site`, …) ein kostenloses Pro-Trial ohne Lizenzschlüssel (`POST admin/actions/app/try-edition`), damit war ein zweiter User möglich: für ihn erscheint nachweislich **keine** Newsletter-Zeile, und ein echter Profil-Save löst **keinen** CleverReach-API-Call aus (Logs vor/nach dem Save verglichen, keine CleverReach-Einträge).

## Lizenz

MIT
