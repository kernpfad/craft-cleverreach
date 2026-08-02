# CleverReach

GDPR-compliant CleverReach newsletter integration for Craft CMS: double opt-in signup, attribute sync, and an optional Craft Commerce order push.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.1+
- A CleverReach account with an OAuth app (Account → Extras → REST API) using the **Client Credentials** grant
- Craft Commerce 5, only for the order push and the product catalog

## Installation

```sh
composer require kernpfad/craft-cleverreach
php craft plugin/install cleverreach
```

Installation creates the `cleverreach_consentlog` table, which holds your own record of consent, independent of CleverReach's double opt-in log.

## Configuration

1. In CleverReach, under *Account → Extras → REST API*, create an OAuth app with the **Client Credentials** grant.
2. Store the client ID and secret as environment variables:

   ```
   CLEVERREACH_CLIENT_ID="..."
   CLEVERREACH_CLIENT_SECRET="..."
   ```

3. Under *Settings → Plugins → CleverReach*, enter the variable names (`$CLEVERREACH_CLIENT_ID` / `$CLEVERREACH_CLIENT_SECRET`), the default group ID, the double opt-in form ID, and optionally the attribute mapping and the order-push switch.

There is no OAuth browser handshake. The client credentials grant authenticates the plugin server-side against the account that created the OAuth app.

## Settings

| Setting | Default | Description |
|---|---|---|
| `oauthClientId` | empty | Env var reference holding the OAuth client ID. |
| `oauthClientSecret` | empty | Env var reference holding the OAuth client secret. |
| `defaultGroupId` | `null` | CleverReach group new receivers are added to. |
| `doiFormId` | `null` | Double opt-in form used to trigger the confirmation mail. |
| `attributeMapping` | none | Craft field handle → CleverReach attribute name. |
| `enableOrderPush` | `false` | Push completed Commerce orders for existing subscribers. |
| `enableCatalog` | `false` | Expose Commerce products to CleverReach's My Content search. |
| `catalogPassword` | empty | Env var reference for the optional catalog request password. |
| `catalogImageFieldHandle` | `null` | Assets field on the product used as the item image. |
| `catalogImageTransformHandle` | `null` | Named image transform applied to that image. |
| `catalogDescriptionFieldHandle` | `null` | Field used as the item description. |

Credentials are stored as env var references, so the secrets themselves never reach `project.yaml` or version control.

## Newsletter signup

Any form can POST to the signup endpoint:

```html
<form method="post" action="/">
    {{ csrfInput() }}
    {{ actionInput('cleverreach/subscribe/subscribe') }}
    {{ redirectInput('thanks') }}

    <input type="email" name="email" required>
    <label><input type="checkbox" name="consent" value="1" required> I would like to receive the newsletter.</label>
    <input type="hidden" name="consentTextVersion" value="2026-07">

    <!-- optional extra fields, as configured in the attribute mapping -->
    <input type="text" name="fields[firstName]">

    <button type="submit">Subscribe</button>
</form>
```

The recipient is created at CleverReach as **inactive**, the double opt-in mail is triggered, and a consent record — email, IP, source, timestamp, consent text version — is written to `cleverreach_consentlog`. Activation happens at CleverReach through the confirmation link.

## Formie integration

