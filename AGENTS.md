# AGENTS.md

This repository is part of the DEV workspace and uses the shared **AI Brain** (`DEV/ai-brain`).

1. Read the brain router first: [`../../../../ai-brain/AGENTS.md`](../../../../ai-brain/AGENTS.md)
2. Then this repo's profile once it exists: `../../../../ai-brain/projects/marketplace/woocommerce-omnibus.md` (not created yet).
3. Rules written in this repository (this file, README.md, repo docs) are **project rules** and override generic brain knowledge.

Project rules:

- Plugin name: "PN Scripts Pricetrail – 30-Day Lowest Price for Sales". Slug, text domain, folder and main file: `pnscripts-pricetrail` (renamed from `pnscripts-omnibus` in 1.0.2 after the WordPress.org review). Code prefix stays `pnscripts_omnibus` / `PNSCRIPTS_OMNIBUS_` / `Pnscripts\Omnibus` (option names, hooks, DB table).
- Free edition for WordPress.org: GPL-2.0-or-later, no locked features, no external requests, no licence checks.
- "Show nothing rather than a wrong number": any change to the reference rules needs unit tests in `tests/Unit/ReferencePriceCalculatorTest.php`.
- Before committing: `composer test`, `composer test:integration`, `composer lint`, `composer analyse`.
