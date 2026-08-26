# P1 Deltas — Soft-Sync, Sync-Tabelle, Forms-Picker

**Package:** `kernpfad/craft-cleverreach`  
**Branch:** `cursor/p1-deltas-soft-sync-forms-sync-table-2610`  
**Date:** 2026-08-26

## Context

P1 (CR-04–06) and P2 (CR-07–08) are already merged on `main`. This spec covers three agreed deltas that align the shipped behavior with the design decisions from brainstorming:

| Decision | Current `main` | Target |
|---|---|---|
| CR-06 = **C** Soft-Sync | Pending receivers are skipped entirely (no attribute push) | Upsert attributes with `activated: false` while pending |
| CR-05 = **B** Own table | Sync fields live on `cleverreach_consentlog` | New `cleverreach_user_sync` table; consent log stays consent-only (+ `unsubscribedAt`) |
| CR-04 = **C** Groups + Forms + fallback | Groups picker only; `doiFormId` is a text field | Groups **and** DOI forms pickers; text-field fallback when API fails |

**Out of scope:** Changes to CR-07 webhooks, CR-08 payload events, or consent-log semantics as a consent proof. Those stay as-is unless separate follow-ups find gaps.

---

## Architecture

### CR-06 Soft-Sync

Add `CleverReachApiService::updateReceiverAttributes(int $groupId, string $email, array $attributes = []): array` that calls the existing private `upsertReceiver(..., activated: false, ...)`.

`UserSyncService::syncUser()`:

1. Skip drafts/revisions / null email / no consent / `unsubscribedAt` set / no groupId (unchanged).
2. `getReceiver(groupId, email)`.
3. If receiver exists and `activated !== true` (pending): call `updateReceiverAttributes`; record sync `ok` with `doiConfirmed = false`.
4. If confirmed (or receiver missing but consent exists — treat as activate path for re-subscribe edge cases): call `activateReceiver`; record sync `ok` with `doiConfirmed = true`.
5. On throwable: log + record sync `error` (do not break User save).

**Order push:** Keep requiring an existing consent and skipping unsubscribed. Additionally gate on CleverReach confirmation (`getReceiver` activated) before `pushOrderToReceiver` — orders must not force-activate a pending DOI receiver. No soft-push of orders while pending.

### CR-05 Sync table

New table `{{%cleverreach_user_sync}}`:

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `userId` | int, unique, not null | FK → `users.id` ON DELETE CASCADE |
| `status` | string(10) | `ok` \| `error` |
| `message` | text, null | Error message when status=error |
| `doiConfirmed` | boolean, null | Last known CR activation flag at sync time |
| `dateCreated` / `dateUpdated` / `uid` | standard Craft | |

New `UserSyncRecord` + thin helper (either methods on `UserSyncService` or a small dedicated component registered on the plugin) for upsert-by-`userId` and fetch-for-metadata.

Migration:

1. Create `cleverreach_user_sync`.
2. Backfill from consent rows where `userId` IS NOT NULL and any of `lastSyncStatus` / `lastSyncAt` / `doiConfirmedAt` is set (latest consent per user wins).
3. Drop from `cleverreach_consentlog`: `lastSyncStatus`, `lastSyncAt`, `lastSyncError`, `doiConfirmedAt`.
4. Keep `unsubscribedAt` on the consent log.
5. Update `Install.php` for fresh installs: consent table without the dropped columns; create sync table.

`Plugin` user metadata: read confirmation + last sync from `cleverreach_user_sync` (by user id); consent + unsubscribe still from consent log.

### CR-04 Forms picker + fallback

- `CleverReachApiService::getForms(): array` → `GET forms`.
- New CP controller action mirroring `GroupsController` (e.g. `cp/FormsController::actionIndex`).
- Settings UI: select + “Load forms” for `doiFormId`; same pattern as groups.
- **Fallback:** If load fails (or before first successful load), show a text/number input prefilled with the current ID so settings remain editable without a working token. Groups picker gets the same fallback behavior for consistency.

---

## Data flow

```
User::EVENT_AFTER_SAVE
  → UserSyncService::syncUser
       → consent + unsubscribe gates
       → getReceiver
       → pending? updateReceiverAttributes(activated:false)
         : activateReceiver(activated:true)
       → upsert cleverreach_user_sync

User::EVENT_DEFINE_METADATA
  → consent (subscribed since / unsubscribed)
  → user_sync (confirmation + last sync)

Settings save
  → defaultGroupId / doiFormId from select or fallback text
```

Errors never abort Craft User save or order completion; they are logged and (for sync) persisted on the sync row.

---

## Testing

Unit tests (PHPUnit, no Craft app bootstrap where practical):

1. **Soft-sync decision** — pure helper or service method under test with mocked API: pending → attributes path / confirmed → activate path / unsubscribed → no API write.
2. **Sync record mapping** — status/message/`doiConfirmed` written as expected.
3. **Picker normalization** — map API group/form arrays to `{id, name}` for the CP JSON response (shared helper if useful).

Integration/migration: covered by the content migration + Install schema alignment; no full Craft bootstrap required for unit suite.

---

## Docs

- `ROADMAP.md` — note CR-04/05/06 deltas (soft-sync, sync table, forms picker).
- `CHANGELOG.md` — under Unreleased / next version.
- `README.md` — settings picker + soft-sync / user metadata behavior briefly.

---

## Explicit non-goals

- Re-implementing CR-07 / CR-08.
- Changing consent-log purpose (IP, source, consent text version remain the legal proof).
- Authorization-Code OAuth (CR-09).
- Formie UI redesign beyond existing group fetch.
