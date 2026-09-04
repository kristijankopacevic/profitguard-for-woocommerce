/**
 * Drive the real plugin in a real browser and photograph what it does.
 *
 *   PG_PORT=8092 PG_COGS=on node tests/browser-smoke.js
 *
 * The screenshots this produces are the only images of the plugin anyone should
 * trust: they come from a fresh WordPress with a seeded store, not from a
 * designer's mockup. The listing art in assets-wporg/ is compared against them.
 *
 * Beyond taking pictures, this asserts the load-bearing claims readme.txt
 * makes, because a claim a merchant cannot check is a claim CI has to:
 *
 *   - "Uploaded CSV files are parsed and discarded" - nothing lands in uploads/
 *   - "never changes a price, a product, or an order" - prices are re-read after
 *     an import and must be byte-identical
 *   - an import that would replace a cost held in WooCommerce's own field is
 *     refused until it is explicitly confirmed
 */

const { chromium } = require("playwright");
const fs = require("fs");
const { execFileSync } = require("child_process");

const PORT = process.env.PG_PORT || "8092";
const COGS = process.env.PG_COGS === "on" ? "on" : "off";
const BASE = `http://127.0.0.1:${PORT}`;
const SHOT_DIR = process.env.PG_SHOT_DIR || `screenshots/cogs-${COGS}`;

/**
 * Run wp-cli inside the test stack.
 *
 * @param {string[]} args wp-cli arguments.
 * @returns {string} stdout, trimmed.
 */
function wp(args) {
  return execFileSync(
    "docker",
    [
      "run", "--rm", "--network", "pg-test", "--volumes-from", "pg-wp",
      "-e", "WORDPRESS_DB_HOST=pg-db:3306",
      "-e", "WORDPRESS_DB_USER=wordpress",
      "-e", "WORDPRESS_DB_PASSWORD=wordpress",
      "-e", "WORDPRESS_DB_NAME=wordpress",
      "wordpress:cli-php8.3", "wp", "--path=/var/www/html", "--allow-root",
      ...args,
    ],
    { encoding: "utf8" }
  ).trim();
}

/**
 * Fail loudly with a named reason.
 *
 * @param {boolean} condition Must be true.
 * @param {string}  message   What was expected.
 */
function assert(condition, message) {
  if (!condition) {
    throw new Error(`ASSERTION FAILED: ${message}`);
  }
}

