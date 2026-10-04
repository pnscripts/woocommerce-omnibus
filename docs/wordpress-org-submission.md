# WordPress.org submission checklist: PN Omnibus

Form: https://wordpress.org/plugins/developers/add/ (log in first; the account needs 2FA).

## Before uploading

1. Create or pick the WordPress.org account that will own the plugin and turn on two-factor authentication.
2. Put that account's username in `readme.txt` → `Contributors:` (it says `pnscripts`, and no WordPress.org profile `pnscripts` exists on 2026-10-05). Commit, then rebuild the zip.
3. Build the zip: `bin/build-zip.sh` → `build/pnscripts-omnibus-1.0.1.zip`
   (absolute: `/media/petar/c8fc2986-4b79-4d7b-9a8c-e6db653915ac/DEV/Projects/pnscripts/marketplace/woocommerce-omnibus/build/pnscripts-omnibus-1.0.1.zip`).
   The version header and readme `Stable tag` must match (the script refuses otherwise).
4. Plugin Check 2.1.0 on WordPress 7.1.2 + WooCommerce 11.1.2 with that zip installed: `wp plugin check pnscripts-omnibus --include-experimental --include-low-severity-errors --include-low-severity-warnings` must print "No errors found" (it did on 2026-10-05).

## On the form

| Field | What to enter |
|-------|---------------|
| Plugin zip | `build/pnscripts-omnibus-1.0.1.zip` (about 400 KB; the zip holds one folder, `pnscripts-omnibus/`) |
| Checkboxes | Read each one and tick them yourself (permission to upload, guidelines, GPL licence, Plugin Check). They are the owner's statements. |

## Right after uploading

- The page shows the slug WordPress.org derived from `Plugin Name`: `omnibus-lowest-price-30-days-for-woocommerce-pn-omnibus`. **Use the one-time "change slug" link on that page and request `pnscripts-omnibus`.** The text domain, folder and main file all use `pnscripts-omnibus`; a slug that does not match the text domain is a review finding. If the link is gone, reply to the review email (or write to plugins@wordpress.org) before approval; it cannot change after approval.

## Notes for the review email (paste if asked)

- No external requests, tracking or licence checks. The plugin only reads data of other Omnibus plugins already in the same database (import, read-only).
- `unserialize()` in `src/Import/Importers.php` reads those plugins' stored meta with `allowed_classes => false`.
- Direct database queries are all prepared (`%i` for table names). They write only the plugin's own table `{prefix}pnscripts_omnibus_price_history`; the importers read posts/postmeta and the `wc_price_history` table of the other plugins.
- Bundled translations (bg_BG, de_DE, pl_PL) are loaded with `load_textdomain()` only when no translate.wordpress.org language pack is installed.

## After approval (SVN)

- `trunk/` = contents of the zip folder; `tags/1.0.1/` = copy of trunk.
- `assets/`: `assets/screenshots/screenshot-1.png` … `screenshot-8.png` from this repo (captions are in `readme.txt` → Screenshots). Banner (`banner-772x250.png`, `banner-1544x500.png`) and icon (`icon-128x128.png`, `icon-256x256.png`) do not exist yet; optional.
