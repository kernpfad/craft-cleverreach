# Craft Plugin Store copy — CleverReach

Paste into the [Craft Plugin Store](https://plugins.craftcms.com) developer portal. English is the store default.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-cleverreach/docs/

## Short description

GDPR-compliant CleverReach double opt-in, consent log, user sync, and optional Commerce order push — queued so checkout never waits on the API.

## Long description

**CleverReach** connects Craft CMS to CleverReach with double opt-in signup, a local consent log, Craft user linking, and optional Commerce order push plus a product catalog for My Content — so newsletter automation stays GDPR-clean without blocking the control panel or checkout.

### Double opt-in signup

Any Twig form can POST to the built-in subscribe action. Receivers are created **inactive**; CleverReach sends the confirmation mail. A consent record (email, IP, source, timestamp, consent text version) is written locally. Optional Formie binding uses the same DOI path and central credentials — not Formie’s immediate-activate connector.

### Consent, users & unsubscribe

Consent lives in `cleverreach_consentlog`, independent of CleverReach’s own log. Matching Craft users show newsletter status on the user edit screen (pending / confirmed / unsubscribed, last sync). Saving a user enqueues a debounced attribute sync — pending DOI receivers are soft-updated (`activated: false`) so profile data is not lost and never force-activated. An inbound unsubscribe webhook (shared secret) marks addresses so sync and order push skip them.

### Commerce, tags & catalog

Optional **order push** sends completed orders only for DOI-confirmed, non-unsubscribed subscribers — never creates a receiver from an order. Optional **tags** after subscribe, user sync, or a successful order push so CleverReach automations can fire. Optional **My Content** catalog lets campaign editors search live Commerce products.

### Built for production Craft sites

- OAuth client-credentials in env vars (no browser handshake)
- Group and DOI form pickers loaded live from the account
- Connection test and last-API-error banner on settings
- Import commands for users, Commerce customers, and CSV (explicit consent modes)
- Agency hook `EVENT_MODIFY_RECEIVER_PAYLOAD` (and tag hooks)
- Every outbound call on user save and order complete runs on the queue

**Requires** Craft CMS 5, PHP 8.1+, and a CleverReach OAuth app with the Client Credentials grant. Craft Commerce 5 is optional (order push and catalog).
