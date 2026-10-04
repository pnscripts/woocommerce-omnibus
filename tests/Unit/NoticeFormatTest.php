<?php
/**
 * Notice placeholders and escaping.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use Brain\Monkey\Functions;
use Pnscripts\Omnibus\Display\NoticeRenderer;

/**
 * NoticeRenderer::format().
 */
final class NoticeFormatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->alias( static fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );
	}

	public function test_placeholders_are_replaced(): void {
		$html = NoticeRenderer::format( 'Lowest price in the {days} days before {date}: {price}', '<span class="amount">10,00 €</span>', 30, '1 June 2026' );

		$this->assertSame( 'Lowest price in the 30 days before 1 June 2026: <span class="amount">10,00 €</span>', $html );
	}

	public function test_text_is_escaped_but_price_html_is_kept(): void {
		$html = NoticeRenderer::format( '<script>x</script> {price} & more', '<bdi>5</bdi>', 30, '' );

		$this->assertSame( '&lt;script&gt;x&lt;/script&gt; <bdi>5</bdi> &amp; more', $html );
	}

	public function test_price_can_appear_twice_or_not_at_all(): void {
		$this->assertSame( 'A 1 B 1', NoticeRenderer::format( 'A {price} B {price}', '1', 30, '' ) );
		$this->assertSame( 'No price here (30)', NoticeRenderer::format( 'No price here ({days})', '1', 30, '' ) );
	}

	public function test_date_value_is_escaped(): void {
		$this->assertSame( 'since &lt;b&gt;', NoticeRenderer::format( 'since {date}', '', 30, '<b>' ) );
	}
}
