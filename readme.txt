=== ProfitGuard for WooCommerce ===
Contributors: truepotato
Tags: woocommerce, profit, margin, cost of goods, shipping
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce cost and carrier data into actionable margin and shipping-profit insights. All analysis runs locally in your own WordPress installation.

== Description ==

WooCommerce tells you what you sold. It does not tell you what you kept.

ProfitGuard analyses your products and orders and reports where the money is
going: products priced below the margin you were aiming for, products selling
below what they cost you, supplier costs that went up while the price stayed
put, and orders where the carrier charged more than the customer paid.

Everything runs inside your own WordPress installation. There is no account to
create, no API key, no external service, and no subscription.

= It uses the cost data you already have =

Since WooCommerce 10.3, Cost of Goods Sold is part of WooCommerce itself,
though it is switched off by default under WooCommerce -> Settings -> Advanced
-> Features. ProfitGuard does not add a competing cost field. It reads
whichever cost data your store already holds:

* **WooCommerce's own Cost of Goods Sold field**, when you have the feature
  enabled. ProfitGuard reads it, and imports write back through it, so you see
  one cost in the product editor rather than two that disagree. Variation costs
  that inherit from the parent product, or add to it, are resolved the same way
  WooCommerce resolves them, so ProfitGuard's figures reconcile with
  WooCommerce's own analytics instead of quietly differing.
* **Costs stored by other cost-of-goods plugins**, read only. ProfitGuard never
  writes to another plugin's data.
* **Costs you import yourself**, when there is nothing else to read.

= What it checks =

**Margins.** Every product and variation with a price and a cost is measured
against a target gross margin you set. ProfitGuard shows the current margin, the
markup, the profit per unit, and the exact price that would reach your target.

**Products with no cost.** Cost of Goods Sold is off by default and rarely
filled in for a whole catalogue, so most stores have costs for some products
and not others. ProfitGuard tells you exactly how many are missing, and reports
coverage separately, rather than quietly leaving them out of the figures or
counting an absent cost as zero.

**Shipping.** WooCommerce records what your customer paid for shipping. It
cannot know what your carrier eventually billed you - that arrives weeks later
on an invoice, with fuel surcharges and weight corrections that did not exist at
checkout. Import a carrier invoice as CSV and ProfitGuard compares the two per
order, and flags duplicate charges and rows that matched no order.

= What it does that a cost field does not =

Capturing a cost is now something WooCommerce and several plugins all do.
ProfitGuard is the layer above that:

* margin analysis against a target gross margin you choose
* recommended selling price, markup and profit per unit
* findings for low margin, selling below cost, missing cost, sale-price margin
  risk and supplier cost increases
* cost coverage and missing-cost detection, reported separately from the score
* carrier cost import matched to orders
* order-level shipping profitability, charged against actual carrier cost
* duplicate carrier charges and carrier rows that matched no order
* the ProfitGuard Score, with the share of your store it covers stated beside it
* every finding prioritised by the amount of money at stake

= What it will not do =

It will not invent a number. If a product has no cost, ProfitGuard says so
instead of showing a margin. If an order has no imported carrier cost,
ProfitGuard says so instead of estimating a shipping loss. Every figure is
labelled with what kind of claim it is - a confirmed calculation, a difference
evidenced by two documents you supplied, or missing data.

The ProfitGuard Score works the same way. Categories with no data are left out
of the score rather than scored zero, and coverage is reported separately, so a
score of 78 across 34% of your orders is never presented as a score across all
of them.

It will not change a price, a product, or an order. It writes a cost only when
you confirm an import, and an import that would replace a cost already held in
WooCommerce's own field shows you the current and the new value and asks first.

= Free, and staying free =

Everything described above is in this plugin, permanently, with no limits on the
number of findings, no trial period, and no feature that stops working. There is
no upsell inside the plugin.

= Privacy =

ProfitGuard's analysis runs inside your WordPress installation. This plugin
makes no external HTTP requests, contains no analytics or telemetry of any kind,
and sends no store data anywhere.

== Installation ==

1. Install and activate WooCommerce if you have not already.
2. Upload the plugin through Plugins → Add New → Upload Plugin, or install it
   from the WordPress plugin directory.
3. Activate it.
4. Go to WooCommerce → ProfitGuard.
5. Set your target gross margin under Settings (30% is a common starting point).
6. Press **Run Profit Scan**.

To analyse shipping you will also need a carrier invoice as a CSV with an order
number and an actual shipping cost. Import it under WooCommerce → ProfitGuard →
Import data.

== Frequently Asked Questions ==

= Why does it say most of my products have no cost? =

Because a cost has to come from somewhere, and nothing has recorded one yet.
WooCommerce does have a Cost of Goods Sold field since version 10.3, but it is
switched off by default, and enabling it does not fill it in. Turn it on under
WooCommerce -> Settings -> Advanced -> Features and ProfitGuard will read and
write it; or import costs from a CSV with a SKU column and a cost column under
Import data. ProfitGuard also reads costs already stored by several common
cost-of-goods plugins, so you may not have to re-enter them.

= Does it work with WooCommerce's built-in Cost of Goods Sold? =

Yes, and it prefers it. With the feature enabled ProfitGuard reads the native
cost, and an import writes through WooCommerce's own API, so there is one cost
field in the product editor rather than a second private one beside it.
Variation costs are resolved the way WooCommerce resolves them - inherited from
the parent product, replacing it, or added to it - so ProfitGuard's margins
reconcile with WooCommerce's own analytics.

