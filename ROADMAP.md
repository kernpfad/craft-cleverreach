# CleverReach — Roadmap & Agent-Prompt

**Package:** `kernpfad/craft-cleverreach`  
**Handle:** typisch `cleverreach`

## Ist-Stand (kurz)

- OAuth Client Credentials (server-side, kein Browser-Authorize)
- Newsletter / User-Profil-Integration
- Optional Commerce-Bezüge
- Credentials bisher oft in Settings / Lab-`.env`

---

## Backlog

### P0

| ID | Klasse | Item | Status |
|---|---|---|---|
| CR-01 | D | Client ID/Secret via Env-Aliase | ✅ war bereits umgesetzt (`Settings::getOauthClientId()`/`getOauthClientSecret()` via `App::parseEnv()`) |
| CR-02 | D | Connection-Test (Token holen + Account/Gruppen lesen) | ✅ erledigt — `php craft cleverreach/test` + CP-Button (`CleverReachApiService::testConnection()`, ruft `getGroups()`) |
| CR-03 | D | Token-Fehler im CP sichtbar (nicht nur Log) | ✅ erledigt — letzter Fehler (Message + Timestamp) im Cache, Anzeige oben auf der Settings-Seite |

### P1

| ID | Klasse | Item | Status |
|---|---|---|---|
| CR-04 | B | Listen/Gruppen-Picker statt manueller IDs | ✅ erledigt — `<select>` auf der Settings-Seite, live befüllt via `GroupsController::actionIndex()` (`GET /groups`), Refresh-Button |
| CR-05 | D | Letzter Sync-Status am User („CleverReach: ok / Fehler …“) | ✅ erledigt — `lastSyncStatus`/`lastSyncAt`/`lastSyncError` auf `cleverreach_consentlog`, angezeigt im User-Metadata-Panel |
| CR-06 | D | Double-Opt-In-Status anzeigen / respektieren | ✅ erledigt — `UserSyncService` prüft echten CleverReach-Bestätigungsstatus via `getReceiver()`, aktiviert pending Receiver nicht zwangsweise; `doiConfirmedAt` gecacht und angezeigt |

### P2

| ID | Klasse | Item | Status |
|---|---|---|---|
| CR-07 | D | Inbound: Unsubscribe-Webhook / Bounce-Handling | ✅ erledigt — `actions/cleverreach/webhook/unsubscribe`, Secret-gated, setzt `unsubscribedAt`, feuert `EVENT_RECEIVER_UNSUBSCRIBED`; `UserSyncService`/`CommerceOrderPushService` überspringen unsubscribed Adressen |
| CR-08 | D | Payload-Mutator Events | ✅ erledigt — `Plugin::EVENT_MODIFY_RECEIVER_PAYLOAD`, feuert vor jedem Receiver-Upsert |
| CR-09 | A | Optional Authorization-Code-Flow nur wenn Merchants es brauchen — Client Credentials Default lassen | ⏭️ bewusst nicht umgesetzt — die Formulierung selbst ist konditional ("nur wenn Merchants es brauchen"); kein konkreter Bedarf für einen Browser-Authorize-Flow ist aufgetaucht, Client Credentials bleibt Default |

### Nicht tun

- Formie hard-requiren
- CleverReach UI im Craft-CP nachbauen

---

## Agent-Prompt (kopieren)

P0–P2 sind komplett erledigt (CR-01 bis CR-08); CR-09 bewusst nicht umgesetzt, siehe oben. Roadmap ist abgeschlossen — das folgende Prompt dokumentiert nur noch den ursprünglichen P0-Auftrag.

```markdown
Du arbeitest im Repo `kernpfad/craft-cleverreach` (Craft 5 Plugin).

## Ziel
P0: Env-Secrets, Connection-Test, sichtbare Token-/API-Fehler (CR-01–CR-03).

## Kontext
- Auth: OAuth2 Client Credentials gegen CleverReach
- Kein Browser-Handshake — so belassen
- Settings unter Settings → Plugins → CleverReach
- Soft-Deps und bestehende Service-Struktur beibehalten

## Anforderungen
1. Settings-Felder akzeptieren `$CLEVERREACH_CLIENT_ID` / `$CLEVERREACH_CLIENT_SECRET`
2. `php craft cleverreach/test` (Handle ggf. anpassen) holt Token und macht einen Read-Call
3. Bei Auth-Fehler: Craft-Log + CP-Flash/Notice mit sanitisierter Meldung (keine Secrets)

## Qualitätsregeln
- strict_types, bestehende DI/Services
- Tests für Config-Parsing / Client-Fehlerpfade
- README + CHANGELOG
- Keine echten Secrets committen
```