With [Formie](https://verbb.io/craft-plugins/formie) installed, a **CleverReach (Double Opt-in)** integration appears under *Formie → Integrations → Email Marketing*, selectable in the normal field-mapping editor. Groups and fields are loaded live from the CleverReach API.

Formie ships its own built-in CleverReach integration. The difference:

| | Formie's built-in CleverReach | This plugin's CleverReach (Double Opt-in) |
|---|---|---|
| Activation | Immediate (`activated: time()`), no double opt-in | Always created as opt-in pending; activation only via the confirmation link |
| Credentials | A separate OAuth connection per form integration | The credentials configured centrally in this plugin |
| Consent record | None | Written to `cleverreach_consentlog` |

If you don't need documented proof of consent, Formie's built-in integration is fine. Use this one where consent logging and the Commerce order push have to stay consistent. Both paths — the generic endpoint and the native Formie integration — run through the same `SubscriberService` and behave identically.

## Commerce order push

With Commerce installed and the order-push switch on, every completed order (`Order::EVENT_AFTER_COMPLETE_ORDER`) is checked against the consent log. Order data — number, date, total, line items — is transmitted only when consent already exists for that address. **A new recipient is never created from an order alone.**

The automations themselves (welcome mail after a first purchase, reactivation, post-purchase) are configured as flows on the CleverReach side. This plugin supplies the data.

## Craft user linking

Each consent log entry is linked to a matching Craft user by email, whether or not the person was logged in when subscribing. The link is informational, for reporting; the foreign key uses `ON DELETE SET NULL` so the consent record survives a deleted user. It does not affect double opt-in behaviour.

Two features build on it, both always active and neither requiring Commerce:

- **Sync on user save.** Saving a Craft user transmits their attributes, through the same mapping used at signup — but only if a consent log entry already exists for them. Saving never creates a new subscription. A CleverReach outage never blocks the save; errors are logged, not thrown.
- **Newsletter status in the user profile.** Where a consent entry exists, the user edit screen's Details pane shows a *Newsletter (CleverReach)* line with the signup date and source. Nothing is shown for users without one.

## Importing existing contacts

```sh
php craft cleverreach/import/{users|customers|csv}
```

Without `--confirm` every run is a dry run: it counts, it writes nothing.

Consent handling is chosen per run through `--consentMode`; the plugin imposes no default:

| Mode | Behaviour |
|---|---|
| `require-consent` | Only contacts with demonstrable existing consent — a Craft field or CSV column — are imported, activated directly with no fresh opt-in. Everyone else is skipped. |
| `doi` | Every contact is treated as a new signup: created inactive, CleverReach sends the confirmation mail. Nobody becomes active without fresh confirmation. |
| `activate` | Every contact is activated immediately, unchecked. Additionally requires `--acceptResponsibility=1` as a deliberate confirmation that a legal basis exists outside the plugin. Nothing is verified automatically here. |

Examples:

```sh
# Craft users carrying an existing opt-in field
php craft cleverreach/import/users --consentMode=require-consent --consentField=newsletterOptIn --confirm

# All Commerce customers from completed orders, via double opt-in
php craft cleverreach/import/customers --consentMode=doi --confirm

# CSV export from a legacy system, with a freely configurable column mapping
php craft cleverreach/import/csv \
  --file=/path/legacy.csv \
  --mapping="E-Mail:email,First name:firstname,Opt-In:consent" \
  --consentMode=require-consent --confirm
```

`--mapping` is a comma-separated list of `column:target`. The targets `email` and `consent` are reserved — the email column and the consent flag column, where `1`, `true`, `yes` and `ja` count as present. Every other target becomes a CleverReach attribute as-is. `--groupId` overrides the default group. Individual failures, such as API errors, do not abort the run; they are collected and reported at the end.

## Product catalog

Exposes Craft Commerce products through CleverReach's [My Content](https://developers.cleverreach.com/mycontent/) interface, so editors can search the shop catalog inside the CleverReach editor and insert results into a campaign without copying product data by hand.

**Setup:**

1. In the plugin settings, enable the catalog and optionally set a password (as an env var), the assets field handle for the product image, an image transform, and a description field handle.
2. In CleverReach, under *My Content*, enter the product search URL:

   ```
   https://your-domain.tld/index.php?p=actions/cleverreach/catalog/search&password=YOUR_PASSWORD
   ```

   Adjust for pretty URLs as needed. The `password` parameter is only required if one is configured.

**How it works.** A single URL answers both calls CleverReach makes, distinguished by the `get` query parameter:

- `?get=filter` returns the search filters shown in the editor: a free-text field searching the product title, and a dropdown of the shop's real Commerce product types.
- `?get=search` receives the chosen filter values as POST parameters (`q`, `productTypeId`) and returns matching published products with title, live URL and price formatted in the store currency, plus the image and description when configured.

The endpoint is read-only and deliberately runs **without Craft's CSRF protection**, since CleverReach's servers call it without a Craft session. The optional password is what secures it. It is only active with Commerce installed and the switch enabled.

## Limitations

- Craft CMS 5 only.
- The catalog image path has not been exercised against a real assets volume. Verify it once with a real product image before going live.

## License

Licensed under the [MIT License](LICENSE.md).

[Legal notice](https://kernpfad.dev/en/legal-notice) · [Privacy policy](https://kernpfad.dev/en/privacy-policy) · [Terms and conditions](https://kernpfad.dev/en/terms-and-conditions)
