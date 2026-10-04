<?php
/**
 * Base unit test case with Brain Monkey.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets up and tears down Brain Monkey.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
