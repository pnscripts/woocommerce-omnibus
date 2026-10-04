<?php
/**
 * Coverage report on the admin page.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Pnscripts\Omnibus\Admin\SettingsPage;

/**
 * The report lists simple products and variations on sale, never variable parents, and loads only one page.
 */
final class AdminReportTest extends IntegrationTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['paged'] );
		parent::tearDown();
	}

	private function render_report(): string {
		$_GET['tab'] = 'report';
		ob_start();
		( new SettingsPage( $this->plugin ) )->render();
		return (string) ob_get_clean();
	}

	public function test_report_lists_products_and_variations_on_sale_but_not_parents(): void {
		wp_set_current_user( 1 );
		$simple                      = $this->simple( '100', '80' );
		list( $parent, $variations ) = $this->variable( array( '50' ) );
		$variation                   = $this->fresh( $variations[0]->get_id() );
		$variation->set_sale_price( '40' );
		$variation->save();
		\WC_Product_Variable::sync( $parent->get_id() );
		delete_transient( 'wc_products_onsale' );

		$html = $this->render_report();

		$this->assertStringContainsString( $simple->get_name(), $html );
		$this->assertStringContainsString( 'post.php?post=' . $parent->get_id(), $html, 'The variation row links to its parent.' );
		$this->assertSame( 1, substr_count( $html, 'post.php?post=' . $parent->get_id() ), 'The variable parent itself is not a row.' );
	}
}
