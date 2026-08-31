<?php
/**
 * PHPUnit bootstrap for the WordPress-free core suite.
 *
 * These tests run against pure PHP: no WordPress, no WooCommerce, no database.
 * That is deliberate and it is what makes the financial core verifiable in a
 * plain `php:8.2-cli` container - the tier that contains every formula a
 * merchant might act on is also the tier with no infrastructure to stand up.
 *
 * PROFITGUARD_TESTING stands in for ABSPATH so the direct-access guard at the
 * top of each core file does not exit the test run.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

define( 'PROFITGUARD_TESTING', true );

require_once __DIR__ . '/../vendor/autoload.php';
