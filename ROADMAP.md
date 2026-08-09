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

| ID | Klasse | Item |
|---|---|---|
| CR-01 | D | Client ID/Secret via Env-Aliase |
| CR-02 | D | Connection-Test (Token holen + Account/Gruppen lesen) |
| CR-03 | D | Token-Fehler im CP sichtbar (nicht nur Log) |

### P1

| ID | Klasse | Item |
|---|---|---|
| CR-04 | B | Listen/Gruppen-Picker statt manueller IDs |
| CR-05 | D | Letzter Sync-Status am User („CleverReach: ok / Fehler …“) |
| CR-06 | D | Double-Opt-In-Status anzeigen / respektieren |

### P2

| ID | Klasse | Item |
|---|---|---|
| CR-07 | D | Inbound: Unsubscribe-Webhook / Bounce-Handling |
| CR-08 | D | Payload-Mutator Events |
| CR-09 | A | Optional Authorization-Code-Flow nur wenn Merchants es brauchen — Client Credentials Default lassen |

### Nicht tun

- Formie hard-requiren
- CleverReach UI im Craft-CP nachbauen

---

## Agent-Prompt (kopieren)

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
