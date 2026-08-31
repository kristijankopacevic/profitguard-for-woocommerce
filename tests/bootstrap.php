<?php
/**
 * PHPUnit bootstrap for the WordPress-free core suite.
 *
 * These tests run against pure PHP: no WordPress, no WooCommerce, no database.
 * That is deliberate and it is what makes the financial core verifiable in a
 * plain `php:8.2-cli` container - the tier containing every formula a merchant
 * might act on is also the tier with no infrastructure to stand up.
 *
 * WHY ABSPATH IS DEFINED HERE.
 *
 * Every shipped file opens with the canonical `defined( 'ABSPATH' ) || exit;`
 * guard, which is what stops a file being requested directly over HTTP and is
 * what the WordPress.org Plugin Check requires. An earlier version let the
 * guard also accept a PROFITGUARD_TESTING constant, so the core could be
 * loaded outside WordPress - but that puts a bypass of the access guard into
 * the shipped code, and Plugin Check flags it as a missing guard.
 *
 * Defining ABSPATH here instead keeps the guard canonical in every file and
 * confines the test affordance to the test bootstrap, where it belongs.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';
