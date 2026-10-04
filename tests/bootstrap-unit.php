<?php
/**
 * Unit test bootstrap: pure PHP, WordPress functions are stubbed with Brain Monkey per test.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