With the feature disabled, ProfitGuard does not write into it. It keeps its own
cost data and tells you the setting exists and what turning it on would give
you.

= Does ProfitGuard add another cost field? =

No. Adding a fourth place a cost might live is the opposite of useful.
ProfitGuard reads what your store already has, in this order: WooCommerce's own
field when enabled, then a cost you imported through ProfitGuard, then a known
third-party cost-of-goods key. Third-party keys are read only.

= Can it work out my shipping losses on its own? =

Only partly. WooCommerce knows what the customer paid for shipping and has no
way of knowing what your carrier later charged you. Import one carrier invoice
and ProfitGuard does the rest. Until then it reports "no carrier cost imported"
rather than estimating.

= Does it send my data anywhere? =

No. The plugin makes no external requests at all.

= Does it change anything in my store? =

It writes a cost to a product only when you confirm an import, and it never
changes a price, a product, or an order.

= Will it slow my store down? =

The scan runs in background batches through Action Scheduler, which WooCommerce
already includes, and only in the admin. Nothing runs on your shop front end.

= Does it support High-Performance Order Storage? =

Yes. ProfitGuard reads orders only through the WooCommerce CRUD API, so it works
identically whether your store uses HPOS or the legacy posts tables, and it
declares HPOS compatibility.

= Can I import XLSX? =

Not in this version. Reading XLSX needs either the PHP `zip` extension, which is
not present on every host, or a large bundled spreadsheet library. Every
spreadsheet program exports CSV in two clicks, so CSV is what the plugin
supports rather than shipping a dependency that fails on some hosts.

= Which PHP versions are supported? =

The test suite is run on PHP 7.4, 8.1, 8.2, 8.3 and 8.4, and passes identically
on all of them. There are no deprecation notices on PHP 8.4.

= What happens to my data if I delete the plugin? =

Nothing is deleted unless you ask for it. Deactivating never removes data.
Deleting the plugin removes it only if you ticked "Delete all ProfitGuard data"
in Settings first.

= Which currencies work? =

Your WooCommerce store currency. Imported rows in a different currency are
rejected rather than converted, because ProfitGuard has no exchange rate and
will not invent one.

== Screenshots ==

1. The ProfitGuard dashboard: the score, how much of your store it covers, profit health and shipping health.
2. Product margin findings, sorted by the amount at stake, with the current price, the price that would reach your target, and the difference.
3. Shipping findings: what each customer paid for shipping against what the carrier actually billed, including duplicate carrier charges.
4. Importing product costs from CSV. The detected column mapping, and the current and new cost for every row, are shown before anything is saved.
5. Importing a carrier invoice. Optional columns you do not have are marked "not in this file" rather than guessed.
6. Settings: target gross margin, scan history retention, store currency, and what happens on uninstall.

== Changelog ==

= 1.0.0 =
* First release.
* Reads WooCommerce's own Cost of Goods Sold field when the feature is enabled,
  and writes imported costs back through it, so there is one cost field in the
  product editor rather than a second private one.
* Variation costs resolve the way WooCommerce resolves them - inherited from the
  parent, replacing it, or added to it - so figures reconcile with WooCommerce's
  own analytics.
* Nothing is written into Cost of Goods Sold while the feature is disabled; the
  plugin says the setting exists and keeps using its own cost data.
* Cost precedence, where a store has more than one source: WooCommerce's native
  field when enabled, then a cost imported through ProfitGuard, then a known
  third-party cost-of-goods key. A store holding both a native cost and an older
  ProfitGuard cost reports the native one, because that is the value shown in the
  product editor.
* The cost import preview shows the current and the new value for every row, and
  refuses to replace a cost already held in WooCommerce's own field unless you
  confirm it.
* Margin analysis for products and variations against a target gross margin.
* Recommended selling price, markup and profit per unit.
* Findings for low margin, critical margin, selling below cost, missing cost,
  sale-price margin risk and supplier cost increases.
* Product cost import from CSV, with column mapping and a preview before saving.
* Carrier cost import from CSV, matched to orders, with duplicate detection.
* Shipping profit and loss per order, and unmatched carrier rows.
* ProfitGuard Score with coverage reported separately.
* CSV export of all findings.
* Background scanning via Action Scheduler.
* High-Performance Order Storage compatible.
* Verified on PHP 7.4, 8.1, 8.2, 8.3 and 8.4.

== Upgrade Notice ==

= 1.0.0 =
First release.

== Privacy ==

ProfitGuard processes data that is already in your WordPress database: products,
their prices and costs, and orders with their totals and shipping charges. It
also processes CSV files you choose to upload.

All of that processing happens on your own server. The plugin makes no external
HTTP requests, uses no third-party service, and contains no analytics, tracking
or telemetry.

ProfitGuard stores:

* Plugin settings, in the WordPress options table.
* A cost per product, in product meta.
* Findings, imported carrier rows, and scan history, in three custom database
  tables prefixed `profitguard_`.

Uploaded CSV files are parsed and discarded; the file itself is never written to
your uploads directory.

If you enable "Delete all ProfitGuard data" in Settings, deleting the plugin
removes all of the above. Otherwise the data is left in place.
