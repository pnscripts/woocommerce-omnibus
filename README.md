# PN Omnibus: 30-day lowest price & honest discounts for WooCommerce

WooCommerce plugin by [PN Scripts](https://pnscripts.com) that records every price change of products and variations and shows the **lowest price in the 30 days before a discount**, as required in the EU by the Price Indication Directive 98/6/EC, Art. 6a (added by Directive (EU) 2019/2161, the "Omnibus" Directive; see also CJEU C-330/23 of 26 September 2024).

When the recorded history cannot prove the number, the plugin shows **nothing**.

> This plugin helps display prices; you remain responsible for compliance. Nothing in this repository is legal advice.

- WordPress.org slug / text domain: `pnscripts-omnibus` (not yet submitted)
- Requires: WordPress 6.5+, WooCommerce 9.0+, PHP 8.1+
- Tested with: WordPress 7.1.2, WooCommerce 11.1.2 (and the minimum WordPress 6.5.12 + WooCommerce 9.0.4)
- License: GPL-2.0-or-later, © ПН СКРИПТС ЕООД (PN Scripts)

This is the free edition (planned for WordPress.org). It has no locked features and makes no external requests.

## Features

- **Capture**: every price state of simple/external products and each variation (regular, sale, sale schedule, stored price) on every `WC_Product::save()` (product editor, quick and bulk edit, CSV import, REST API, WP-CLI, scheduled sale start/end, third-party code) and on direct price meta writes. Stored in `{prefix}pnscripts_omnibus_price_history`, only when the state changes.
- **Rules**: anchor = start of the current uninterrupted reduction (progressive reductions keep the first anchor); period = midnight N calendar days before the anchor day in the shop time zone (N ≥ 30); every price in force during the period counts; gaps, short history and unknown reduction starts give "unknown"; new-product rule (hide, or period since launch); optional perishable-goods exemption per product/category.
- **Display**: appended to WooCommerce price HTML, which covers classic templates, the Product Price block, product collections, variations (`woocommerce_available_variation`) and the Store API; shortcode `[pnscripts_omnibus_price id="…"]`; prices converted with `wc_get_price_to_display()` (tax display of the shop); hidden while the period reaches back before a tax configuration change (`Capture\TaxWatcher`) or a shop currency change.
- **Background work** (Action Scheduler): first baseline of the catalogue, repair, resume after the plugin was inactive (the inactive period becomes "unknown" for products changed meanwhile), daily retention (default 90 days, minimum 31, never deleting rows a running reduction needs).
- **Imports** (read-only): Omnibus by iWorks (post type `iw_omnibus_price_log` and `_iwo_price_*` meta), WC Price History (`{prefix}wc_price_history` table and `_wc_price_history` meta), Omnibus by iLabs (`omnibus_by_ilabs_prices_history` meta). Imports only fill the time before this plugin's own first record of each product.
- **Admin**: WooCommerce → PN Omnibus (settings with presets, coverage report, import and tools), history metabox on the product screen, perishable flags.
- **WP-CLI**: `wp pnscripts-omnibus reference <id>`, `history <id>`, `backfill [--mode=repair|baseline]`, `import <omnibus|wc-price-history|omnibus-by-ilabs>`, `prune`.
- **i18n**: `languages/pnscripts-omnibus.pot`; bundled `bg_BG`, `pl_PL`, `de_DE` (used only when no translate.wordpress.org pack is installed).

## Architecture

```
pnscripts-omnibus.php        Header, constants, PSR-4 autoloader, hooks to Lifecycle and Plugin::boot
uninstall.php                Deletes data only when the shop opted in
src/Domain/                  Pure PHP (no WordPress): PriceRecord, PriceTimeline, ReferencePriceCalculator,
                             ReferencePolicy, ReferenceResult, Money, Clock
src/Storage/                 Schema (dbDelta, versioned), HistoryRepository (prepared SQL)
src/Capture/                 PriceRecorder (save + meta hooks, dedupe, resume gaps), SourceDetector
src/Reference/               ReferenceService (product → records → calculator, cache, launch time, perishable, tax)
src/Display/                 NoticeRenderer, PriceDisplay (price HTML + variations), Shortcode
src/Jobs/                    Queue (Action Scheduler), Backfill, Retention
src/Import/                  ImportMapper (pure), Importers (readers), ImportRunner
src/Admin/                   SettingsPage, ProductPanel, CategoryFields, Labels
src/Cli/                     WP-CLI command
src/Licensing/               LicenseInterface + FreeLicense (no-op)
src/Plugin.php, Lifecycle.php, Settings.php
```

## Extension points

| Hook | Type | Purpose |
|------|------|---------|
| `pnscripts_omnibus_loaded` | action | Receives the `Plugin` service container |
| `pnscripts_omnibus_capture_record` | filter | Change or skip a record before it is stored (dynamic-pricing capture) |
| `pnscripts_omnibus_price_recorded` | action | After a record is stored (evidence log/export) |
| `pnscripts_omnibus_currency` | filter | Currency stored with a record and used to read the history |
| `pnscripts_omnibus_reference_result` | filter | Final `ReferenceResult` for a product |
| `pnscripts_omnibus_launch_time` | filter | Launch timestamp used by the new-product rule |
| `pnscripts_omnibus_display_price` | filter | Amount shown (e.g. currency conversion) |
| `pnscripts_omnibus_notice_html` | filter | Notice HTML |
| `pnscripts_omnibus_price_html` | filter | Full price HTML after the notice (badge/strikethrough recalculation) |
| `pnscripts_omnibus_presets` | filter | Rule presets |
| `pnscripts_omnibus_admin_tabs`, `pnscripts_omnibus_admin_tab_{tab}` | filter, action | Extra admin tabs |
| `pnscripts_omnibus_license` | filter | Provide a `LicenseInterface` implementation |
| `pnscripts_omnibus_now` | filter | Fixed clock (tests, demos) |

## Development

```bash
composer install
composer test                 # unit tests (PHPUnit + Brain Monkey)
composer lint                 # PHP_CodeSniffer with WPCS 3 and PHPCompatibilityWP
composer analyse              # PHPStan level 8 (phpstan-wordpress, WooCommerce stubs)

# Integration tests: real WordPress + WooCommerce on SQLite (no MySQL or Docker needed), needs WP-CLI
bin/install-wp-tests.sh 7.1.2 11.1.2      # creates tests/.wp/wordpress
composer test:integration

bin/build-zip.sh              # build/pnscripts-omnibus-<version>.zip without development files
```

`tests/manual/pnscripts-omnibus-dev.php` is a must-use plugin for **local test sites only** that adds `wp pnscripts-omnibus-dev simulate <id> <days_ago:regular[:sale]>...` to create a simulated price history. It is excluded from the release zip on purpose.

## Security

See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). "PN Scripts" and "PN Omnibus" are names of ПН СКРИПТС ЕООД and are not covered by the code licence.
