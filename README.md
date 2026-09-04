# ProfitGuard for WooCommerce

**Turn WooCommerce cost and carrier data into actionable margin and shipping-profit insights.**

WooCommerce tells you what you sold. It does not tell you what you kept.

ProfitGuard reads the cost data your store already holds, measures every product
against the gross margin you were aiming for, compares what your carrier billed
against what the customer actually paid, and ranks what to fix by the amount of
money at stake.

All analysis runs inside your own WordPress installation. There is no account,
no API key, no external service, and no subscription.

[**Download the latest release →**](https://github.com/kristijankopacevic/profitguard-for-woocommerce/releases/latest)
· [Landing page](https://kristijankopacevic.github.io/profitguard-for-woocommerce/)

---

## It uses the cost data you already have

Since **WooCommerce 10.3** (October 2025), Cost of Goods Sold is part of
WooCommerce itself — though it is **off by default**, under
WooCommerce → Settings → Advanced → Features. ProfitGuard does not add a
competing cost field. It reads whichever cost data the store already holds:

| Source | Behaviour |
|---|---|
| **WooCommerce's own Cost of Goods Sold field** | Preferred when the feature is enabled. ProfitGuard reads it, and imports write back **through** it, so there is one cost in the product editor rather than two that disagree. |
| **Third-party cost-of-goods plugin meta** | Read only. ProfitGuard never writes to another plugin's data. |
| **Costs you import yourself** | Used when there is nothing else to read. |

Variation costs resolve the way WooCommerce resolves them — inherited from the
parent, replacing it, or added to it — so ProfitGuard's product-level margins
reconcile with WooCommerce's own analytics rather than quietly disagreeing.
The measured behaviour those rules are built on is recorded in
[`tests/cogs/MEASURED-FACTS.md`](tests/cogs/MEASURED-FACTS.md).

With the feature **disabled** — the common case — ProfitGuard writes nothing
into it, keeps using its own cost storage, and tells the merchant the setting
exists and what enabling it would give them.

## What it reports

- Margin against a target gross margin you set, with the current margin, the
  markup, the profit per unit, and the price that would reach your target
- Findings for low margin, critical margin, selling below cost, missing cost,
  sale-price margin risk, and supplier cost increases
- Cost coverage and missing-cost detection, reported **separately** from the score
- Product cost CSV import with column mapping, a current → new preview per row,
  and no silent replacement of a cost held in WooCommerce's own field
- Carrier cost CSV import matched to orders, with duplicate and unmatched-row detection
- Order-level shipping profitability: charged against actual carrier cost
- The ProfitGuard Score, with the share of the store it covers stated beside it
- Every finding prioritised by the amount of money at stake, and CSV export
- Background scanning through Action Scheduler; High-Performance Order Storage compatible

## What it will not do

It will not invent a number. If a product has no cost, ProfitGuard says so
instead of showing a margin. If an order has no imported carrier cost, it says
so instead of estimating a loss. Categories with no data are left out of the
score rather than scored zero.

It will not change a price, a product, or an order. It writes a cost only when
you confirm an import.

## Free, and staying free

Everything above is in this plugin permanently, with no row limit, no trial and
no upsell inside it. A separate paid add-on may follow, but only once the
conditions in [`MONETIZATION_LATER.md`](MONETIZATION_LATER.md) are met.

## Privacy

No external HTTP requests, no analytics, no telemetry, no store data sent
anywhere. Uploaded CSV files are parsed and discarded — the file itself is never
written to your uploads directory.

That is checked rather than asserted: CI walks the **PHP tokens** of the shipped
archive looking for outbound-request calls, so a comment mentioning
`wp_remote_get` cannot pass the check and a real call cannot slip through one.

## Requirements

- WordPress 6.4+ (tested to 7.1)
- WooCommerce 8.0+ (tested to 11.1)
- PHP 7.4+

## Verified on every release

The machine this plugin is developed on has no PHP, no Composer and no working
Docker daemon, so every check runs in GitHub Actions. `v1.0.0` was cut only
after all of the following were green:

- PHPUnit on PHP **7.4, 8.1, 8.2, 8.3 and 8.4** — 200 tests, 281,558 assertions
- WordPress Coding Standards (the full `WordPress` ruleset) — **zero errors, zero warnings**
- Allow-listed ZIP, with the archive contents asserted: plugin file present at
  the root, and no `tests/`, `vendor/`, `node_modules/`, `bin/`, `.git` or `.github`
- Plugin header `Version` must equal `readme.txt`'s `Stable tag`
- WordPress.org **Plugin Check** against the installed plugin — zero errors
- Installed from that exact ZIP into a fresh **WordPress 7.1 + WooCommerce 11.1.0**,
  then deactivated and reactivated
- HPOS compatibility read back out of `FeaturesUtil` at runtime, not inferred
  from the source
- A seeded store, a real Profit Scan, and an assertion that it produced findings
- Browser smoke with real screenshots — run **twice**, once with WooCommerce's
  native COGS feature enabled and once with it disabled
- Native COGS resolution asserted against a live store: a simple product's cost,
  a variation inheriting its parent's, a variation overriding it, a variation
  with no cost anywhere staying `null` rather than becoming `0`, order-level
  totals agreeing with core's own, and no shadow meta written
- An import that would replace a cost in WooCommerce's own field proven to be
  refused until confirmed
- Every product price and order total re-read after an import, proving none moved

## Development

There are no runtime dependencies; the plugin ships without a `vendor` tree and
uses a hand-written autoloader. Composer is development tooling only.

```bash
composer install
composer run test     # PHPUnit, WordPress-free core suite
composer run lint     # PHPCS, full WordPress standard
bash bin/build-zip.sh # the distributable archive
```

See [`DEVELOPMENT.md`](DEVELOPMENT.md).

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
