#!/usr/bin/env bash
#
# Install the SHIPPED ZIP into a fresh WordPress + WooCommerce and prove the
# claims readme.txt makes, rather than inheriting them.
#
#   bash tests/fresh-install.sh
#
# Environment:
#   PG_COGS   on|off  whether WooCommerce's native Cost of Goods Sold feature
#                     is enabled. The suite runs twice, because the plugin has
#                     to behave correctly in both states and "off" is the
#                     default a real store arrives in.
#   PG_PORT           host port for the test WordPress. Never 7777 (a live
#                     dashboard) and never 8787/8788 (PassiveOS).
#   PG_KEEP_CONTAINERS=1 leave the stack up for the browser smoke.
#
# Container and network names are prefixed pg- so nothing here can collide with
# the sibling Cost Importer stack, which uses ciwc-.

set -euo pipefail

PG_COGS="${PG_COGS:-off}"
PG_PORT="${PG_PORT:-8092}"
SLUG="profitguard-for-woocommerce"
ZIP_PATH="dist/${SLUG}.zip"

# Pinned, not "latest": readme.txt claims a specific WordPress and the plugin
# header a specific WooCommerce, and a floating tag would quietly stop testing
# what is claimed.
WP_VERSION="7.1"
WC_VERSION="11.1.0"

cleanup() {
  if [[ "${PG_KEEP_CONTAINERS:-0}" == "1" ]]; then
    return
  fi
  docker rm -f pg-wp pg-db >/dev/null 2>&1 || true
  docker network rm pg-test >/dev/null 2>&1 || true
}
trap cleanup EXIT

pull_image() {
  local image="$1" attempt
  # Docker Hub intermittently 5xxes a manifest request on hosted runners.
  # Retry before blaming the product.
  for attempt in {1..4}; do
    if docker pull "$image"; then return 0; fi
    sleep "$(( attempt * 3 ))"
  done
  return 1
}

[ -f "${ZIP_PATH}" ] || { echo "ERROR: ${ZIP_PATH} not found. Run bin/build-zip.sh first." >&2; exit 1; }

###############################################################################
# 1. The privacy claim, checked against the SHIPPED archive.
#
# readme.txt says the plugin "makes no external HTTP requests, contains no
# analytics or telemetry of any kind". That is the single highest-value claim in
# the file, because it is the one a merchant cannot verify and a reviewer will
# not take on trust. Checked here against the files that actually ship - not the
# working tree, where bin/ and tests/ legitimately read local files.
###############################################################################
echo "== Checking the no-external-requests claim against the shipped ZIP =="
rm -rf dist/_verify && mkdir -p dist/_verify
if command -v unzip >/dev/null 2>&1; then
  unzip -q "${ZIP_PATH}" -d dist/_verify
else
  python3 -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "${ZIP_PATH}" dist/_verify
fi

# Tokenized, not grepped. The grep version of this check failed the build on a
# COMMENT in Import/Importer.php explaining why wp_remote_get() is NOT used - a
# check guarding a trust claim must not be decided by prose.
php tests/assert-no-outbound-requests.php dist/_verify

# Analytics endpoints hiding in a string literal.
if grep -rnE "google-analytics|googletagmanager|segment\.(io|com)|mixpanel|sentry\.io|plausible|matomo|amplitude" dist/_verify; then
  echo "ERROR: shipped code references an analytics endpoint." >&2
  exit 1
fi
echo "  no analytics endpoints referenced"

###############################################################################
# 2. Stand up WordPress and WooCommerce, both at pinned versions.
###############################################################################
echo "== Standing up WordPress ${WP_VERSION} + WooCommerce ${WC_VERSION} =="
docker network create pg-test >/dev/null
pull_image mariadb:11
pull_image wordpress:php8.3-apache
pull_image wordpress:cli-php8.3

docker run -d --name pg-db --network pg-test \
  -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress \
  -e MARIADB_PASSWORD=wordpress -e MARIADB_ROOT_PASSWORD=root \
  mariadb:11 >/dev/null

docker run -d --name pg-wp --network pg-test -p "${PG_PORT}:80" \
  -e WORDPRESS_DB_HOST=pg-db:3306 -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  -v "$(pwd)/${ZIP_PATH}:/tmp/${SLUG}.zip:ro" \
  -v "$(pwd)/bin:/pgbin:ro" \
  -v "$(pwd)/samples:/pgsamples" \
  -v "$(pwd)/tests/cogs:/pgcogs:ro" \
  wordpress:php8.3-apache >/dev/null

for attempt in {1..40}; do
  if curl --fail --silent "http://127.0.0.1:${PG_PORT}/wp-admin/install.php" >/dev/null; then break; fi
  sleep 3
done
curl --fail --silent "http://127.0.0.1:${PG_PORT}/wp-admin/install.php" >/dev/null

# The entrypoint sets its own permissions as it starts. Once it is ready, make
# only the transient directories writable, so plugin installs work.
docker exec pg-wp sh -c 'mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads; chmod 777 /var/www/html/wp-content /var/www/html/wp-content/plugins /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads'

wp() {
  docker run --rm --network pg-test --volumes-from pg-wp \
    -e WORDPRESS_DB_HOST=pg-db:3306 -e WORDPRESS_DB_USER=wordpress \
    -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
    wordpress:cli-php8.3 wp --path=/var/www/html --allow-root "$@"
}

wp core install --url="http://127.0.0.1:${PG_PORT}" --title='ProfitGuard test' \
  --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email

# Pin WordPress to the version readme.txt claims, then assert it took.
wp core update --version="${WP_VERSION}" --force --skip-plugins --skip-themes
wp core update-db
actual_wp="$(wp core version | tr -d '\r')"
echo "  WordPress: ${actual_wp}"
[ "${actual_wp}" = "${WP_VERSION}" ] || { echo "ERROR: wanted WordPress ${WP_VERSION}, got ${actual_wp}." >&2; exit 1; }

