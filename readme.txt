=== PN Omnibus – Lowest Price in 30 Days ===
Contributors: pnscripts
Tags: omnibus, lowest price, price history, discount, sale price
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows the lowest price in the 30 days before a discount, based on a complete price history. Shows nothing rather than a wrong number.

== Description ==

When a shop in the EU announces a price reduction, the prior price it shows must be the lowest price applied during at least the 30 days before the reduction (Price Indication Directive 98/6/EC, Art. 6a, added by the "Omnibus" Directive (EU) 2019/2161).

**PN Omnibus** (by PN Scripts) records every price change of your products and variations, works out that lowest prior price and prints it under the price:

> Lowest price in the 30 days before the discount: €21.00

It is built around one promise: **if the price history cannot prove the number, the plugin shows nothing.** No guesses, no "current price" fallbacks.

= Records every price change =

* Simple products, external products and **each variation** separately, regular and sale price, including the **sale schedule**.
* Every way a price is saved: product editor, quick edit, bulk edit, CSV import, REST API, WP-CLI, WooCommerce's scheduled sale start and end, and code that calls `$product->save()`. Price fields written directly to the database by other software are picked up at the end of the request.
* Stored in its own database table (not in post meta), only when the price actually changes.
* A background job (Action Scheduler) records the starting prices of your catalogue after installation. No catalogue scans on page load.
* Keeps 90 days of history by default (configurable, minimum 31) and never deletes prices that a running discount still needs.

= Calculates the prior price the way the rules describe it =

* The period ends when the current discount **started**, not today.
* **Progressive discounts** (lowering the sale price again) keep the start of the first reduction, so the prior price does not drift down to the previous sale price.
* Every price in force during the period counts, including earlier promotions and a lower regular price.
* The period is counted in calendar days in your shop's time zone and is never shorter than 30 days.
* **New products**: hide the notice (strict, default) or, where your country allows a shorter period, use the lowest price since launch.
* **Perishable goods**: optional exemption per product or per category, off by default.
* Prices are shown **with or without tax exactly like your shop shows its prices**.
* History gaps (the plugin was inactive, a price was changed outside WooCommerce) are detected and make the result "unknown".

= Shows it in classic and block themes =

* Product pages, shop and category pages, related products and product blocks (the Product Price block and product collections in block themes such as Twenty Twenty-Five).
* The selected **variation** of a variable product.
* Anywhere else with the shortcode `[pnscripts_omnibus_price]` or `[pnscripts_omnibus_price id="123"]`.
* Your own wording with `{price}`, `{days}` and `{date}` placeholders, or the translated default.

= Brings your existing history =

One-click, read-only import of the price history saved by **Omnibus — show the lowest price** (iWorks), **WC Price History for Omnibus** and **Omnibus by iLabs**. Imported prices only fill the time before PN Omnibus started recording.

= Admin tools =

* Settings under WooCommerce → PN Omnibus, with rule presets (EU strict, Bulgaria, Germany, Poland, custom).
* **Coverage report**: every product and variation on sale, the prior price shown, or why nothing is shown.
* A price history table and the computed prior price on the product edit screen.
* WP-CLI: `wp pnscripts-omnibus reference|history|backfill|import|prune`.

= Compatibility =

* WooCommerce High-Performance Order Storage (HPOS) and the Cart and Checkout blocks: declared compatible (the plugin does not touch orders or checkout).
* Multisite: each site keeps its own history.
* Translations included: Bulgarian, Polish, German.

= Important =

This plugin helps display prices; **you remain responsible for compliance**. It does not give legal advice and cannot know every national rule. The presets are starting points; check the rules of the countries you sell to.

= Privacy =

The plugin stores product prices only. It stores no personal data, sets no cookies and makes no external requests.

== Installation ==

1. Install and activate WooCommerce 9.0 or newer.
2. Upload the `pnscripts-omnibus` folder to `/wp-content/plugins/` or install the plugin from the Plugins screen, then activate it.
3. Open **WooCommerce → PN Omnibus**, choose a preset and check the display options.
4. Optional: on the **Import and tools** tab, import the history of another Omnibus plugin you used before.
5. Wait: for products that were already on sale when you installed the plugin, the notice appears once the history covers the whole period. The **Coverage report** tab tells you, product by product.

== Frequently Asked Questions ==

= Is this plugin enough to comply with the Omnibus Directive? =

No plugin can promise that. It records prices and shows the lowest prior price according to the rules you configure; you remain responsible for your prices, your promotions and how you announce them. This FAQ is not legal advice.

= Why is no notice shown for some discounted products? =

Because the history cannot prove the number yet: the product was already on sale when recording started, it was launched recently and your rules hide the notice for new products, or prices were changed while the plugin was inactive. The Coverage report shows the reason for every product.

= What happens to a progressive discount? =

If you lower a sale price again without ending the sale, the period still ends when the first reduction started, so the notice keeps the price from before the first reduction.

= Are earlier promotions counted? =

Yes. Every price that was in force during the period counts, including an earlier sale.

= Does it show prices with or without tax? =

The same way as your shop: it follows WooCommerce's "Display prices in the shop" setting. Prices are stored as entered, so after a change of the tax rates (when prices are entered without tax or shown without tax), of "Prices entered with tax" or of "Enable taxes", and after a change of the shop currency, the notice is hidden until the whole period lies after the change.

= Does it work with variable products? =

Yes. Each variation has its own history and its own prior price. The notice appears when a shopper selects a variation. Price ranges of the parent product carry no notice.

= Does it change the sale badge or the struck-through price? =

No. The free plugin adds the notice only.

= Can I place the notice somewhere else? =

Use the shortcode `[pnscripts_omnibus_price]` on a product page or `[pnscripts_omnibus_price id="123"]` anywhere, and switch off the automatic notice on product pages if you want only the shortcode.

= Does it slow down my shop? =

Prices are written only when they change, reads use an indexed table and results are cached. The catalogue is processed in the background with Action Scheduler.

= What is removed when I delete the plugin? =

Nothing, unless you tick "Delete the price history and settings when the plugin is deleted" in the settings. Keeping the history is the safer default.

= Which plugins can I import from? =

Omnibus — show the lowest price (iWorks), WC Price History for Omnibus, and Omnibus by iLabs. The import only reads their data.

== Screenshots ==

1. The notice under the price on a product page in a block theme (Twenty Twenty-Five).
2. The notice for the selected variation of a variable product in a block theme.
3. The notice for a variation in a classic theme (Storefront).
4. Shop page: products with a known prior price show the notice; the others show nothing.
5. Settings: rule presets, period, new products, perishable goods, display, texts and data retention.
6. Coverage report: every product and variation on sale with its prior price or the reason it is hidden.
7. Product edit screen: price history per variation and the computed prior price.
8. Import and tools: catalogue check, imports from other Omnibus plugins, background tasks.

== Changelog ==

= 1.0.1 =
* Direct-access guard in every PHP file (WordPress.org review). No functional change.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
Hardening only, no functional change.

= 1.0.0 =
First release.