(async () => {
  fs.mkdirSync(SHOT_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const consoleErrors = [];
  page.on("console", (m) => {
    if (m.type() === "error") consoleErrors.push(m.text());
  });

  // ---------------------------------------------------------------- log in
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: "networkidle" });
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    page.click("#wp-submit"),
  ]);

  // ------------------------------------------------------------- dashboard
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard`, { waitUntil: "networkidle" });
  assert(
    (await page.locator("h1").filter({ hasText: "ProfitGuard" }).count()) > 0,
    "the ProfitGuard dashboard did not render"
  );
  await page.screenshot({ path: `${SHOT_DIR}/1-dashboard.png`, fullPage: true });

  // -------------------------------------------------------------- settings
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard-settings`, { waitUntil: "networkidle" });
  await page.fill('input[name="settings[target_margin_percent]"]', "35");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    page.getByRole("button", { name: "Save settings" }).click(),
  ]);
  const savedTarget = await page.inputValue('input[name="settings[target_margin_percent]"]');
  assert(savedTarget === "35", `target margin did not persist, got "${savedTarget}"`);
  await page.screenshot({ path: `${SHOT_DIR}/6-settings.png`, fullPage: true });

  // The scan itself is driven synchronously by bin/run-scan.php in
  // fresh-install.sh, because Action Scheduler's CLI runner does not pick up
  // async actions. Pressing the button here proves the CONTROL works and that
  // a scan is accepted; the findings below come from that completed scan.
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard`, { waitUntil: "networkidle" });
  const scanButton = page.getByRole("button", { name: "Run Profit Scan" });
  if (await scanButton.count()) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle" }),
      scanButton.first().click(),
    ]);
  }

  // -------------------------------------------------------------- findings
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard-findings`, { waitUntil: "networkidle" });
  const findingRows = await page.locator("table.widefat tbody tr").count();
  assert(findingRows > 0, "the findings table rendered no rows after a completed scan");
  await page.screenshot({ path: `${SHOT_DIR}/2-findings.png`, fullPage: true });

  // --------------------------------------- prices before any import happens
  const pricesBefore = wp([
    "eval",
    'foreach ( wc_get_products( array( "limit" => -1, "return" => "ids" ) ) as $id ) { $p = wc_get_product( $id ); echo $id . ":" . $p->get_regular_price() . ":" . $p->get_sale_price() . "\\n"; }',
  ]);
  const orderTotalsBefore = wp([
    "eval",
    'foreach ( wc_get_orders( array( "limit" => -1, "return" => "ids" ) ) as $id ) { $o = wc_get_order( $id ); echo $id . ":" . $o->get_total() . ":" . $o->get_status() . "\\n"; }',
  ]);

  // ------------------------------------------------- import product costs
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard-import`, { waitUntil: "networkidle" });
  await page.screenshot({ path: `${SHOT_DIR}/4-import.png`, fullPage: true });

  // Two upload forms share identical markup and differ only by a hidden
  // "kind", so target the form rather than the button.
  const costForm = page.locator('form:has(input[name="kind"][value="cost"])');
  await costForm.locator('input[name="profitguard_file"]').setInputFiles("samples/sample-product-costs.csv");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    costForm.getByRole("button", { name: "Upload and preview" }).click(),
  ]);

  assert(
    (await page.getByRole("heading", { name: "Check the columns" }).count()) > 0,
    "the cost import preview did not render"
  );
  assert(
    (await page.getByRole("heading", { name: "What this would change" }).count()) > 0,
    "the preview did not show a current-to-new comparison"
  );
  await page.screenshot({ path: `${SHOT_DIR}/4b-import-preview.png`, fullPage: true });

  // Nothing may have been written yet: this is the preview step.
  const pricesAtPreview = wp([
    "eval",
    'foreach ( wc_get_products( array( "limit" => -1, "return" => "ids" ) ) as $id ) { $p = wc_get_product( $id ); echo $id . ":" . $p->get_regular_price() . ":" . $p->get_sale_price() . "\\n"; }',
  ]);
  assert(pricesAtPreview === pricesBefore, "a price changed during the PREVIEW step, before any confirmation");

  // With native COGS on, the seeded store already holds native costs, so some
  // rows would replace one. Those rows must be refused until confirmed.
  const overwriteNotice = page.locator('input[name="confirm_native_overwrite"]');
  const hasOverwriteGuard = (await overwriteNotice.count()) > 0;
  if (COGS === "on") {
    assert(
      hasOverwriteGuard,
      "native COGS is enabled and the seeded store has native costs, but the preview offered no overwrite confirmation"
    );

    // Confirm-and-import WITHOUT ticking: the guarded rows must be skipped.
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle" }),
      page.getByRole("button", { name: "Confirm and import" }).click(),
    ]);
    const blockedNotice = await page.getByText(/left alone because/i).count();
    assert(blockedNotice > 0, "an unconfirmed import did not report refusing to replace native costs");
    await page.screenshot({ path: `${SHOT_DIR}/4c-native-overwrite-blocked.png`, fullPage: true });

    // Now do it again, ticking the box, and the rows must apply.
    const costForm2 = page.locator('form:has(input[name="kind"][value="cost"])');
    await costForm2.locator('input[name="profitguard_file"]').setInputFiles("samples/sample-product-costs.csv");
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle" }),
      costForm2.getByRole("button", { name: "Upload and preview" }).click(),
    ]);
    const box = page.locator('input[name="confirm_native_overwrite"]');
    if (await box.count()) {
      await box.check();
    }
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle" }),
      page.getByRole("button", { name: "Confirm and import" }).click(),
    ]);
    assert(
      (await page.getByText(/Costs imported/i).count()) > 0,
      "a confirmed import did not report success"
    );
  } else {
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle" }),
      page.getByRole("button", { name: "Confirm and import" }).click(),
    ]);
    assert(
      (await page.getByText(/Costs imported/i).count()) > 0,
      "the cost import did not report success"
    );
  }

  // --------------------------------- the two trust claims, after a real write
  const pricesAfter = wp([
    "eval",
    'foreach ( wc_get_products( array( "limit" => -1, "return" => "ids" ) ) as $id ) { $p = wc_get_product( $id ); echo $id . ":" . $p->get_regular_price() . ":" . $p->get_sale_price() . "\\n"; }',
  ]);
  assert(pricesAfter === pricesBefore, "a cost import changed a product price, which readme.txt says never happens");

  const orderTotalsAfter = wp([
    "eval",
    'foreach ( wc_get_orders( array( "limit" => -1, "return" => "ids" ) ) as $id ) { $o = wc_get_order( $id ); echo $id . ":" . $o->get_total() . ":" . $o->get_status() . "\\n"; }',
  ]);
  assert(orderTotalsAfter === orderTotalsBefore, "a cost import changed an order, which readme.txt says never happens");

  // "Uploaded CSV files are parsed and discarded; the file itself is never
  // written to your uploads directory."
  const strayUploads = wp([
    "eval",
    '$d = wp_upload_dir(); $found = array(); $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d["basedir"], FilesystemIterator::SKIP_DOTS ) ); foreach ( $it as $f ) { if ( preg_match( "/\\.csv$/i", $f->getFilename() ) ) { $found[] = $f->getPathname(); } } echo implode( "\\n", $found );',
  ]);
  assert(
    strayUploads === "",
    `an uploaded CSV was left in the uploads directory, contradicting readme.txt: ${strayUploads}`
  );

  // ------------------------------------------------ import a carrier invoice
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard-import`, { waitUntil: "networkidle" });
  const carrierForm = page.locator('form:has(input[name="kind"][value="carrier"])');
  await carrierForm.locator('input[name="profitguard_file"]').setInputFiles("samples/sample-carrier-costs.csv");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    carrierForm.getByRole("button", { name: "Upload and preview" }).click(),
  ]);
  await page.screenshot({ path: `${SHOT_DIR}/5-carrier-preview.png`, fullPage: true });
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle" }),
    page.getByRole("button", { name: "Confirm and import" }).click(),
  ]);
  assert(
    (await page.getByText(/Carrier costs imported/i).count()) > 0,
    "the carrier import did not report success"
  );

  // Re-scan so shipping findings exist, then photograph them.
  wp(["eval-file", "/pgbin/run-scan.php"]);
  await page.goto(`${BASE}/wp-admin/admin.php?page=profitguard-findings&module=shipping`, { waitUntil: "networkidle" });
  await page.screenshot({ path: `${SHOT_DIR}/3-shipping-findings.png`, fullPage: true });

  // ------------------------------------------------------------ CSV export
  const download = await Promise.all([
    page.waitForEvent("download"),
    page.getByRole("button", { name: "Export findings (CSV)" }).first().click(),
  ]).then(([d]) => d);
  const exportPath = await download.path();
  assert(!!exportPath, "the findings export produced no file");
  const exported = fs.readFileSync(exportPath, "utf8");
  assert(exported.split("\n").length > 1, "the exported CSV had no data rows");

  // CSV formula injection: no exported cell may begin with a character a
  // spreadsheet would evaluate.
  const dangerous = exported
    .split("\n")
    .slice(1)
    .flatMap((line) => line.split(","))
    .filter((cell) => /^"?[=+\-@\t\r]/.test(cell) && !/^"?-?\d/.test(cell));
  assert(
    dangerous.length === 0,
    `exported CSV cells could be evaluated as formulas: ${dangerous.slice(0, 5).join(" | ")}`
  );

  // ---------------------------------------------- native COGS, when enabled
  if (COGS === "on") {
    const resolution = wp(["eval-file", "/pgcogs/assert-native-resolution.php"]);
    console.log(resolution);
    assert(
      resolution.includes("NATIVE_RESOLUTION_PASS"),
      "native COGS resolution assertions did not pass"
    );
  }

  assert(
    consoleErrors.length === 0,
    `browser console reported errors: ${consoleErrors.slice(0, 5).join(" | ")}`
  );

  await browser.close();
  console.log(`BROWSER_SMOKE_PASS (cogs=${COGS})`);
})().catch((error) => {
  console.error(error.message || error);
  process.exit(1);
});
