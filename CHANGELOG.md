# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-04

Not yet published.

### Added

- Price history capture for products and variations (regular price, sale price, sale schedule, stored price) on every product save and on direct price meta writes, in a custom table.
- Lowest prior price calculation: anchor at the start of the current reduction, progressive reductions, calendar-day period in the shop time zone (at least 30 days), earlier promotions counted, "unknown" for gaps and incomplete history.
- New-product rule (hide or period since launch) and optional perishable-goods exemption per product and category.
- Notice under the price in classic and block themes, for variations and in product lists; shortcode `[pnscripts_omnibus_price]`; customisable text with `{price}`, `{days}`, `{date}`.
- Settings page with rule presets, coverage report, import and tools; price history metabox on the product screen.
- Background baseline, repair, resume-after-inactivity and retention jobs (Action Scheduler).
- Read-only imports from Omnibus (iWorks), WC Price History and Omnibus by iLabs.
- WP-CLI commands `reference`, `history`, `backfill`, `import`, `prune`.
- Translations: Bulgarian, Polish, German.
- HPOS and Cart/Checkout blocks compatibility declarations.

[Unreleased]: https://github.com/pnscripts/woocommerce-omnibus/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/pnscripts/woocommerce-omnibus/releases/tag/v1.0.0
