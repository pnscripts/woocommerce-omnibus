<?php
/**
 * Runs imports in the background.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Import;

use Pnscripts\Omnibus\Jobs\Queue;
use Pnscripts\Omnibus\Storage\HistoryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Imports only extend history backwards: for each product, entries at or after the first price this plugin
 * recorded itself are skipped, so our own capture always wins. Re-running an import is safe (duplicates are skipped).
 */
final class ImportRunner {

	public const HOOK          = 'pnscripts_omnibus_import';
	public const STATUS_OPTION = 'pnscripts_omnibus_import_status';
	public const BATCH         = 200;

	/**
	 * Constructor.
	 *
	 * @param Importers         $importers  Readers.
	 * @param HistoryRepository $repository Storage.
	 */
	public function __construct(
		private readonly Importers $importers,
		private readonly HistoryRepository $repository
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 3 );
	}

	/**
	 * Start importing a group in the background.
	 *
	 * @param string $group Group id.
	 */
	public static function start( string $group ): void {
		if ( ! isset( Importers::GROUPS[ $group ] ) ) {
			return;
		}
		self::update_status(
			$group,
			array(
				'started'  => time(),
				'finished' => 0,
				'imported' => 0,
				'skipped'  => 0,
			)
		);
		Queue::async( self::HOOK, array( $group, 0, 0 ) );
	}

	/**
	 * Background step.
	 *
	 * @param string     $group  Group id.
	 * @param int|string $reader Index of the reader inside the group.
	 * @param int|string $after  Cursor.
	 */
	public function run_batch( $group, $reader = 0, $after = 0 ): void {
		$step = $this->step( (string) $group, (int) $reader, (int) $after, self::BATCH );

		$status             = self::status( (string) $group );
		$status['imported'] = (int) ( $status['imported'] ?? 0 ) + $step['imported'];
		$status['skipped']  = (int) ( $status['skipped'] ?? 0 ) + $step['skipped'];
		if ( null === $step['next'] ) {
			$status['finished'] = time();
		}
		self::update_status( (string) $group, $status );

		if ( null !== $step['next'] ) {
			Queue::async( self::HOOK, array( (string) $group, $step['next'][0], $step['next'][1] ) );
		}
	}

	/**
	 * Import one batch.
	 *
	 * @param string $group  Group id.
	 * @param int    $reader Reader index.
	 * @param int    $after  Cursor.
	 * @param int    $limit  Batch size.
	 * @return array{imported: int, skipped: int, next: array{0: int, 1: int}|null}
	 */
	public function step( string $group, int $reader, int $after, int $limit ): array {
		$readers = Importers::GROUPS[ $group ] ?? array();
		if ( ! isset( $readers[ $reader ] ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'next'     => null,
			);
		}

		$source   = Importers::source( $group );
		$batch    = $this->importers->batch( $readers[ $reader ], $source, $after, $limit );
		$imported = 0;
		$skipped  = 0;
		$earliest = array();
		$currency = (string) get_option( 'woocommerce_currency', '' );

		foreach ( $batch['items'] as $item ) {
			$product_id = $item['product_id'];
			$record     = $item['record'];
			$post_type  = get_post_type( $product_id );
			if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
				++$skipped;
				continue;
			}
			if ( ! array_key_exists( $product_id, $earliest ) ) {
				$earliest[ $product_id ] = $this->repository->earliest_own( $product_id );
			}
			$own = $earliest[ $product_id ];
			if ( ( null !== $own && $record->changed_at >= $own ) || $this->repository->has_record_at( $product_id, $record->changed_at, $source ) ) {
				++$skipped;
				continue;
			}
			$parent = 'product_variation' === $post_type ? (int) wp_get_post_parent_id( $product_id ) : 0;
			if ( $this->repository->insert( $product_id, $parent, $record, $currency ) > 0 ) {
				++$imported;
			}
		}

		$next = null;
		if ( null !== $batch['next'] ) {
			$next = array( $reader, $batch['next'] );
		} elseif ( isset( $readers[ $reader + 1 ] ) ) {
			$next = array( $reader + 1, 0 );
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'next'     => $next,
		);
	}

	/**
	 * Status of a group.
	 *
	 * @param string $group Group id.
	 * @return array<string, int>
	 */
	public static function status( string $group ): array {
		$all = get_option( self::STATUS_OPTION, array() );
		return is_array( $all ) && isset( $all[ $group ] ) && is_array( $all[ $group ] ) ? $all[ $group ] : array();
	}

	/**
	 * Store the status of a group.
	 *
	 * @param string             $group  Group id.
	 * @param array<string, int> $status Status.
	 */
	private static function update_status( string $group, array $status ): void {
		$all           = get_option( self::STATUS_OPTION, array() );
		$all           = is_array( $all ) ? $all : array();
		$all[ $group ] = $status;
		update_option( self::STATUS_OPTION, $all, false );
	}
}