wp plugin install woocommerce --version="${WC_VERSION}" --activate
actual_wc="$(wp plugin get woocommerce --field=version | tr -d '\r')"
echo "  WooCommerce: ${actual_wc}"
[ "${actual_wc}" = "${WC_VERSION}" ] || { echo "ERROR: wanted WooCommerce ${WC_VERSION}, got ${actual_wc}." >&2; exit 1; }

###############################################################################
# 3. Native COGS state for this run.
###############################################################################
if [ "${PG_COGS}" = "on" ]; then
  wp option update woocommerce_feature_cost_of_goods_sold_enabled yes
else
  wp option update woocommerce_feature_cost_of_goods_sold_enabled no
fi
echo "== Native COGS feature: ${PG_COGS} =="

###############################################################################
# 4. Install the plugin from the ZIP and exercise its lifecycle.
###############################################################################
echo "== Installing the plugin from the built ZIP =="
wp plugin install "/tmp/${SLUG}.zip" --activate
wp plugin is-active "${SLUG}"

# Deactivating must actually deactivate, and reactivating must work. A plugin
# whose activation hook only survives a first run breaks on every update.
wp plugin deactivate "${SLUG}"
if wp plugin is-active "${SLUG}"; then
  echo "ERROR: plugin still active after deactivation." >&2
  exit 1
fi
wp plugin activate "${SLUG}"
wp plugin is-active "${SLUG}"
echo "  activate / deactivate / reactivate all clean"

###############################################################################
# 5. WordPress.org Plugin Check, against the installed plugin. Zero errors.
###############################################################################
echo "== Plugin Check =="
wp plugin install plugin-check --activate
check_output="$(wp plugin check "/var/www/html/wp-content/plugins/${SLUG}" --require=./wp-content/plugins/plugin-check/cli.php 2>&1 || true)"
printf '%s\n' "${check_output}"
check_errors="$(printf '%s\n' "${check_output}" | grep -E '(^|[[:space:]])ERROR([[:space:]]|$)' || true)"
if [ -n "${check_errors}" ]; then
  echo "ERROR: Plugin Check reported errors:" >&2
  printf '%s\n' "${check_errors}" >&2
  exit 1
fi
echo "  Plugin Check: zero errors"

###############################################################################
# 6. HPOS. readme.txt says the plugin declares compatibility; prove it at
#    runtime rather than by reading the source.
###############################################################################
echo "== HPOS declaration =="
wp eval '
  if ( ! class_exists( "\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil" ) ) {
    WP_CLI::error( "FeaturesUtil missing; HPOS declaration cannot be verified." );
  }
  $compatible = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( "custom_order_tables" );
  $declared = in_array( "profitguard-for-woocommerce/profitguard-for-woocommerce.php", (array) ( $compatible["compatible"] ?? array() ), true );
  if ( ! $declared ) {
    WP_CLI::error( "plugin is NOT declared HPOS compatible, but readme.txt says it is" );
  }
  WP_CLI::success( "HPOS compatibility is declared and registered" );
'

# And with HPOS actually switched on, the plugin must still work, because it
# reads orders only through the CRUD API.
wp option update woocommerce_feature_custom_order_tables_enabled yes
wp eval 'WP_CLI::log( "HPOS enabled: " . var_export( \ProfitGuard\Woo\Orders::hpos_enabled(), true ) );'

###############################################################################
# 7. A real store, a real scan, real findings.
###############################################################################
echo "== Seeding a demo store =="
wp eval-file /pgbin/seed-demo.php
echo "== Running a Profit Scan to completion =="
wp eval-file /pgbin/run-scan.php

echo "== Asserting the scan produced findings =="
wp eval '
  $scan_id = \ProfitGuard\Plugin\Repository::latest_scan_id();
  if ( $scan_id < 1 ) {
    WP_CLI::error( "no scan id recorded, so the scan never completed" );
  }
  $counts = \ProfitGuard\Plugin\Repository::counts_by_type( $scan_id );
  $total  = array_sum( array_map( "intval", (array) $counts ) );
  WP_CLI::log( "scan " . $scan_id . " findings by type: " . wp_json_encode( $counts ) );
  if ( $total < 1 ) {
    WP_CLI::error( "a scan over the demo store produced no findings at all" );
  }
  WP_CLI::success( $total . " findings recorded" );
'

###############################################################################
# 8. Cost resolution in whichever state this run is in.
###############################################################################
if [ "${PG_COGS}" = "on" ]; then
  echo "== Creating a real native-cost conflict for the import guard =="
  # seed-demo.php writes the sample CSV FROM the store it built, so
  # re-importing that CSV finds every cost already equal and would never
  # exercise the overwrite guard. This sets one cost deliberately different.
  wp eval-file /pgcogs/setup-native-conflict.php

  echo "== Asserting native COGS resolution against a real store =="
  wp eval-file /pgcogs/assert-native-resolution.php
fi

echo "== Cost resolution with native COGS ${PG_COGS} =="
wp eval "
  \$expected_native = ( '${PG_COGS}' === 'on' );
  \$enabled = \ProfitGuard\Woo\NativeCogs::is_enabled();
  WP_CLI::log( 'NativeCogs::is_enabled() = ' . var_export( \$enabled, true ) );
  if ( \$enabled !== \$expected_native ) {
    WP_CLI::error( 'feature detection disagrees with the option that was set' );
  }
  WP_CLI::success( 'native COGS detection matches the store state' );
"

echo 'FRESH_INSTALL_PASS'
