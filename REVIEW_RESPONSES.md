# Answers for the WordPress.org plugin reviewer

**Do not send any of this proactively.** Reviewers read the code, not your
documentation, and volunteering answers to questions nobody asked wastes their
time. Use a section only when a reviewer actually raises the point, and reply on
the existing email thread rather than resubmitting the plugin.

Every answer below was true of version 1.0.0 at submission. If the code changes,
re-check the claim before repeating it.

---

## "Why are you querying the database directly?"

ProfitGuard stores three kinds of high-volume analytics rows: findings from each
scan, imported carrier invoice rows, and scan history. A single scan of a modest
store produces well over a hundred findings.

Those cannot go in `wp_options`, which is where a plugin without its own tables
would be pushed. Autoloaded options are read on **every** page load of the entire
site, so putting scan output there would slow down the whole store to avoid three
tables. They are not custom post types either: they are not content, they are
never queried by WordPress, and they are pruned on a retention schedule.

The tables are created with `dbDelta()` in `includes/Plugin/Database.php` and
carry a schema version so migrations are ordered.

## "Table names are interpolated into SQL rather than prepared."

This is the source of every remaining Plugin Check warning, and it is
unavoidable rather than an oversight.

`$wpdb->prepare()` binds **values**. It cannot bind an **identifier** — there is
no placeholder for a table or column name in `prepare()`, and passing one through
`%s` would quote it as a string literal and produce invalid SQL.

Every table name in this plugin is built exactly one way:

```php
$table = $wpdb->prefix . 'profitguard_findings';
```

`$wpdb->prefix` plus a hard-coded literal defined in the class. There is no code
path in which a table or column identifier comes from `$_GET`, `$_POST`, a
request parameter, a CSV cell, a setting, or any other input. Every **value** in
every one of those queries is bound with `prepare()`.

Two related places where user input does reach a query, and how each is handled:

- **Findings filters** (`module`, `type`, `severity`, `orderby`). These select an
  ORDER BY clause and WHERE conditions, so they are checked against a hard-coded
  allow-list of permitted values before use; anything unrecognised falls back to
  the default. See `includes/Plugin/Repository.php`.
- **Imported CSV data.** Every cell is bound as a value. Column *mapping* is an
  integer index into the parsed row, not a name pasted into SQL.

The `phpcs:disable` in `Repository.php` is file-scoped, documented in place with
this reasoning, and covers only `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`.

## "Does the plugin make external requests?"

No. There is not one `wp_remote_get`, `wp_remote_post`, `curl_*`, or
`file_get_contents()` on a URL anywhere in the shipped code.

The only `file_get_contents()` call reads PHP's own upload temporary file during
a CSV import, with a byte cap, in `includes/Import/Importer.php`.

There is no update checker, no license check, no font or script loaded from a
CDN, and no analytics endpoint. The plugin works identically on a server with no
outbound internet access.

## "Is there telemetry?"

None of any kind. No analytics, no usage statistics, no "anonymous" reporting,
no opt-in tracking prompt, no third-party SDK. Nothing is collected, because
nothing is sent anywhere.

The admin footer says so, and the statement is literally true rather than
carefully worded.

## "Is any functionality restricted, timed, or paid?"

No. Every calculation the plugin can perform, it performs, for everyone, forever:

- No cap on the number of findings.
- No trial period and no expiry.
- No feature that stops working after a number of scans or imports.
- No upgrade prompt, no upsell, no advertisement, no affiliate link.
- No "pro" code, no license field, no payment integration, no remote licence
  server. None of it exists in this codebase.

## "What happens if WooCommerce is not active?"

The plugin declares `Requires Plugins: woocommerce`, so modern WordPress will not
let it be activated without WooCommerce.

That is not relied on as the only defence, because a merchant can deactivate
WooCommerce at any point afterwards. `includes/Plugin/Requirements.php` checks on
every load; if WooCommerce is missing or below the minimum version, ProfitGuard
registers no menus, runs no scans, and shows one admin notice explaining why. It
does not fatal, and it does not delete anything.

## "Is HPOS supported?"

Yes, and it is declared:

```php
FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
```

on `before_woocommerce_init`.

Orders are read **only** through the WooCommerce CRUD API — `wc_get_orders()` and
`WC_Order` — never by querying `wp_posts` or `wp_postmeta` for order data. That
is what makes the plugin behave identically on HPOS and on the legacy tables.
It was tested with HPOS enabled.

## "Are capabilities and nonces checked?"

Every state-changing request goes through one function,
`Admin::handle_post()`, which checks `current_user_can( 'manage_woocommerce' )`
and then `check_admin_referer()` for the specific action, before dispatching.
There is no second entry point and no AJAX handler.

Read screens re-check the capability when rendering, and the CSV export
re-checks it again before streaming, because a download endpoint should never
rely on an upstream check alone.

`manage_woocommerce` is the capability WooCommerce grants to administrators and
shop managers. Subscribers and customers have no access to any ProfitGuard screen
or action.

## "Is the file upload safe?"

CSV import validates, in order: `is_uploaded_file()`, a 5 MB size cap, the file
extension, `wp_check_filetype_and_ext()` (which rejects a file whose contents
disagree with its extension), and a NUL-byte scan that rejects binaries.

The uploaded file is parsed from PHP's temporary directory and never written to
`wp-content/uploads`. There is no code path that includes, executes, or renames
an uploaded file. Row and byte caps bound the work.

## "Is the CSV export safe to open in Excel?"

Yes. Every exported cell is passed through `Csv::escape_cell()`, which prefixes
a value beginning with `=`, `+`, `-`, `@`, tab or carriage return so a
spreadsheet treats it as text rather than a formula. Amounts are exported
unsigned with a separate direction column, so a negative number cannot begin a
cell with `-`.

This is verified on the built ZIP by `bin/check-export.php`, which parses the
generated file and fails if any cell would execute.

## "What is deleted on uninstall?"

Nothing, unless the merchant ticked "Delete all ProfitGuard data" in Settings
first. That setting is off by default.

Deactivating never deletes data; it only cancels queued Action Scheduler jobs.
With the setting enabled, `uninstall.php` drops the three tables, deletes the
plugin's product meta and its options, and is multisite-aware. Both branches were
tested.

## "Is any code obfuscated, minified, or loaded remotely?"

No. All PHP is human-readable and commented. There is no minified bundle, no
`eval()`, no `base64_decode()`, no `unserialize()` of untrusted data, and no
shell execution anywhere in the shipped code. The plugin ships no third-party
library and no `vendor/` directory — it has zero runtime dependencies.

## "Why is there no vendor directory / composer.json in the ZIP?"

Because nothing is needed at runtime. Classes are loaded by a small hand-written
PSR-4 autoloader in the main plugin file, which resolves only within the plugin's
own `includes/` directory. Composer is used for development tooling (PHPUnit,
PHPCS) and none of it is shipped.
