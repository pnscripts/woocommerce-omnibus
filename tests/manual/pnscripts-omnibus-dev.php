<?php
/**
 * Plugin Name: PN Scripts Price History dev tools (local testing only)
 * Description: Adds `wp pnscripts-omnibus-dev simulate` to write a simulated price history. NOT part of the
 *              released plugin (excluded by .distignore): fabricating history on a live shop would defeat its purpose.
 *
 * Install as a must-use plugin on a local test site only:
 *   cp tests/manual/pnscripts-omnibus-dev.php wp-content/mu-plugins/
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Simulated price histories for local manual testing.
 */
final class Pnscripts_Omnibus_Dev_Command {

	/**
	 * Replace the history of a product or variation with simulated steps.
	 *
	 * Each step is "days_ago:regular[:sale]". The last step becomes the product's real current price.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation id.
	 *
	 * <steps>...
	 * : Steps like 60:100 25:100:90 10:100:80 (oldest first).
	 *
	 * [--launched-days-ago=<days>]
	 * : Set the product creation date this many days ago (default: oldest step + 1).
	 *
	 * ## EXAMPLES
	 *
	 *     wp pnscripts-omnibus-dev simulate 12 60:100 40:100:85 20:100 5:100:90
	 *
	 * @param string[]              $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function simulate( array $args, array $assoc_args ): void {
		$id      = (int) array_shift( $args );
		$product = wc_get_product( $id );
		$plugin  = \Pnscripts\Omnibus\Plugin::instance();
		if ( ! $product instanceof WC_Product || null === $plugin ) {
			WP_CLI::error( 'Product or plugin not found.' );
		}

		$steps = array();
		foreach ( $args as $arg ) {
			$parts = explode( ':', $arg );
			if ( count( $parts ) < 2 ) {
				WP_CLI::error( "Bad step: {$arg}" );
			}
			$steps[] = array(
				'days'    => (float) $parts[0],
				'regular' => $parts[1],
				'sale'    => $parts[2] ?? '',
			);
		}
		usort( $steps, static fn ( array $a, array $b ): int => $b['days'] <=> $a['days'] );

		$now      = $plugin->clock->now();
		$oldest   = $steps[0]['days'];
		$launched = isset( $assoc_args['launched-days-ago'] ) ? (float) $assoc_args['launched-days-ago'] : $oldest + 1;
		$last     = end( $steps );

		$product->set_regular_price( $last['regular'] );
		$product->set_sale_price( $last['sale'] );
		$product->set_date_on_sale_from( '' );
		$product->set_date_on_sale_to( '' );
		$product->set_date_created( (int) ( $now - $launched * DAY_IN_SECONDS ) );
		$product->save();

		$plugin->repository->delete_product( $id );
		foreach ( $steps as $step ) {
			$regular = \Pnscripts\Omnibus\Domain\Money::normalize( $step['regular'] );
			$sale    = \Pnscripts\Omnibus\Domain\Money::normalize( $step['sale'] );
			$price   = null !== $sale && null !== $regular && $sale < $regular ? $sale : $regular;
			$plugin->repository->insert(
				$id,
				$product->get_parent_id(),
				new \Pnscripts\Omnibus\Domain\PriceRecord( (int) ( $now - $step['days'] * DAY_IN_SECONDS ), $regular, $sale, null, null, $price, null, null, 'simulated' ),
				(string) get_option( 'woocommerce_currency' )
			);
		}
		$plugin->recorder->reset();
		WP_CLI::success( sprintf( 'Simulated %d steps for #%d.', count( $steps ), $id ) );
	}
}

WP_CLI::add_command( 'pnscripts-omnibus-dev', 'Pnscripts_Omnibus_Dev_Command' );
