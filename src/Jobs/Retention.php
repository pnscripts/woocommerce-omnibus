<?php
/**
 * Deletes history older than the retention period.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Jobs;

use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Domain\PriceTimeline;
use Pnscripts\Omnibus\Domain\ReferencePriceCalculator;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;

/**
 * Daily pruning. Per product it keeps:
 * - every record newer than the retention cutoff;
 * - the last record before the cutoff (the price in force at the cutoff);
 * - everything needed for a reduction that is still running (its period may start before the cutoff).
 */
final class Retention {

	public const HOOK  = 'pnscripts_omnibus_prune';
	public const BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository $repository Storage.
	 * @param Settings          $settings   Settings.
	 * @param Clock             $clock      Time.
	 */
	public function __construct(
		private readonly HistoryRepository $repository,
		private readonly Settings $settings,
		private readonly Clock $clock
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Prune one batch; queue the next one when needed.
	 *
	 * @param int|string $after_product Continue after this product id.
	 */
	public function run( $after_product = 0 ): void {
		$result = $this->prune( (int) $after_product, self::BATCH );
		if ( null !== $result['next'] ) {
			Queue::async( self::HOOK, array( $result['next'] ) );
		}
	}

	/**
	 * Prune a batch of products.
	 *
	 * @param int $after_product Continue after this product id.
	 * @param int $limit         Batch size.
	 * @return array{deleted: int, next: int|null}
	 */
	public function prune( int $after_product, int $limit ): array {
		$now        = $this->clock->now();
		$cutoff     = $now - $this->settings->int( 'retention_days' ) * DAY_IN_SECONDS;
		$days       = $this->settings->policy()->period_days;
		$calculator = new ReferencePriceCalculator( wp_timezone() );
		$products   = $this->repository->prunable_products( $cutoff, $after_product, $limit );
		$deleted    = 0;

		foreach ( $products as $product_id ) {
			$records = $this->repository->for_product( $product_id );
			$keep    = $cutoff;

			$timeline = PriceTimeline::from_records( $records );
			$anchor   = $calculator->current_anchor( $timeline, $now );
			if ( null !== $anchor ) {
				$keep = min( $keep, $calculator->window_start( $anchor, $days ) );
			}

			// Queried rather than taken from $records: those are capped to the newest rows.
			$boundary = $this->repository->latest_at_or_before( $product_id, $keep );
			if ( null !== $boundary ) {
				$deleted += $this->repository->delete_before( $product_id, $boundary );
			}
		}

		return array(
			'deleted' => $deleted,
			'next'    => count( $products ) === $limit ? (int) end( $products ) : null,
		);
	}
}
