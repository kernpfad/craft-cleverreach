# Craft Plugin Store — screenshots

Upload these PNGs in the Craft Console Plugin Store listing (carousel order).

**Folder:** `store-screenshots/`

| # | File | Shows |
|---|---|---|
| 1 | `01-setup-oauth.png` | OAuth env-var fields + Test connection |
| 2 | `02-groups-forms.png` | Live group picker + DOI form picker |
| 3 | `03-attributes-tags.png` | Attribute mapping + subscribe / user-sync / order-complete tags |
| 4 | `04-commerce-catalog.png` | Order push + My Content catalog settings *(optional)* |
| 5 | `05-user-newsletter.png` | User edit screen: Newsletter (CleverReach) status *(optional)* |

**Recommended carousel:** 1 → 2 → 3 (add 4–5 if the Store allows more).

**Icon (already in the plugin package):** `src/icon.svg` (square SVG). Nav mask: `src/icon-mask.svg`.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-cleverreach/docs/

Notes:
- Screenshots are English CP UI (matches Store / plugin default language).
- Cropped from a full settings page capture.
- For a fresh full-page reshoot from craft-lab: `SHOT_LANGS=en ./shoot.sh craft-cleverreach` with `fullPage: true` on the settings shot in `shots.config.mjs`.
