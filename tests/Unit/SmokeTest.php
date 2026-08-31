<?php
declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function test_the_toolchain_runs(): void {
		$this->assertSame( 8, PHP_INT_SIZE, 'ProfitGuard assumes 64-bit integers for money arithmetic.' );
	}
}
