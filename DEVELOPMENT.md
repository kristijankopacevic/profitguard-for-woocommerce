# Developing ProfitGuard for WooCommerce

Everything here is free. No account, no paid image, no hosted service.

## What you need

- **Docker Desktop** (free) — for the WordPress environment and for PHP itself.
  You do not need PHP, Composer or MySQL installed on your machine; every
  command below runs inside an official public image.

That is the whole list.

---

## The fast loop: tests without WordPress

The financial core — `includes/Core/`, `includes/Analysis/` and
`includes/Import/Mapper.php` — is deliberately free of WordPress. It does not
call a single WordPress function, so it runs in a bare PHP container in under a
second. This is where you should live while working on the maths.

```bash
cd profitguard-for-woocommerce

# One-off: install the dev dependencies (PHPUnit, PHPCS, WPCS).
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install

# The whole verification suite: 191 tests, then coding standards.
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli \
  sh -c 'php vendor/bin/phpunit && php vendor/bin/phpcs'
```

Expected output: `OK (191 tests, 281540 assertions)` and no PHPCS violations.

On Windows in Git Bash, prefix with `MSYS_NO_PATHCONV=1` and use an absolute
path (`-v "//c/Users/you/.../profitguard-for-woocommerce:/app"`), otherwise
MSYS rewrites `/app` into a Windows path and the mount lands in the wrong place.

---

## The full loop: a real WordPress with a real store

```bash
docker compose up -d

docker compose run --rm wpcli "
  wp core install --url=http://localhost:8080 --title='ProfitGuard Dev' \
    --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email &&
  wp plugin install woocommerce --activate &&
  wp option update woocommerce_feature_custom_order_tables_enabled yes &&
  wp wc hpos sync &&
  wp option update woocommerce_custom_orders_table_enabled yes &&
  wp plugin activate profitguard-for-woocommerce
"
```

Then <http://localhost:8080/wp-admin> — `admin` / `admin`.

The plugin directory is bind-mounted, so an edit to a PHP file is live on the
next request. There is no build step and nothing to recompile.

**HPOS is enabled above on purpose.** It is the WooCommerce default for new
stores, so it is what the plugin must work against. `wp wc hpos enable` fatals on
some WooCommerce builds; the three options above are the reliable equivalent.

### Fill it with a demo store

```bash
docker compose run --rm wpcli "
  wp eval-file wp-content/plugins/profitguard-for-woocommerce/bin/seed-demo.php &&
  wp eval-file wp-content/plugins/profitguard-for-woocommerce/bin/import-samples.php &&
  wp eval-file wp-content/plugins/profitguard-for-woocommerce/bin/run-scan.php
"
```

This creates 60 products and 40 orders covering every state the plugin has a
finding for: healthy margins, low, critical, negative, missing cost, sale prices
eating the margin, cost increases, shipping profit, shipping loss, high shipping
loss, missing carrier costs, an unmatched carrier row and a duplicate.

**The seeder is idempotent.** It tags everything it creates with
`_profitguard_demo` and deletes only its own rows, so you can re-run it as often
as you like without tripping WooCommerce's duplicate-SKU check.

`bin/run-scan.php` runs the scan synchronously rather than through Action
Scheduler, so you get results immediately instead of waiting for cron.

`bin/smoke-render.php` renders every admin page and fails loudly on a PHP notice
— useful before you commit a template change.

**`bin/*.php` files must not use `declare(strict_types=1)`.** WP-CLI's
`eval-file` runs them through `eval()`, which rejects the declaration.

---

## Testing the built ZIP

The dev environment mounts your working tree, which proves the working tree
works — not that the ZIP does. Those are different claims, and the difference is
exactly where "it worked on my machine" releases come from.

```bash
bash bin/build-zip.sh    # -> dist/profitguard-for-woocommerce.zip

docker compose -p pgzip -f docker-compose.ziptest.yml up -d
docker compose -p pgzip -f docker-compose.ziptest.yml run --rm wpcli "
  wp core install --url=http://localhost:8081 --title=ZipTest \
    --admin_user=admin --admin_password=admin --admin_email=z@example.test --skip-email &&
  wp plugin install woocommerce --activate &&
  wp plugin install /dist/profitguard-for-woocommerce.zip --activate &&
  wp plugin install plugin-check --activate &&
  wp plugin check profitguard-for-woocommerce
"
docker compose -p pgzip -f docker-compose.ziptest.yml down -v
```

That stack does **not** mount the source. The only way the plugin gets in is by
being installed from the ZIP, the same way a merchant installs it.

`bin/build-zip.sh` copies from an allow-list rather than excluding a deny-list,
so a new development file cannot accidentally ship. It refuses to build if
`vendor/` or a dotfile would end up inside.

---

## Layout, and why

```
includes/
  Core/        Money, Margin, Finding, Aggregate, Score, Shipping, Csv
  Analysis/    MarginAnalyser, ShippingAnalyser
  Import/      Mapper (pure), Importer (WordPress)
  Woo/         CostProvider, Catalog, Orders
  Scan/        Scanner
  Plugin/      Database, Repository, Settings
  Admin/       Admin, Pages, Labels, Exporter
```

The dependency rule is one-directional: `Core/` knows nothing, `Analysis/`
knows `Core/`, `Woo/` and `Plugin/` know WordPress, `Admin/` knows everything.
Nothing in `Core/`, `Analysis/` or `Import/Mapper.php` may call a WordPress
function. That is not tidiness for its own sake — it is what lets the money
arithmetic be tested exhaustively in a one-second container instead of a
WordPress test harness, and it is why the suite has 281,540 assertions.

### Money

All money is a **64-bit integer in minor units** (cents). All percentages are
**basis points** (1% = 100 bp). There is no float anywhere in a financial path
and there is no `bcmath` dependency, because `bcmath` is absent from a great
many shared hosts.

`Money::mul_div_round()` takes the quotient and remainder before multiplying,
which keeps far more headroom before overflow than the naive form. Overflow is
detected rather than ignored: PHP silently converts an overflowing integer to a
float, so the code checks `is_int()`.

### Unknown is null, never zero

A cost we do not have is `null`. It stays `null` through the finding, through
the aggregate, into the database column, and out to the screen as an em dash.
`SUM()` ignores NULL, which is the correct behaviour and the reason the totals
column is deliberately not wrapped in `COALESCE`.

This is the single most important invariant in the plugin. A missing carrier
invoice must never become "€0.00 of shipping cost", because that renders as a
profit the merchant does not have.
