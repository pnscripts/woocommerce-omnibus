# WordPress.org submission checklist: PN Scripts Pricetrail

Status 2026-10-06: the first upload (1.0.1, "PN Omnibus – Lowest Price in 30 Days") was pended in pre-review. The name was too close to "Omnibus — show the lowest price" and "PN" alone is not a distinctive leading term. 1.0.2 renames the plugin to **PN Scripts Pricetrail – 30-Day Lowest Price for Sales**, slug and text domain `pnscripts-pricetrail`. Upload 1.0.2 and reply to the review email with [wordpress-org-review-reply.md](wordpress-org-review-reply.md).

Form: https://wordpress.org/plugins/developers/add/ (log in first; the account needs 2FA).

## Before uploading

1. Create or pick the WordPress.org account that will own the plugin and turn on two-factor authentication.
2. Put that account's username in `readme.txt` → `Contributors:` (it says `pnscripts`, and no WordPress.org profile `pnscripts` exists on 2026-10-05). Commit, then rebuild the zip.
3. Build the zip: `bin/build-zip.sh` → `build/pnscripts-pricetrail-1.0.2.zip`
   (absolute: `/media/petar/c8fc2986-4b79-4d7b-9a8c-e6db653915ac/DEV/Projects/pnscripts/marketplace/woocommerce-omnibus/build/pnscripts-pricetrail-1.0.2.zip`).
   The version header and readme `Stable tag` must match (the script refuses otherwise).
4. Plugin Check 2.1.0 on WordPress 7.1.2 + WooCommerce 11.1.2 with that zip installed: `wp plugin check pnscripts-pricetrail --include-experimental --include-low-severity-errors --include-low-severity-warnings` must print "No errors found" (it did on 2026-10-06 for 1.0.2).
5. The name in `Plugin Name:` and in the readme's `=== … ===` line must be identical and must not contain restricted terms such as "WooCommerce" or "WordPress" (the form rejected "…for WooCommerce…" on 2026-10-05). "WooCommerce" in the description and body text is fine. The name must start with a distinctive term ("PN Scripts Pricetrail"), not with a generic word or another plugin's name (review, 2026-10-06).

## On the form

| Field | What to enter |
|-------|---------------|
| Plugin zip | `build/pnscripts-pricetrail-1.0.2.zip` (about 400 KB; the zip holds one folder, `pnscripts-pricetrail/`) |
| Checkboxes | Read each one and tick them yourself (permission to upload, guidelines, GPL licence, Plugin Check). They are the owner's statements. |

## Right after uploading

- Because the plugin is already in review, upload 1.0.2 through the link in the review email (or the "Upload new version" option on https://wordpress.org/plugins/developers/add/), then reply to the review email with the text in [wordpress-org-review-reply.md](wordpress-org-review-reply.md) asking for the slug `pnscripts-pricetrail`.
- The slug WordPress.org derives from the new `Plugin Name` would be `pn-scripts-pricetrail-30-day-lowest-price-for-sales`; the text domain, folder and main file all use `pnscripts-pricetrail`, so the reply asks for exactly that. It cannot change after approval.

## Notes for the review email (paste if asked)

- No external requests, tracking or licence checks. The plugin only reads data of other lowest-price plugins already in the same database (import, read-only).
- `unserialize()` in `src/Import/Importers.php` reads those plugins' stored meta with `allowed_classes => false`.
- Direct database queries are all prepared (`%i` for table names). They write only the plugin's own table `{prefix}pnscripts_omnibus_price_history`; the importers read posts/postmeta and the `wc_price_history` table of the other plugins.
- Bundled translations (bg_BG, de_DE, pl_PL) are loaded with `load_textdomain()` only when no translate.wordpress.org language pack is installed.

## After approval (SVN)

- `trunk/` = contents of the zip folder; `tags/1.0.2/` = copy of trunk.
- `assets/`: `assets/screenshots/screenshot-1.png` … `screenshot-8.png` from this repo (captions are in `readme.txt` → Screenshots). Banner (`banner-772x250.png`, `banner-1544x500.png`) and icon (`icon-128x128.png`, `icon-256x256.png`) do not exist yet; optional.
