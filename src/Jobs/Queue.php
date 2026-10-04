<?php
/**
 * Thin wrapper around Action Scheduler (bundled with WooCommerce).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Jobs;

/**
 * Enqueues background actions in the plugin's group.
 */
final class Queue {

	public const GROUP = 'pnscripts-omnibus';

	/**
	 * Whether Action Scheduler is ready.
	 */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Run an action in the background as soon as possible.
	 *
	 * @param string            $hook Hook.
	 * @param array<int, mixed> $args Arguments.
	 */
	public static function async( string $hook, array $args = array() ): void {
		if ( self::available() ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		}
	}

	/**
	 * Schedule a daily recurring action once.
	 *
	 * @param string $hook Hook.
	 */
	public static function daily( string $hook ): void {
		if ( self::available() && false === as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Number of pending actions of a hook.
	 *
	 * @param string $hook Hook.
	 */
	public static function pending( string $hook ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			),
			'ids'
		);
		return count( $ids );
	}

	/**
	 * Remove all scheduled actions of the plugin.
	 */
	public static function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
