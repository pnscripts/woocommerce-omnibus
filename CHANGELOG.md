# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-05

### Changed

- Plugin name is now "PN Omnibus – Lowest Price in 30 Days" (was "Omnibus Lowest Price 30 Days for WooCommerce – PN Omnibus"): WordPress.org does not allow the term "WooCommerce" in a plugin's name or permalink. Slug, text domain, folder and code are unchanged; the bundled translations of the name are updated.

### Security

- Every PHP file under `src/` exits when it is requested directly (WordPress.org plugin review: direct file access).

## [1.0.0] - 2026-10-04


### Added

- Price history capture for products and variations (regular price, sale price, sale schedule, stored price) on every product save and on direct price meta writes, in a custom table.
- Lowest prior price calculation: anchor at the start of the current reduction, progressive reductions, calendar-day period in the shop time zone (at least 30 days), earlier promotions counted, "unknown" for gaps and incomplete history.
- "Unknown" also after a shop currency change or a tax configuration change inside the period, while an inactive period is not yet checked, and when a reduction starts right after an imported price without an on-sale flag; prices written outside all hooks are marked as a gap at the next save.
- New-product rule (hide or period since launch) and optional perishable-goods exemption per product and category.
- Notice under the price in classic and block themes, for variations and in product lists; shortcode `[pnscripts_omnibus_price]`; customisable text with `{price}`, `{days}`, `{date}`.
- Settings page with rule presets, coverage report, import and tools; price history metabox on the product screen.
- Background baseline, repair, resume-after-inactivity and retention jobs (Action Scheduler).
- Read-only imports from Omnibus (iWorks), WC Price History and Omnibus by iLabs.
- WP-CLI commands `reference`, `history`, `backfill`, `import`, `prune`.
- Translations: Bulgarian, Polish, German.
- HPOS and Cart/Checkout blocks compatibility declarations.

[Unreleased]: https://github.com/pnscripts/woocommerce-omnibus/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/pnscripts/woocommerce-omnibus/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/pnscripts/woocommerce-omnibus/releases/tag/v1.0.0
